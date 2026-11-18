<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Tenant;

/** Setup-guide steps for a new workspace, computed from what actually exists (no separate progress to keep in sync). */
class Onboarding {
    /** @return array<int,array{key:string,title:string,why:string,done:bool,optional:bool,link:string,cta:string}> */
    public static function steps(int $tid): array {
        $t = Tenant::get($tid); $cfg = $t['cfg'] ?? [];
        $has = fn(string $sql, array $p = []) => (int)Db::val($sql, array_merge([$tid], $p)) > 0;
        return [
            ['key' => 'profile', 'title' => 'Tell us about your business', 'why' => 'Your timezone and hours drive dashboards and tell customers when your team is available.',
                'done' => !empty($cfg['profile_done']), 'optional' => false, 'link' => 'app/onboarding#profile', 'cta' => 'Set up profile'],
            ['key' => 'agent', 'title' => 'Teach your AI agent', 'why' => 'The instructions are its briefing: what you sell, your rules, when to call a human.',
                'done' => $has("SELECT COUNT(*) FROM agents WHERE tenant_id=? AND is_active=1 AND CHAR_LENGTH(COALESCE(instructions,'')) >= 60"), 'optional' => false, 'link' => 'app/onboarding#agent', 'cta' => 'Write instructions'],
            ['key' => 'store_details', 'title' => 'Your store details', 'why' => 'Your logo, business name, delivery fee and payment methods. They appear on product cards, the catalogue, the order summary and invoices.',
                'done' => !empty($cfg['brand_done']), 'optional' => false, 'link' => 'app/onboarding#store_details', 'cta' => 'Add store details'],
            ['key' => 'knowledge', 'title' => 'Add your policies and FAQs', 'why' => 'Returns, delivery, payment, opening hours: answers that are the same for every customer.',
                'done' => $has("SELECT COUNT(*) FROM knowledge_docs WHERE tenant_id=?"), 'optional' => false, 'link' => 'app/knowledge', 'cta' => 'Add knowledge'],
            ['key' => 'systems', 'title' => 'Add your products, or connect your ERP', 'why' => 'Lets the AI look up products and orders, and create or cancel them. No system yet? Use the built-in Store: add products and delivery areas and connect it with one click. Skip if you only need FAQs.',
                'done' => $has("SELECT COUNT(*) FROM tools WHERE tenant_id=? AND is_active=1") || !empty($cfg['skip_systems']), 'optional' => true, 'link' => 'app/store', 'cta' => 'Set up the store'],
            ['key' => 'test', 'title' => 'Chat with your agent', 'why' => 'Try real questions in the Playground, in every language you serve, before customers do.',
                'done' => $has("SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND is_test=1 AND last_message_at IS NOT NULL"), 'optional' => false, 'link' => 'app/playground', 'cta' => 'Open Playground'],
            ['key' => 'channels', 'title' => 'Connect WhatsApp, Messenger or Instagram', 'why' => 'This is where your customers are. Sign in with Facebook and pick your accounts.',
                'done' => $has("SELECT COUNT(*) FROM channels WHERE tenant_id=? AND (verified_at IS NOT NULL OR last_webhook_at IS NOT NULL)"), 'optional' => false, 'link' => 'app/channels', 'cta' => 'Connect channels'],
            ['key' => 'live', 'title' => 'Receive your first real customer message', 'why' => 'Send a message from your own phone to your connected number and watch it arrive in the Inbox.',
                'done' => $has("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.tenant_id=? AND m.sender='customer' AND c.is_test=0"), 'optional' => false, 'link' => 'app/inbox', 'cta' => 'Open Inbox'],
        ];
    }
    public static function progress(int $tid): array {
        $s = self::steps($tid); $done = count(array_filter($s, fn($x) => $x['done']));
        return ['done' => $done, 'total' => count($s), 'complete' => $done === count($s)];
    }
}
