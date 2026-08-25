<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable e-mail domains
    |--------------------------------------------------------------------------
    |
    | Addresses on these domains are refused at registration. The point is not
    | to be exhaustive — thousands of throwaway domains exist and new ones
    | appear daily, so treating this as a complete defence would be wrong. It
    | raises the cost of the cheapest abuse path (open a tab, grab an inbox,
    | claim another trial) and nothing more; the phone verification gating the
    | trial is what actually carries that weight.
    |
    | Deliberately a static list rather than a lookup service: the whole point
    | of this feature is that registration must not depend on a third party
    | being reachable, and a blocklist that fails open on a timeout would be
    | worse than none at all.
    |
    | Extend per deployment with DISPOSABLE_EMAIL_DOMAINS (comma-separated)
    | rather than editing this file — the env entries are merged in, not
    | replacing the list below.
    |
    | @var list<string>
    */

    'disposable_email_domains' => array_values(array_unique(array_filter(array_map(
        static fn (string $domain): string => mb_strtolower(trim($domain)),
        [
            ...[
                '0-mail.com',
                '10minutemail.com',
                '20minutemail.com',
                'discard.email',
                'dispostable.com',
                'emailondeck.com',
                'fakeinbox.com',
                'getairmail.com',
                'getnada.com',
                'guerrillamail.com',
                'guerrillamail.net',
                'guerrillamail.org',
                'inboxbear.com',
                'mailcatch.com',
                'maildrop.cc',
                'mailinator.com',
                'mailnesia.com',
                'mintemail.com',
                'moakt.com',
                'mohmal.com',
                'mytemp.email',
                'sharklasers.com',
                'spam4.me',
                'temp-mail.org',
                'tempail.com',
                'tempinbox.com',
                'tempmail.net',
                'tempmailo.com',
                'throwawaymail.com',
                'trashmail.com',
                'trashmail.de',
                'yopmail.com',
                'yopmail.net',
            ],
            ...explode(',', (string) env('DISPOSABLE_EMAIL_DOMAINS', '')),
        ],
    )))),

];
