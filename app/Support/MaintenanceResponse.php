<?php

namespace App\Support;

/** No framework dependencies: also required before Composer by public/index.php. */
final class MaintenanceResponse
{
    public const JSON = '{"error":{"code":"service_unavailable","message":"MetKurd is temporarily unavailable for maintenance."}}';

    public static function wantsJson(array $server): bool
    {
        $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $accept = strtolower($server['HTTP_ACCEPT'] ?? '');

        return preg_match('#^/(?:api|mcp)(?:/|$)#', $path) === 1
            || $path === '/oauth/token' || str_starts_with($path, '/.well-known/oauth-')
            || str_contains($accept, '/json') || str_contains($accept, '+json')
            || (strtolower($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
                && ($accept === '' || $accept === '*/*'));
    }

    public static function prerendered(string $file): void
    {
        // Laravel alone decides exclusions, secret/cookie bypass, redirects and status.
        // Its exit flushes this buffer; a bypass returns and removes it before boot.
        ob_start(static function (string $html): string {
            if (http_response_code() !== 503) {
                return $html;
            }

            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Content-Type-Options: nosniff');
            if (self::wantsJson($_SERVER)) {
                header('Content-Type: application/json; charset=UTF-8');

                return self::JSON;
            }
            header('Content-Type: text/html; charset=UTF-8');

            return $html;
        });
        require $file;
        ob_end_clean();
    }
}
