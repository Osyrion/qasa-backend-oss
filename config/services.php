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

    // The domain the per-account inbox addresses live on
    // (`{token}@in.<domain>`). Neutral rather than under 'postmark' because it
    // is a product fact the settings screen shows a customer, not a provider
    // credential — the ESP behind it can change without the address changing.
    'email_inbox' => [
        'domain' => env('MAIL_INBOUND_DOMAIN', env('POSTMARK_INBOUND_DOMAIN')),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),

        // N1 (e-mail-in) — inbound webhook. Postmark has no HMAC signature
        // option for inbound; Basic Auth embedded in the webhook URL
        // registered with Postmark (https://user:pass@host/...) is its own
        // recommended protection, enforced by RequireBasicAuthWebhook.
        'inbound_username' => env('POSTMARK_INBOUND_USERNAME'),
        'inbound_password' => env('POSTMARK_INBOUND_PASSWORD'),

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

    // Cloudflare Turnstile — captcha for public, unauthenticated endpoints
    // (currently the waitlist). Off by default: 'enabled' false means
    // TurnstileVerifier never calls out and always passes, so local/CI never
    // need a secret key configured. Flip on once a site key + secret exist
    // for the domain in the Cloudflare dashboard.
    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    // Twilio Programmable Messaging — delivers the SMS one-time codes.
    //
    // The Messaging API, deliberately not Verify: Verify generates and
    // checks codes of its own, which would make Twilio the source of truth
    // for something phone_verification_codes already owns, and would not fit
    // PhoneVerificationProviderInterface's sendCode($phone, $code) shape at
    // all. Plain SMS is also what every other gateway can do, so swapping
    // Twilio for a local SK/CZ one stays a binding change.
    //
    // Reached over REST rather than the SDK: composer.json is shared with
    // the generated OSS core, and a dependency only the SaaS edition uses
    // would ship into the AGPL build unused.
    //
    // The feature itself is switched by qasa.features.phone_verification;
    // these are only credentials. Missing credentials surface the same way
    // an outage does — "verification unavailable" — never a 500.
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'base_url' => env('TWILIO_BASE_URL', 'https://api.twilio.com/2010-04-01'),
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
        // Redirect URI used for the mobile app's in-app-browser OAuth flow.
        //
        // It is an https URL on *this* backend, not the app's `flok://` scheme,
        // because Google only accepts http(s) redirect URIs on a Web OAuth
        // client — a custom scheme is rejected at the console. The route it
        // points at (auth.google.callback.mobile) is a two-line bridge that
        // 302s Google's answer on to `mobile_app_redirect` below, which the
        // in-app browser does accept. Must be registered as an additional
        // authorized redirect URI on the same Google OAuth client — an
        // external Google Cloud Console step, not something this repo can do.
        'mobile_redirect' => env(
            'GOOGLE_MOBILE_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/auth/google/callback/mobile'
        ),
        // Where that bridge sends the browser: flok_mobile's custom scheme,
        // the return URL expo-web-browser's openAuthSessionAsync waits for.
        'mobile_app_redirect' => env('GOOGLE_MOBILE_APP_REDIRECT_URI', 'flok://auth/google/callback'),
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
