<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Guards against SSRF via webhook endpoint URLs: only https is allowed
 * (http tolerated in local dev only), and the resolved IP(s) must not fall
 * in a private/reserved range — checked after DNS resolution so a domain
 * can't simply present a public-looking hostname while pointing at an
 * internal address.
 */
final class WebhookUrlGuard
{
    public static function isSafe(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'http') {
            if ((string) config('app.env') !== 'local') {
                return false;
            }
        } elseif ($scheme !== 'https') {
            return false;
        }

        $host = $parts['host'];

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (self::isPrivateOrReserved($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function isPrivateOrReserved(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
