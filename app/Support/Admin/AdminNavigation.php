<?php

namespace App\Support\Admin;

/** Presentation only: destinations are existing pages or existing Operations sections. */
final class AdminNavigation
{
    public static function groups(): array
    {
        if (! AdminUiAccess::can('admin.read')) {
            return [];
        }

        $page = static fn (string $key, string $route, string $icon, array $query = []) => compact('key', 'route', 'icon', 'query');
        $operation = static fn (string $key, string $section, string $icon) => $page($key, 'admin.operations', $icon, ['section' => $section]);

        return [
            'overview' => [$page('dashboard', 'admin.home', 'ri-dashboard-line')],
            'customers' => [
                $page('customers', 'admin.customers.list', 'ri-group-line'),
                $page('register', 'admin.customers.register', 'ri-user-settings-line'),
                $page('ranking', 'admin.customers.ranking', 'ri-bar-chart-line'),
                $page('usage', 'admin.customers.usage', 'ri-pie-chart-line'),
                $page('suspended', 'admin.customers.suspended', 'ri-user-unfollow-line'),
            ],
            'services' => [
                $page('tools', 'admin.services.tools', 'ri-apps-2-line'),
                $page('voices', 'admin.services.voices', 'ri-mic-line'),
                $page('entitlements', 'admin.services.entitlements', 'ri-shield-check-line'),
                $page('pricing', 'admin.services.pricing', 'ri-price-tag-3-line'),
                $page('plans', 'admin.payments.plans', 'ri-stack-line'),
                $page('landing_tools', 'admin.landing.tools', 'ri-layout-line'),
            ],
            'billing' => [
                $operation('payments', 'payments', 'ri-bank-card-line'),
                $operation('subscriptions', 'subscriptions', 'ri-calendar-check-line'),
                $operation('orders', 'orders', 'ri-shopping-bag-line'),
                $operation('review', 'review', 'ri-search-eye-line'),
                $page('addons', 'admin.payments.addons', 'ri-add-circle-line'),
            ],
            'developer' => [
                $operation('processing', 'jobs', 'ri-terminal-box-line'),
                $operation('api', 'api', 'ri-code-s-slash-line'),
                $operation('mcp', 'mcp', 'ri-links-line'),
                $operation('keys', 'keys', 'ri-key-2-line'),
                $operation('reservations', 'reservations', 'ri-lock-line'),
            ],
            'storage' => [
                $operation('files', 'files', 'ri-folder-line'),
                $operation('storage_subscriptions', 'storage_subscriptions', 'ri-hard-drive-2-line'),
                $page('storage_plans', 'admin.payments.storage', 'ri-database-2-line'),
            ],
            'system' => [
                $operation('audit', 'audit', 'ri-file-search-line'),
                $operation('ledger', 'ledger', 'ri-book-2-line'),
                $page('translations', 'admin.landing.translations', 'ri-translate-2'),
                $page('contact', 'admin.landing.contact', 'ri-contacts-line'),
                $page('meta', 'admin.landing.meta', 'ri-global-line'),
                $page('phone_countries', 'admin.customers.phone-countries', 'ri-phone-line'),
                $page('coupons', 'admin.payments.coupons', 'ri-coupon-line'),
                $page('methods', 'admin.payments.methods', 'ri-bank-line'),
                $page('currencies', 'admin.payments.currencies', 'ri-exchange-line'),
            ],
        ];
    }

    public static function active(array $item): bool
    {
        return request()->routeIs($item['route'])
            && ($item['route'] !== 'admin.operations' || request()->query('section', 'jobs') === $item['query']['section']);
    }

    public static function context(): array
    {
        if (request()->routeIs('admin.customers.detail')) {
            return ['group' => 'customers', 'key' => 'customer_detail'];
        }
        foreach (self::groups() as $group => $items) {
            foreach ($items as $item) {
                if (self::active($item)) {
                    return ['group' => $group, 'key' => $item['key']];
                }
            }
        }

        return ['group' => 'system', 'key' => 'operations'];
    }
}
