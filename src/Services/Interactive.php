<?php
declare(strict_types=1);
namespace CaliChat\Services;

/**
 * Decides when a reply should carry WhatsApp tap-to-reply buttons or a product list instead of plain text.
 * Buttons: when the assistant has just shown a preview and is asking the customer to confirm (Yes / No).
 * List: when a product search found 2 to 10 products. The customer's tap arrives as an ordinary message (the button or row title).
 * Only for Meta WhatsApp channels; everything else, and any failure, falls back to plain text.
 */
class Interactive {
    /** @return array{buttons?:array,list?:array} */
    public static function forReply(array $tenant, ?array $channel, array $r, string $text): array {
        if (!$channel || $channel['type'] !== 'whatsapp' || (int)($tenant['cfg']['interactive'] ?? 1) === 0) return [];
        if ($text === '' || mb_strlen($text) > 1000) return [];
        $lang = preg_match('/\p{Sinhala}/u', $text) ? 'si' : (preg_match('/\p{Tamil}/u', $text) ? 'ta' : 'en');
        $b = null;
        if (!empty($r['order_summary']) && mb_strlen($text) <= 1024) { // the server-built order form: Confirm / Cancel under the summary, with the business logo and name
            $b = Brand::get((int)$tenant['id']);
            return ['buttons' => OrderFlow::buttons($r['order_summary']['lang']), 'header' => $b['has_logo'] ? $b['logo_url'] : null, 'footer' => $b['name']];
        }
        if (!empty($r['awaiting_confirmation'])) {
            $t = ['en' => ['Yes, confirm', 'No, change it'], 'si' => ['ඔව්, තහවුරු කරන්න', 'නැහැ, වෙනස් කරන්න'], 'ta' => ['ஆம், உறுதி', 'இல்லை, மாற்று']][$lang];
            return ['buttons' => [['id' => 'confirm_yes', 'title' => $t[0]], ['id' => 'confirm_no', 'title' => $t[1]]]];
        }
        $p = $r['products'] ?? [];
        if (count($p) >= 2 && count($p) <= 10 && empty($r['cards_sent'])) {
            $rows = array_map(fn($x) => ['id' => (string)$x['sku'], 'title' => (string)$x['name'], 'description' => 'Rs. ' . number_format((float)$x['price'])], $p);
            return ['list' => ['button' => ['en' => 'View products', 'si' => 'නිෂ්පාදන', 'ta' => 'பொருட்கள்'][$lang], 'rows' => $rows]];
        }
        return [];
    }

    /** Deliver text with the rich part when there is one; fall back to plain text if Meta refuses it. */
    public static function deliver(array $channel, string $to, string $text, array $rich): array {
        if (!empty($rich['buttons'])) {
            $d = Meta::sendButtons($channel, $to, $text, $rich['buttons'], $rich['header'] ?? null, $rich['footer'] ?? null); if ($d['ok']) return $d;
            if (!empty($rich['header'])) { $d = Meta::sendButtons($channel, $to, $text, $rich['buttons'], null, $rich['footer'] ?? null); if ($d['ok']) return $d; } // Meta could not load the logo: keep the buttons
        }
        elseif (!empty($rich['list'])) { $d = Meta::sendList($channel, $to, $text, $rich['list']['button'], $rich['list']['rows']); if ($d['ok']) return $d; }
        return Meta::send($channel, $to, $text);
    }
}
