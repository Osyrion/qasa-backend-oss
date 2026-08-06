<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),

        // N1 (e-mail-in) — inbound webhook. Postmark has no HMAC signature
        // option for inbound; Basic Auth embedded in the webhook URL
        // registered with Postmark (https://user:pass@host/...) is its own
        // recommended protection, enforced by RequireBasicAuthWebhook.
        'inbound_username' => env('POSTMARK_INBOUND_USERNAME'),
        'inbound_password' => env('POSTMARK_INBOUND_PASSWORD'),
        'inbound_domain' => env('POSTMARK_INBOUND_DOMAIN'),

        // Outbound (delivery) webhook — Delivery/Bounce/Open/SpamComplaint.
        // A separate pair from the inbound one above so either can be
        // rotated without taking the other down.
        'outbound_username' => env('POSTMARK_OUTBOUND_USERNAME'),
        'outbound_password' => env('POSTMARK_OUTBOUND_PASSWORD'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cnb' => [
        'base_url' => env('CNB_API_URL', 'https://api.cnb.cz'),
    ],

    'clockify' => [
        'base_url' => env('CLOCKIFY_API_URL', 'https://api.clockify.me/api/v1'),
    ],

    'ares' => [
        'base_url' => env('ARES_API_URL', 'https://ares.gov.cz'),
        // Short TTL for a failed lookup (timeout/5xx/malformed response) so
        // a registry outage doesn't force every request through the full
        // timeout+retry again — a successful lookup keeps the long TTL below.
        'failure_ttl' => env('ARES_API_FAILURE_TTL', 300),
    ],

    'rpo' => [
        'base_url' => env('RPO_API_URL', 'https://api.statistics.sk'),
    ],

    'vies' => [
        'base_url' => env('VIES_API_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
        // Short TTL for a failed lookup (timeout/5xx/malformed response) so
        // a registry outage doesn't force every request through the full
        // timeout+retry again — a successful lookup keeps the long TTL below.
        'failure_ttl' => env('VIES_API_FAILURE_TTL', 300),
    ],

    'crpdph' => [
        'base_url' => env('CRPDPH_API_URL', 'https://adisrws.mfcr.cz'),
    ],

    'fio' => [
        'base_url' => env('FIO_API_URL', 'https://fioapi.fio.cz'),
    ],

    // UNVERIFIED against a live sandbox account — see "Otvorené otázky" in
    // docs/plans/COMPETITOR_MIGRATION_IMPORTS_PLAN.md. SuperfakturaApiClient
    // implements the publicly documented REST shape (SFAPI auth header,
    // /clients/index.json, /invoices/index.json) but must be confirmed
    // against a real account — in particular whether invoice payments are
    // included in the export — before this driver is enabled in production.
    'superfaktura' => [
        'base_url' => env('SUPERFAKTURA_API_URL', 'https://moja.superfaktura.sk'),
    ],

    'anthropic' => [
        // Platform key for metered AI invoice extraction (ai_extraction
        // feature). A BYOK credential saved on the account (ai_credentials
        // table) always takes priority over this — see
        // ByokCredentialResolver / FieldExtractorFactory.
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'stripe' => [
        'prices' => [
            'standard' => [
                'month' => [
                    'CZK' => env('STRIPE_PRICE_STANDARD_MONTHLY_CZK'),
                    'EUR' => env('STRIPE_PRICE_STANDARD_MONTHLY_EUR'),
                    'USD' => env('STRIPE_PRICE_STANDARD_MONTHLY_USD'),
                ],
                'year' => [
                    'CZK' => env('STRIPE_PRICE_STANDARD_YEARLY_CZK'),
                    'EUR' => env('STRIPE_PRICE_STANDARD_YEARLY_EUR'),
                    'USD' => env('STRIPE_PRICE_STANDARD_YEARLY_USD'),
                ],
            ],
            'pro' => [
                'month' => [
                    'CZK' => env('STRIPE_PRICE_PRO_MONTHLY_CZK'),
                    'EUR' => env('STRIPE_PRICE_PRO_MONTHLY_EUR'),
                    'USD' => env('STRIPE_PRICE_PRO_MONTHLY_USD'),
                ],
                'year' => [
                    'CZK' => env('STRIPE_PRICE_PRO_YEARLY_CZK'),
                    'EUR' => env('STRIPE_PRICE_PRO_YEARLY_EUR'),
                    'USD' => env('STRIPE_PRICE_PRO_YEARLY_USD'),
                ],
            ],
        ],

        // Stripe Connect (Standard, OAuth) — lets a tenant accept online
        // card payments on their own Stripe account. Platform secret key is
        // Cashier's own config('cashier.secret'); Connect charges use that
        // same platform key with a Stripe-Account header, never a tenant
        // secret (see docs/plans/STRIPE_INVOICE_PAYMENTS_PLAN.md).
        'connect' => [
            'client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
            'redirect_uri' => env('STRIPE_CONNECT_REDIRECT_URI'),
            'webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
            'frontend_return_url' => env('STRIPE_CONNECT_FE_RETURN_URL'),
        ],
    ],

];
