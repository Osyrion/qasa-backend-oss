<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Sentry\EventScrubber;

return [

    /*
    |--------------------------------------------------------------------------
    | DSN — the master switch
    |--------------------------------------------------------------------------
    |
    | Without a DSN the SDK builds a null transport and every capture is a
    | no-op, so the package ships enabled everywhere and stays silent until
    | production sets this. That is also why the OSS core keeps the dependency
    | instead of stripping it: a self-hosted install that never sets a DSN
    | pays nothing for its presence.
    |
    */

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Environment and release
    |--------------------------------------------------------------------------
    |
    | Empty environment falls back to APP_ENV, which is what we want — staging
    | and production separate themselves. SENTRY_RELEASE is expected to be the
    | deployed git SHA; there is no deploy pipeline setting it yet (see
    | docs/plans/SENTRY_OBSERVABILITY_PLAN.md), so until there is, events are
    | simply not tied to a release.
    |
    */

    'environment' => env('SENTRY_ENVIRONMENT'),

    'release' => env('SENTRY_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Personal data
    |--------------------------------------------------------------------------
    |
    | Deliberately a literal, not an env flag: send_default_pii attaches the
    | request body, cookies, the client IP and the authenticated user's email
    | to every event, and Sentry is a subprocessor listed in
    | docs/legal/SUBPROCESSORS.md on the strength of this staying off. What we
    | do want — user id, account owner id, request id — is attached explicitly
    | by Shared\Presentation\Middleware\{Authenticate,RequestId}, which is a
    | choice per field rather than a switch that opens everything at once.
    |
    */

    'send_default_pii' => false,

    /*
    |--------------------------------------------------------------------------
    | Scrubbing
    |--------------------------------------------------------------------------
    |
    | An array callable rather than a closure on purpose: config:cache writes
    | the config out with var_export and dies on closures, so a closure here
    | would break the production build step and nothing else. EventScrubber is
    | the single place that decides what may leave the process — see its
    | docblock for why frame arguments are the part that actually matters.
    |
    */

    'before_send' => [EventScrubber::class, 'handle'],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Errors are sampled at 100% — they are rare and each one is the point of
    | the exercise. Performance tracing is off (0.0) rather than merely
    | unconfigured: it is billed per transaction and cannot be tuned sensibly
    | before there is production traffic to measure. Turn it on later with a
    | small rate (0.05–0.1) via env, no code change needed.
    |
    | Profiling additionally needs ext-excimer, which docker/php does not
    | build, so it stays at 0.0 until that changes.
    |
    */

    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'profiles_sample_rate' => 0.0,

    /*
    |--------------------------------------------------------------------------
    | Logs and metrics
    |--------------------------------------------------------------------------
    |
    | Both off. Log forwarding would ship the whole application log — including
    | the `security` channel, which exists precisely because it holds things
    | (login attempts against unknown addresses) that should not travel — and
    | metrics are a separate product we are not buying. Errors only.
    |
    */

    'enable_logs' => false,

    'enable_metrics' => false,

    /*
    |--------------------------------------------------------------------------
    | Transactions never worth a trace
    |--------------------------------------------------------------------------
    |
    | Health checks run on a timer forever. /up is Laravel's own; /up/deep is
    | ours (Shared\Presentation\Controllers\DeepHealthController) and would
    | otherwise be the single most sampled route in the system.
    |
    */

    'ignore_transactions' => [
        '/up',
        '/up/deep',
    ],

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | Query bindings stay off in both breadcrumbs and spans: the SQL text is
    | placeholders and safe, the bindings are the actual client names, IBANs
    | and token hashes. The rest is on — breadcrumbs are what turn "an
    | exception happened" into "an exception happened after these four queries
    | and that cache miss".
    |
    | Every key is spelled out because mergeConfigFrom is a shallow merge: a
    | partial array here replaces the package's whole array and silently
    | disables everything it does not mention.
    |
    */

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing detail
    |--------------------------------------------------------------------------
    |
    | Inert while traces_sample_rate is 0.0, and spelled out for the same
    | shallow-merge reason — so that turning tracing on later is one env var
    | and not a debugging session about why the spans are empty.
    |
    */

    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'views' => true,
        'http_client_requests' => true,
        'cache' => true,
        'redis_commands' => false,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],

];
