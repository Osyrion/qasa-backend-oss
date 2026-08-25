<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Account purge grace period
    |--------------------------------------------------------------------------
    |
    | Deleting an account soft-deletes it; qasa:accounts:purge anonymises it
    | this many days later. The gap is deliberate — it leaves room to undo a
    | mistake and for in-flight payments to settle before the identity behind
    | them becomes unreadable.
    |
    */

    'account_purge_grace_days' => (int) env('QASA_ACCOUNT_PURGE_GRACE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Trial-claim retention
    |--------------------------------------------------------------------------
    |
    | How long trial_phone_claims remembers that a phone number has already
    | had its free trial. The row holds a peppered HMAC and a date — never
    | the number, never an account — because it has to survive the account
    | purge to be worth anything: otherwise deleting the account and waiting
    | out account_purge_grace_days above earns a second trial.
    |
    | Finite rather than indefinite: fraud prevention is a legitimate
    | interest (Recital 47), but "forever" is hard to square with Art.
    | 5(1)(e) whatever the hashing. Two years is long enough that replaying
    | the trial is not worth anyone's time, and short enough to defend. The
    | trade-off is explicit — after this window the same number can claim
    | again.
    |
    | Pruned by qasa:subscriptions:trial-reminders on its daily run.
    |
    */

    'trial_claim_retention_months' => (int) env('QASA_TRIAL_CLAIM_RETENTION_MONTHS', 24),

    /*
    |--------------------------------------------------------------------------
    | Request metadata retention
    |--------------------------------------------------------------------------
    |
    | An IP address is personal data. qasa:privacy:purge-request-metadata
    | deletes expired sessions outright and clears ip_address/user_agent off
    | older personal_access_tokens — the token itself is a credential and is
    | never revoked for the sake of retention, or a retention rule would log
    | an integration out.
    |
    | The admin audit keeps its metadata longer: it is the record of who
    | reached into someone else's account, and that is worth more than a
    | tenant's own login history. Only the metadata is cleared either way;
    | the audit entry always survives.
    |
    */

    'request_metadata_retention_days' => (int) env('QASA_REQUEST_METADATA_RETENTION_DAYS', 90),

    'admin_audit_ip_retention_days' => (int) env('QASA_ADMIN_AUDIT_IP_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Document retention
    |--------------------------------------------------------------------------
    |
    | How long issued accounting documents must be kept, per tax residency.
    | These are numbers from a law that gets amended, so they live here rather
    | than in code — and the defaults are a conservative reading, not a legal
    | opinion. Nothing purges documents today: the value exists so that
    | docs/legal/PROCESSING_ACTIVITIES.md and any later archival purge read
    | the same figure instead of each carrying its own.
    |
    | Keyed by users.country, the only two values users_country_check allows.
    |
    */

    'document_retention_years' => [
        'SK' => (int) env('QASA_DOCUMENT_RETENTION_YEARS_SK', 10),
        'CZ' => (int) env('QASA_DOCUMENT_RETENTION_YEARS_CZ', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Terms & privacy policy version
    |--------------------------------------------------------------------------
    |
    | Semver of the terms-of-use/privacy-policy documents a new account must
    | accept (RegisterUserData::$accepted_terms) and an existing one is asked
    | to re-accept when this moves (UserResource's terms_acceptance_required).
    | Covers docs/legal/TERMS_OF_SERVICE.*.md and docs/legal/PRIVACY_POLICY.*.md
    | as one unit — bump it whenever either changes materially.
    |
    */

    'terms_version' => env('QASA_TERMS_VERSION', '1.0'),

];
