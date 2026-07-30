<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Guards against SSRF via webhook endpoint URLs: only https is allowed
 * (http tolerated in local dev only), and the resolved IP(s) must not fall
 * in a private/reserved range — checked after DNS resolution so a domain
 * can't simply present a public-looking hostname while pointing at an
 * internal address.
 *
 * Validation (isSafe) happens when an endpoint is saved, but DNS can be
 * re-pointed to an internal address afterwards (DNS rebinding / TOCTOU).
 * resolveSafeIp() therefore re-resolves at send time and hands back the one
 * validated IP the HTTP client must pin the connection to, so the address
 * that was vetted is the exact address curl dials.
 */
final class WebhookUrlGuard
{
    public static function isSafe(string $url): bool
    {
        return self::resolveSafeIp($url) !== null;
    }

    /**
     * Re-resolve the URL's host and return a single validated IP to pin the
     * outbound connection to, or null when the URL is unsafe. Callers must
     * connect to exactly this IP (e.g. curl CURLOPT_RESOLVE) so the vetted
     * address cannot be swapped out between check and dial.
     */
    public static function resolveSafeIp(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'http') {
            if ((string) config('app.env') !== 'local') {
                return null;
            }
        } elseif ($scheme !== 'https') {
            return null;
        }

        $ips = self::resolveIps($parts['host']);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (self::isPrivateOrReserved($ip)) {
                // A single internal record poisons the whole host — refuse
                // rather than pin to a public sibling and leave the internal
                // record reachable on a later resolution.
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * @return list<string> Every A/AAAA record for the host (or the literal
     *                      IP), so an internal record can't hide behind a
     *                      public sibling.
     */
    private static function resolveIps(string $host): array
    {
        // Strip IPv6 literal brackets, e.g. [::1].
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }

        $ipv4 = gethostbynamel($host) ?: [];

        $ipv6 = [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ipv6[] = $record['ipv6'];
            }
        }

        return array_values(array_unique([...$ipv4, ...$ipv6]));
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
