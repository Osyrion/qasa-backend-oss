<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reverse Proxy Trust
    |--------------------------------------------------------------------------
    |
    | Off by default so local/dev and any deployment not yet behind Cloudflare
    | keep today's behaviour: nginx passes REMOTE_ADDR to PHP-FPM as a FastCGI
    | param, which already is the real client IP with no header trust needed.
    |
    | Once DNS is proxied through Cloudflare (orange-cloud), nginx instead sees
    | Cloudflare's edge IP as the connecting peer. Flip this on so Laravel
    | trusts X-Forwarded-For from that edge and recovers the real visitor IP —
    | otherwise every per-IP rate limiter (waitlist, invitation-accept,
    | admin-login, ...) and every ip column in audit/session logs collapses
    | onto a handful of shared Cloudflare addresses.
    |
    */
    'proxy_enabled' => env('CLOUDFLARE_PROXY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy IP Ranges
    |--------------------------------------------------------------------------
    |
    | Cloudflare's published edge ranges (https://www.cloudflare.com/ips/,
    | fetched 2026-08-17). These change occasionally — re-fetch ips-v4/ips-v6
    | before a deployment if it has been a while. Override entirely via
    | CLOUDFLARE_TRUSTED_PROXIES (comma-separated) if that ever drifts and a
    | redeploy isn't immediately possible.
    |
    | @var list<string>
    */
    'trusted_proxies' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CLOUDFLARE_TRUSTED_PROXIES', implode(',', [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ]))),
    )),

];
