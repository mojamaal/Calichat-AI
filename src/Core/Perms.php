<?php
declare(strict_types=1);
namespace CaliChat\Core;

/**
 * Per-capability access for team members. Owners (and the platform admin) can do everything. Other members get a default set,
 * which an owner can change person by person on the Team page (stored as a JSON list in users.permissions; NULL means "the default").
 */
class Perms {
    /** capability => [label, group, on by default for a team member] */
    public const CAPS = [
        'inbox'          => ['See the Inbox and reply to chats', 'Chats', true],
        'inbox_all'      => ['See every chat (off: only chats assigned to them, plus unassigned ones)', 'Chats', true],
        'show_phone'     => ['See full phone numbers (off: partly hidden)', 'Chats', true],
        'contacts'       => ['Contacts and marketing consent', 'Customers', true],
        'orders'         => ['See orders and the store', 'Sales', true],
        'orders_edit'    => ['Change orders: pack, ship, cancel, mark paid, create', 'Sales', true],
        'leads'          => ['See leads', 'Sales', true],
        'leads_edit'     => ['Change leads: stage, owner, notes, add', 'Sales', true],
        'appointments'      => ['See appointments', 'Sales', true],
        'appointments_edit' => ['Book, complete and cancel appointments', 'Sales', true],
        'store_edit'     => ['Edit products, delivery areas, store rules, appointment services', 'Setup', false],
        'knowledge'      => ['See the Knowledge base', 'Setup', true],
        'knowledge_edit' => ['Edit the Knowledge base', 'Setup', false],
        'templates'      => ['See WhatsApp templates', 'Setup', true],
        'templates_edit' => ['Create and submit WhatsApp templates', 'Setup', false],
        'automations'    => ['See follow-ups', 'Setup', true],
        'automations_edit' => ['Change follow-ups and SMS settings', 'Setup', false],
        'campaigns'      => ['See campaigns', 'Setup', true],
        'campaigns_edit' => ['Create and launch campaigns', 'Setup', false],
        'monitoring'     => ['See usage and monitoring', 'Oversight', true],
        'reports'        => ['See business reports', 'Oversight', true],
    ];
    private const PAGES = ['inbox' => 'inbox', 'contacts' => 'contacts', 'orders' => 'orders', 'store' => 'orders', 'catalogue' => 'orders', 'leads' => 'leads', 'appointments' => 'appointments', 'knowledge' => 'knowledge',
        'templates' => 'templates', 'automations' => 'automations', 'campaigns' => 'campaigns', 'monitoring' => 'monitoring', 'reports' => 'reports', 'quality' => 'monitoring'];

    public static function all(): array { return array_keys(self::CAPS); }
    public static function defaults(): array { return array_keys(array_filter(self::CAPS, fn($c) => $c[2])); }
    public static function pageCap(string $page): ?string { return self::PAGES[$page] ?? null; }

    /** @return string[] */
    public static function forUser(array $u): array {
        if (in_array($u['role'] ?? '', ['owner', 'super_admin'], true)) return self::all();
        $p = isset($u['permissions']) && $u['permissions'] !== null && $u['permissions'] !== '' ? json_decode((string)$u['permissions'], true) : null;
        return is_array($p) ? array_values(array_intersect($p, self::all())) : self::defaults();
    }
    public static function can(array $u, string $cap): bool { return in_array($cap, self::forUser($u), true); }

    /** 94771234567 -> 94771****67 */
    public static function mask(string $phone): string {
        $d = preg_replace('/\D/', '', $phone);
        return strlen($d) >= 9 ? substr($d, 0, 5) . str_repeat('*', strlen($d) - 7) . substr($d, -2) : $phone;
    }
}
