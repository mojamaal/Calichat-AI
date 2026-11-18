<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Net;

/** WhatsApp Cloud API, Messenger and Instagram DM via the Meta Graph API. */
class Meta {
    public static function graphBase(): string { return rtrim((string)env('META_GRAPH_BASE', 'https://graph.facebook.com'), '/') . '/' . env('META_GRAPH_VERSION', 'v21.0'); }
    private static function graph(): string { return self::graphBase(); }
    /** Private addresses are only reachable when a local Graph stub is configured for development/tests. */
    public static function allowPrivate(): bool { return ALLOW_PRIVATE_HOSTS && env('META_GRAPH_BASE') !== null; }

    public static function verifySignature(string $raw, ?string $header, ?string $secret): bool {
        if (!$secret) return false;
        if (!$header || strpos($header, 'sha256=') !== 0) return false;
        return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
    }

    /**
     * Normalise a webhook payload into events:
     * [{id,sender,name,phone,type,text,media_ref,mime}]  (type: text|audio|image|other)
     */
    public static function parse(array $channel, array $p): array {
        $ev = [];
        foreach ($p['entry'] ?? [] as $entry) {
            if ($channel['type'] === 'whatsapp') {
                foreach ($entry['changes'] ?? [] as $ch) {
                    $v = $ch['value'] ?? [];
                    if (($v['metadata']['phone_number_id'] ?? null) && $channel['external_id'] && (string)$v['metadata']['phone_number_id'] !== (string)$channel['external_id']) continue;
                    $names = [];
                    foreach ($v['contacts'] ?? [] as $c) $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? null;
                    foreach ($v['messages'] ?? [] as $m) {
                        $mt = $m['type'] ?? 'text'; $type = 'other'; $text = ''; $ref = null; $mime = null; $replyId = '';
                        switch ($mt) {
                            case 'text': $type = 'text'; $text = $m['text']['body'] ?? ''; break;
                            case 'button': $type = 'text'; $text = $m['button']['text'] ?? ''; break;
                            case 'interactive': $type = 'text'; $text = $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''; $replyId = (string)($m['interactive']['button_reply']['id'] ?? $m['interactive']['list_reply']['id'] ?? ''); break;
                            case 'audio': $type = 'audio'; $ref = $m['audio']['id'] ?? null; $mime = $m['audio']['mime_type'] ?? 'audio/ogg'; break;
                            case 'image': $type = 'image'; $ref = $m['image']['id'] ?? null; $mime = $m['image']['mime_type'] ?? 'image/jpeg'; $text = $m['image']['caption'] ?? ''; break;
                            case 'document': case 'video': $text = ($m[$mt]['caption'] ?? '') ?: "[customer sent a $mt]"; $type = 'text'; break;
                            case 'location':
                                $type = 'text'; $l = $m['location'] ?? [];
                                $text = '[customer shared a location pin' . (!empty($l['name']) || !empty($l['address']) ? ': ' . trim((string)($l['name'] ?? '') . ' ' . (string)($l['address'] ?? '')) : '') . (isset($l['latitude'], $l['longitude']) ? ' (' . round((float)$l['latitude'], 5) . ', ' . round((float)$l['longitude'], 5) . ')' : '') . ']';
                                break;
                            default: $type = 'text'; $text = "[unsupported $mt message]";
                        }
                        $ev[] = ['id' => $m['id'] ?? rand_key(8), 'sender' => $m['from'] ?? '', 'name' => $names[$m['from'] ?? ''] ?? null, 'phone' => $m['from'] ?? null, 'type' => $type, 'text' => $text, 'media_ref' => $ref, 'mime' => $mime, 'reply_id' => $replyId !== '' ? $replyId : null, 'referral' => !empty($m['referral']) && is_array($m['referral']) ? array_intersect_key($m['referral'], array_flip(['source_url', 'source_type', 'source_id', 'headline', 'body', 'ctwa_clid'])) : null];
                    }
                }
            } else { // messenger + instagram
                foreach ($entry['messaging'] ?? [] as $m) {
                    if (!empty($m['message']['is_echo']) || empty($m['message'])) continue;
                    $msg = $m['message']; $text = $msg['text'] ?? ''; $type = 'text'; $ref = null; $mime = null;
                    $att = $msg['attachments'][0] ?? null;
                    if ($att) {
                        $at = $att['type'] ?? 'file';
                        if (in_array($at, ['audio', 'image'], true) && !empty($att['payload']['url'])) { $type = $at; $ref = $att['payload']['url']; $mime = $at === 'audio' ? 'audio/mp4' : 'image/jpeg'; }
                        elseif ($text === '') $text = "[customer sent $at]";
                    }
                    if ($text === '' && !$ref) continue;
                    $ev[] = ['id' => $msg['mid'] ?? rand_key(8), 'sender' => $m['sender']['id'] ?? '', 'name' => null, 'phone' => null, 'type' => $type, 'text' => $text, 'media_ref' => $ref, 'mime' => $mime];
                }
            }
        }
        return array_values(array_filter($ev, fn($e) => $e['sender'] !== ''));
    }


    /**
     * Shared platform webhook: map each entry to the tenant's managed channel by page / Instagram account / phone number id.
     * @return array<int,array{0:array,1:array}> list of [channel, events]
     */
    public static function route(array $payload): array {
        $out = []; $obj = (string)($payload['object'] ?? '');
        foreach ($payload['entry'] ?? [] as $entry) {
            if ($obj === 'whatsapp_business_account') {
                foreach ($entry['changes'] ?? [] as $ch) {
                    $pn = (string)($ch['value']['metadata']['phone_number_id'] ?? '');
                    $channel = $pn !== '' ? Db::one("SELECT * FROM channels WHERE type='whatsapp' AND external_id=? AND managed=1 AND status='active'", [$pn]) : null;
                    if ($channel) $out[] = [$channel, self::parse($channel, ['entry' => [['changes' => [$ch]]]])];
                }
            } elseif ($obj === 'page' || $obj === 'instagram') {
                $channel = Db::one("SELECT * FROM channels WHERE type=? AND external_id=? AND managed=1 AND status='active'", [$obj === 'page' ? 'messenger' : 'instagram', (string)($entry['id'] ?? '')]);
                if ($channel) $out[] = [$channel, self::parse($channel, ['entry' => [$entry]])];
            }
        }
        return $out;
    }
    /** Download a customer's media. WhatsApp: ref is a media id; Messenger/Instagram: ref is a signed CDN URL. @return array{bytes:string,mime:string}|null */
    public static function fetchMedia(array $channel, string $ref, ?string $mime): ?array {
        if ($channel['type'] === 'twilio_whatsapp') return Twilio::fetchMedia($channel, $ref, $mime);
        $token = Crypto::decrypt($channel['access_token']);
        if ($channel['type'] === 'whatsapp') {
            if (!$token) return null;
            $meta = Net::request('GET', self::graph() . '/' . rawurlencode($ref), ['Authorization: Bearer ' . $token], ['timeout' => 10, 'allow_private' => self::allowPrivate(), 'max_bytes' => 65536]);
            $j = json_decode($meta['body'], true);
            if ($meta['status'] !== 200 || empty($j['url'])) return null;
            $m = $j['mime_type'] ?? $mime ?? 'application/octet-stream';
            $file = Net::request('GET', $j['url'], ['Authorization: Bearer ' . $token], ['timeout' => 20, 'allow_private' => self::allowPrivate(), 'max_bytes' => 8 * 1048576]);
            return $file['status'] === 200 && $file['body'] !== '' ? ['bytes' => $file['body'], 'mime' => $m] : null;
        }
        $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
        if (parse_url($ref, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)(fbcdn\.net|fbsbx\.com|cdninstagram\.com|facebook\.com)$/', $host)) return null;
        $file = Net::request('GET', $ref, [], ['timeout' => 20, 'allow_private' => self::allowPrivate(), 'max_bytes' => 8 * 1048576]);
        return $file['status'] === 200 && $file['body'] !== '' ? ['bytes' => $file['body'], 'mime' => $mime ?: 'application/octet-stream'] : null;
    }

    public static function send(array $channel, string $to, string $text): array {
        if ($channel['type'] === 'twilio_whatsapp') return Twilio::send($channel, $to, $text);
        $token = Crypto::decrypt($channel['access_token']);
        if (!$token) return ['ok' => false, 'error' => 'Access token not set'];
        $last = ['ok' => true];
        foreach (self::chunk($text, 1500) as $c) {
            if ($channel['type'] === 'whatsapp') {
                $url = self::graph() . '/' . $channel['external_id'] . '/messages';
                $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $c]];
            } else {
                $url = self::graph() . '/' . ($channel['external_id'] ?: 'me') . '/messages';
                $body = ['recipient' => ['id' => $to], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $c]];
            }
            $last = self::post($url, $body, $token);
            if (!$last['ok']) break;
        }
        self::noteResult($channel, $last);
        return $last;
    }

    public static function sendTemplate(array $channel, string $to, string $template, string $lang, array $vars = []): array {
        if ($channel['type'] === 'twilio_whatsapp') return ['ok' => false, 'error' => 'Twilio channels send free-form messages only (within 24 hours of the customer\'s last message)'];
        $token = Crypto::decrypt($channel['access_token']);
        if (!$token || $channel['type'] !== 'whatsapp') return ['ok' => false, 'error' => 'Templates need a WhatsApp channel with a token'];
        $tpl = ['name' => $template, 'language' => ['code' => $lang]];
        if ($vars) $tpl['components'] = [['type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $vars)]];
        $r = self::post(self::graph() . '/' . $channel['external_id'] . '/messages', ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => $tpl], $token);
        self::noteResult($channel, $r);
        return $r;
    }

    /** Check that the saved ID and token are valid. @return array{ok:bool,name:?string,error:?string} */
    public static function verifyConnection(array $channel): array {
        if ($channel['type'] === 'twilio_whatsapp') return Twilio::verifyConnection($channel);
        $token = Crypto::decrypt($channel['access_token']);
        if (!$token || !$channel['external_id']) return ['ok' => false, 'name' => null, 'error' => 'Add the ID and access token first'];
        $fields = ['whatsapp' => 'display_phone_number,verified_name', 'messenger' => 'name', 'instagram' => 'username'][$channel['type']] ?? 'id';
        $r = Net::request('GET', self::graph() . '/' . rawurlencode($channel['external_id']) . '?fields=' . $fields, ['Authorization: Bearer ' . $token], ['timeout' => 10, 'allow_private' => self::allowPrivate(), 'max_bytes' => 65536]);
        $j = json_decode($r['body'], true) ?: [];
        if ($r['status'] === 200) return ['ok' => true, 'name' => $j['verified_name'] ?? $j['name'] ?? $j['username'] ?? $j['display_phone_number'] ?? $channel['external_id'], 'error' => null];
        return ['ok' => false, 'name' => null, 'error' => $r['error'] ?: ($j['error']['message'] ?? 'HTTP ' . $r['status'])];
    }

    /** Record delivery failures on the channel and raise an alert on token problems. */
    public static function noteResult(array $channel, array $r): void {
        try {
            if ($r['ok']) { if (!empty($channel['last_error'])) Db::update('channels', ['last_error' => null], 'id=?', [$channel['id']]); return; }
            Db::update('channels', ['last_error' => mb_substr((string)$r['error'], 0, 290)], 'id=?', [$channel['id']]);
            if (!empty($r['auth'])) Notifier::alert((int)$channel['tenant_id'], 'crit', 'channel_token', "Channel '{$channel['name']}' rejected its access token. Replies are not being delivered; update the token in Channels.", 'chan_token:' . $channel['id'], 12);
        } catch (\Throwable $e) {}
    }

    /** Send any WhatsApp Cloud API message body (interactive buttons/lists, images). Meta WhatsApp channels only. */
    public static function sendRaw(array $channel, string $to, array $message): array {
        if ($channel['type'] !== 'whatsapp') return ['ok' => false, 'error' => 'Rich messages need a Meta WhatsApp channel'];
        $token = Crypto::decrypt($channel['access_token']);
        if (!$token) return ['ok' => false, 'error' => 'Access token not set'];
        $r = self::post(self::graph() . '/' . $channel['external_id'] . '/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to] + $message, $token);
        self::noteResult($channel, $r);
        return $r;
    }
    /** Up to 3 tap-to-reply buttons under a message. @param array<int,array{id:string,title:string}> $buttons */
    public static function sendButtons(array $channel, string $to, string $body, array $buttons, ?string $headerImage = null, ?string $footer = null): array {
        $i = ['type' => 'button', 'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => array_map(fn($b) => ['type' => 'reply', 'reply' => ['id' => mb_substr($b['id'], 0, 256), 'title' => mb_substr($b['title'], 0, 20)]], array_slice($buttons, 0, 3))]];
        if ($headerImage) $i['header'] = ['type' => 'image', 'image' => ['link' => $headerImage]];
        if ($footer) $i['footer'] = ['text' => mb_substr($footer, 0, 60)];
        return self::sendRaw($channel, $to, ['type' => 'interactive', 'interactive' => $i]);
    }
    /** One "open this link" button under a message (optionally with a header image and footer). */
    public static function sendCtaUrl(array $channel, string $to, string $body, string $buttonText, string $url, ?string $headerImage = null, ?string $footer = null): array {
        $i = ['type' => 'cta_url', 'body' => ['text' => mb_substr($body, 0, 1024)], 'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr($buttonText, 0, 20), 'url' => $url]]];
        if ($headerImage) $i['header'] = ['type' => 'image', 'image' => ['link' => $headerImage]];
        if ($footer) $i['footer'] = ['text' => mb_substr($footer, 0, 60)];
        return self::sendRaw($channel, $to, ['type' => 'interactive', 'interactive' => $i]);
    }
    /** A "pick one" list (up to 10 rows). @param array<int,array{id:string,title:string,description?:string}> $rows */
    public static function sendList(array $channel, string $to, string $body, string $buttonText, array $rows, string $section = 'Products'): array {
        return self::sendRaw($channel, $to, ['type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['button' => mb_substr($buttonText, 0, 20), 'sections' => [['title' => mb_substr($section, 0, 24), 'rows' => array_map(fn($r) => ['id' => mb_substr($r['id'], 0, 200), 'title' => mb_substr($r['title'], 0, 24)] + (!empty($r['description']) ? ['description' => mb_substr($r['description'], 0, 72)] : []), array_slice($rows, 0, 10))]]]]]);
    }
    public static function sendImage(array $channel, string $to, string $url, string $caption = ''): array {
        return self::sendRaw($channel, $to, ['type' => 'image', 'image' => ['link' => $url] + ($caption !== '' ? ['caption' => mb_substr($caption, 0, 1000)] : [])]);
    }

    /** Generic Graph call for management endpoints (templates). @return array{ok:bool,status:int,json:array,error:?string,auth:bool} */
    public static function api(string $method, string $path, ?array $body, string $token): array {
        $h = ['Authorization: Bearer ' . $token]; $o = ['timeout' => 20, 'allow_private' => self::allowPrivate(), 'max_bytes' => 262144];
        if ($body !== null) { $h[] = 'Content-Type: application/json'; $o['body'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
        $r = Net::request($method, self::graph() . '/' . ltrim($path, '/'), $h, $o);
        $j = json_decode($r['body'], true); $j = is_array($j) ? $j : [];
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        $err = $ok ? null : ($r['error'] ?: (($j['error']['error_user_msg'] ?? '') ?: ($j['error']['message'] ?? ('HTTP ' . $r['status']))));
        return ['ok' => $ok, 'status' => (int)$r['status'], 'json' => $j, 'error' => $err, 'auth' => $r['status'] === 401 || (int)($j['error']['code'] ?? 0) === 190];
    }

    private static function post(string $url, array $body, string $token): array {
        $r = Net::request('POST', $url, ['Content-Type: application/json', 'Authorization: Bearer ' . $token], ['timeout' => 20, 'allow_private' => self::allowPrivate(), 'max_bytes' => 65536, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        if ($r['status'] >= 200 && $r['status'] < 300) return ['ok' => true];
        $j = json_decode($r['body'], true);
        $msg = $r['error'] ?: ($j['error']['message'] ?? substr($r['body'], 0, 200));
        return ['ok' => false, 'error' => "Meta HTTP {$r['status']}: $msg", 'auth' => $r['status'] === 401 || (int)($j['error']['code'] ?? 0) === 190];
    }

    private static function chunk(string $t, int $max): array {
        if (mb_strlen($t) <= $max) return [$t];
        $out = []; $cur = '';
        foreach (preg_split('/\n/u', $t) as $line) {
            if (mb_strlen($cur) + mb_strlen($line) + 1 > $max && $cur !== '') { $out[] = trim($cur); $cur = ''; }
            $cur .= $line . "\n";
        }
        if (trim($cur) !== '') $out[] = trim($cur);
        return $out;
    }
}
