<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    |
    | "oss"  — self-hosted single-user core: every core ability is granted
    |          via Gate::before and data isolation stays with HasUserScope.
    | "saas" — spatie roles/permissions, teams, billing and the admin panel
    |          take over authorization.
    |
    */

    'edition' => env('QASA_EDITION', 'oss'),

    /*
    |--------------------------------------------------------------------------
    | Edition-dependent validation rules
    |--------------------------------------------------------------------------
    |
    | price_list_items is a Pricing (premium) table, so an exists: rule against
    | it cannot ship in the OSS core — it would fail on a missing table rather
    | than reject a bad value. PricingServiceProvider tightens this.
    |
    */

    'rules' => [
        'price_list_item_id' => ['nullable', 'uuid'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP tools contributed by premium modules
    |--------------------------------------------------------------------------
    |
    | QasaServer always exposes the core tools; a tool that needs a premium
    | module is appended here by that module's service provider, so deleting
    | the module for the OSS build takes its tool with it.
    |
    */

    'mcp' => [
        'tools' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global AI kill switch
    |--------------------------------------------------------------------------
    |
    | Extends the invoicing.inbox.extraction.driver=regex idea to every AI
    | path in the app, not just inbox OCR — AiAssistantService (phase 3 Part
    | C: dashboard summaries, reminder drafts, low-confidence categorization)
    | checks this first, and FieldExtractorFactory checks it alongside its
    | own driver toggle. One env flag turns off every outbound LLM call
    | account-wide, regardless of plan/feature/BYOK.
    |
    */

    'ai' => [
        'disabled' => (bool) env('QASA_AI_DISABLED', false),
    ],

    'features' => [

        // Public registration endpoint; the OSS edition creates users via
        // the `qasa:user` artisan command instead.
        'registration' => (bool) env('QASA_REGISTRATION', false),

        // SMS verification of the account owner's phone number. Core, not
        // saas.*, on purpose: scripts/build-oss.sh deletes config/saas.php,
        // so a core class reading a saas.* key would resolve to null in the
        // generated OSS tree and switch the feature off with no way back —
        // and EditionBoundaryTest cannot see it, because it checks class
        // references, not config strings.
        //
        // Off by default so a deployment without an SMS provider configured
        // behaves exactly as it did before this existed. The SaaS admin can
        // flip it at runtime — PlatformSettingsRegistry allowlists this key.
        'phone_verification' => (bool) env('QASA_PHONE_VERIFICATION', false),

    ],

    'waitlist' => [

        // How long an invitation off the beta waitlist stays usable. Short
        // enough that a leaked link goes stale, long enough to survive
        // somebody's holiday.
        'invitation_days' => (int) env('QASA_WAITLIST_INVITATION_DAYS', 14),

    ],

    /*
    |--------------------------------------------------------------------------
    | Phone verification
    |--------------------------------------------------------------------------
    |
    | One-time codes sent by SMS. The provider itself is bound behind
    | Auth\Domain\Contracts\PhoneVerificationProviderInterface — the core
    | edition binds a null implementation that never sends, so these knobs
    | only matter once a real provider is registered.
    |
    | max_attempts is per code, not per account: a wrong guess burns one of
    | them and the code dies at zero, which is what keeps a 6-digit secret
    | (a million possibilities, but only ~10 minutes of life) out of reach of
    | online guessing. The rate limiters in AppServiceProvider cover the
    | other half — cost, and flooding one number with messages.
    |
    */

    'phone_verification' => [
        'code_ttl_minutes' => (int) env('QASA_PHONE_CODE_TTL_MINUTES', 10),
        'max_attempts' => (int) env('QASA_PHONE_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('QASA_PHONE_RESEND_COOLDOWN', 60),

        // How long a verified number must stand before it can be swapped for
        // a different one. Changing is a legitimate thing to want — new
        // operator, lost handset, a company number left behind — so this is
        // days rather than the "never" a stricter reading would suggest.
        //
        // It exists for cost, not for abuse: cycling numbers earns nothing
        // now that the trial is locked per tenant (users.trial_used_at), but
        // each attempt is still a paid SMS, and the per-number rate limit
        // resets the moment a different number is typed. 0 switches it off.
        'change_cooldown_days' => (int) env('QASA_PHONE_CHANGE_COOLDOWN_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule timezone
    |--------------------------------------------------------------------------
    |
    | Timezone for scheduled jobs (recurring invoice generation). Explicit so
    | that deploying to a UTC host doesn't silently shift the run times the
    | SK/CZ users expect.
    |
    */

    'schedule_timezone' => env('QASA_SCHEDULE_TIMEZONE', 'Europe/Bratislava'),

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Locales the API translates user-facing messages into. Resolved per
    | request by App\Modules\Shared\Presentation\Middleware\SetLocale from
    | the authenticated user's `locale` column, falling back to the
    | Accept-Language header and then config('app.locale').
    |
    */

    'locales' => [
        'available' => ['en', 'sk', 'cs'],
    ],

    /*
    |--------------------------------------------------------------------------
    | VIES grace window
    |--------------------------------------------------------------------------
    |
    | Days a client's last successful VIES check (clients.vat_verified_at)
    | remains trusted when VIES itself is unreachable at issuance time. Only
    | covers a down VIES service — a number VIES actively rejects always
    | blocks issuance, grace window or not.
    |
    */

    'vies_grace_days' => (int) env('QASA_VIES_GRACE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Require a verified sender before mailing third parties
    |--------------------------------------------------------------------------
    |
    | On by default: an account whose owner never proved the address it
    | registered with may not have this deployment e-mail invoices, payment
    | reminders, quotes or team invitations on its behalf. Enforced in
    | Shared\Support\VerifiedSenderGuard, which the scheduler and the
    | automation listener go through too — not only in route middleware.
    |
    | The escape hatch is always open (resend verification, click the link),
    | so this cannot strand an account the way a hard lockout would. It is a
    | flag rather than a constant for one operational case: if outbound mail
    | itself breaks, verification e-mails stop arriving and every account
    | looks unverified at once. Turning this off restores sending while that
    | is fixed.
    |
    */

    'require_verified_sender' => (bool) env('QASA_REQUIRE_VERIFIED_SENDER', true),

    /*
    |--------------------------------------------------------------------------
    | flok_mobile deep link scheme
    |--------------------------------------------------------------------------
    |
    | Matches app.json's "scheme" in the mobile repo. Unlike the Google OAuth
    | redirect_uri (Auth\GoogleAuthController), this is not restricted by an
    | external provider to http(s) — an emailed link opening the app directly
    | is exactly what a custom scheme is for, so PasswordResetController uses
    | it as-is, no https bridge route needed.
    |
    */

    'mobile_app_scheme' => env('MOBILE_APP_SCHEME', 'flok://'),

    /*
    |--------------------------------------------------------------------------
    | Operational metrics textfile
    |--------------------------------------------------------------------------
    |
    | Directory qasa:metrics:export writes flok.prom into, and the monitoring
    | agent reads *.prom out of (docker/alloy/config.alloy mounts this same
    | path read-only). Under storage/ rather than /var/lib/node_exporter so
    | that the writer needs no privilege it does not already have, and so a
    | deployment without the observability stack simply writes a file nobody
    | reads.
    |
    | Nothing tenant-specific is ever written here — see the command's
    | docblock. The file leaves the machine as metrics, so it must stay
    | operational counts only.
    |
    */

    'metrics' => [
        'textfile_dir' => env('QASA_METRICS_DIR', storage_path('app/metrics')),
    ],

];
