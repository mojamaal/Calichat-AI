<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Log;
use CaliChat\Core\Net;
use CaliChat\Core\Tenant;

/**
 * Voice-note replies. When a customer sends a voice note and the owner has switched voice replies on, the assistant's answer is spoken
 * with Gemini text-to-speech, converted to Opus with ffmpeg, uploaded to WhatsApp and sent as a voice note. Any failure falls back to text,
 * so the customer always gets an answer. Needs ffmpeg on the server (FFMPEG_PATH, or on the PATH).
 */
class Voice {
    public const VOICES = ['Kore', 'Puck', 'Charon', 'Zephyr', 'Aoede', 'Leda', 'Fenrir', 'Orus'];
    /** @var string|false|null */
    private static $ff = null;
    /** Tests replace speech synthesis. @var null|callable(string):?string */
    public static $synth = null;

    public static function ffmpeg(): ?string {
        if (self::$ff === null) {
            $p = (string)env('FFMPEG_PATH', '');
            if ($p === '' || !is_file($p)) {
                $p = '';
                $out = []; @exec((stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where ffmpeg' : 'command -v ffmpeg') . ' 2>&1', $out, $code);
                if ($code === 0 && !empty($out[0]) && is_file(trim($out[0]))) $p = trim($out[0]);
            }
            self::$ff = $p !== '' ? $p : false;
        }
        return self::$ff ?: null;
    }

    public static function enabled(array $tenant, array $channel): bool {
        return $channel['type'] === 'whatsapp' && (int)($tenant['cfg']['voice_replies'] ?? 0) === 1 && (self::$synth !== null || self::ffmpeg() !== null);
    }

    /** What is worth speaking: short, no links, no formatting marks. */
    public static function speakable(string $text): ?string {
        $t = trim(preg_replace('/[*_~`#>]+/u', '', preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text)));
        $t = trim(preg_replace('/\s+/u', ' ', preg_replace('/^\s*[-•]\s*/m', '', $t)));
        if ($t === '' || mb_strlen($t) > 600 || preg_match('#https?://#i', $t)) return null;
        return $t;
    }

    /** @return string|null Opus-in-Ogg bytes */
    public static function synthesize(array $tenant, string $text, ?int $convId = null): ?string {
        if (self::$synth !== null) return (self::$synth)($text);
        $ff = self::ffmpeg(); if (!$ff) return null;
        $voice = in_array($tenant['cfg']['voice_name'] ?? '', self::VOICES, true) ? $tenant['cfg']['voice_name'] : 'Kore';
        $r = Gemini::generate(Tenant::geminiKey($tenant), ['contents' => [['parts' => [['text' => $text]]]], 'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]]],
            ['model' => (string)env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'), 'fallback' => false, 'tenant_id' => (int)$tenant['id'], 'conversation_id' => $convId, 'kind' => 'tts', 'deadline' => microtime(true) + 25]);
        $b64 = $r['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null; if (!$b64) return null;
        $pcm = base64_decode($b64); $tmp = sys_get_temp_dir() . '/cc_tts_' . bin2hex(random_bytes(6)); file_put_contents($tmp . '.pcm', $pcm);
        $out = []; @exec('"' . $ff . '" -v error -y -f s16le -ar 24000 -ac 1 -i ' . escapeshellarg($tmp . '.pcm') . ' -c:a libopus -b:a 32k -application voip ' . escapeshellarg($tmp . '.ogg') . ' 2>&1', $out, $code);
        $ogg = $code === 0 && is_file($tmp . '.ogg') ? (string)file_get_contents($tmp . '.ogg') : null; @unlink($tmp . '.pcm'); @unlink($tmp . '.ogg');
        if ($ogg === null || $ogg === '') { Log::warn('tts_convert_failed', ['out' => mb_substr(implode(' ', $out), 0, 200)]); return null; }
        return $ogg;
    }

    /** Upload the voice note to WhatsApp and send it. @return array{ok:bool,error:?string} */
    public static function send(array $tenant, array $channel, string $to, string $text, ?int $convId = null): array {
        try {
            $say = self::speakable($text); if ($say === null) return ['ok' => false, 'error' => 'not suitable for voice'];
            $ogg = self::synthesize($tenant, $say, $convId); if ($ogg === null) return ['ok' => false, 'error' => 'speech could not be created'];
            $token = Crypto::decrypt($channel['access_token']); if (!$token) return ['ok' => false, 'error' => 'no token'];
            $b = '----cc' . bin2hex(random_bytes(8));
            $body = "--$b\r\nContent-Disposition: form-data; name=\"messaging_product\"\r\n\r\nwhatsapp\r\n--$b\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\naudio/ogg\r\n--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"reply.ogg\"\r\nContent-Type: audio/ogg\r\n\r\n" . $ogg . "\r\n--$b--\r\n";
            $r = Net::request('POST', Meta::graphBase() . '/' . $channel['external_id'] . '/media', ['Authorization: Bearer ' . $token, 'Content-Type: multipart/form-data; boundary=' . $b], ['timeout' => 20, 'allow_private' => Meta::allowPrivate(), 'max_bytes' => 65536, 'body' => $body]);
            $id = json_decode($r['body'], true)['id'] ?? null; if ($r['status'] !== 200 || !$id) return ['ok' => false, 'error' => 'upload failed: HTTP ' . $r['status']];
            $d = Meta::sendRaw($channel, $to, ['type' => 'audio', 'audio' => ['id' => (string)$id]]);
            if ($d['ok']) Tenant::usage((int)$tenant['id'], 'media_in', 0);
            return $d;
        } catch (\Throwable $e) { Log::exception($e, 'voice_reply_failed'); return ['ok' => false, 'error' => $e->getMessage()]; }
    }
}
