<?php

declare(strict_types=1);

use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Modules\Auth\Application\DTOs\UpdateProfileData;
use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `OpenApiRouteSyncTest` proves every route *has* an annotation. This one
 * goes a layer deeper on the endpoints that validate against a DTO's
 * `rules()`: a field the endpoint accepts must also be documented, and a
 * field the docs promise must actually be accepted.
 *
 * This is not pedantry about docs. The frontend's API client is *generated*
 * from this spec (`orval`), so an accepted-but-undocumented field does not
 * merely read as missing — it cannot be typed, and therefore cannot be sent
 * at all without hand-writing around the generated client. That is exactly
 * how `ai_extraction_enabled` (the AI Act art. 50 opt-out our own
 * transparency notice promises, see docs/legal/AI_ACT.md) turned out to be
 * unreachable from the UI while the backend had supported it for months;
 * `overdue_digest_enabled`, `quote_number_mask/start`, `vat_status`,
 * `vat_filing_frequency`, `tax_filing_reminder_enabled` and
 * `current_password` were in the same state and are documented now.
 *
 * Regenerates the spec through Artisan rather than reading the committed
 * artifact — same reasoning as OpenApiRouteSyncTest: a stale
 * storage/api-docs/api-docs.json must not be able to hide the drift.
 *
 * Needs the app booted (Artisan, storage_path()), hence uses(TestCase::class)
 * instead of Pest.php's default.
 */
uses(TestCase::class);

/**
 * Endpoints whose request body is validated against a DTO's static
 * `rules()`, mapped to the rule set they must match. Keyed by
 * "METHOD /path" as it appears in the generated spec.
 *
 * Only endpoints using the `rules()` + `$request->validate()` idiom belong
 * here; the `SomeData::validateAndCreate()` DTOs carry their constraints as
 * attributes on constructor properties, which is a different comparison and
 * a different test if it is ever worth writing.
 *
 * @return array<string, callable(): array<string, mixed>>
 */
function documentedRequestBodies(): array
{
    return [
        // rules() only reads $user->id (for the e-mail uniqueness rule), so
        // an unsaved model is enough and this test stays DB-free.
        'PUT /api/v1/auth/profile' => static fn (): array => UpdateProfileData::rules(new User),

        // Registration is the other endpoint the *mobile* client generates
        // against, and it was missing turnstile_token: RegisterUserAction
        // verifies the captcha on every call, but no generated client could
        // send the token, so switching services.turnstile.enabled on would
        // have refused every registration from the web and the app alike.
        'POST /api/v1/auth/register' => static fn (): array => RegisterUserData::rules(),
    ];
}

/**
 * Documented properties that deliberately have no validation rule of their
 * own — the endpoint reads them some other way.
 *
 * @return array<string, array<string, string>> endpoint => property => reason
 */
function undocumentedBodyPropertyAllowlist(): array
{
    return [];
}

/**
 * @return array<string, list<string>> "METHOD /path" => documented property names
 */
function specRequestBodyProperties(): array
{
    Artisan::call('l5-swagger:generate');

    /** @var array{paths: array<string, array<string, mixed>>} $spec */
    $spec = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

    $bodies = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! is_array($operation)) {
                continue;
            }

            /** @var array<string, mixed>|null $properties */
            $properties = $operation['requestBody']['content']['application/json']['schema']['properties'] ?? null;

            if ($properties === null) {
                continue;
            }

            $bodies[strtoupper($method).' '.$path] = array_keys($properties);
        }
    }

    return $bodies;
}

it('documents every field the endpoint validates', function (): void {
    $documented = specRequestBodyProperties();
    $missing = [];

    foreach (documentedRequestBodies() as $endpoint => $rules) {
        expect(array_key_exists($endpoint, $documented))
            ->toBeTrue("No documented JSON request body found for {$endpoint}.");

        foreach (array_keys($rules()) as $field) {
            if (! in_array($field, $documented[$endpoint], true)) {
                $missing[] = "{$endpoint}: {$field}";
            }
        }
    }

    expect($missing)->toBe(
        [],
        'These fields are validated but not in the OpenAPI request body, so the generated API '.
        'clients cannot send them: '.implode(', ', $missing),
    );
});

it('does not document a field the endpoint would ignore', function (): void {
    $documented = specRequestBodyProperties();
    $allowlist = undocumentedBodyPropertyAllowlist();
    $orphaned = [];

    foreach (documentedRequestBodies() as $endpoint => $rules) {
        $validated = array_keys($rules());

        foreach ($documented[$endpoint] ?? [] as $property) {
            if (in_array($property, $validated, true) || isset($allowlist[$endpoint][$property])) {
                continue;
            }

            $orphaned[] = "{$endpoint}: {$property}";
        }
    }

    expect($orphaned)->toBe(
        [],
        'These documented fields have no validation rule, so the endpoint silently ignores them — '.
        'remove them from the annotation, or justify in undocumentedBodyPropertyAllowlist(): '
        .implode(', ', $orphaned),
    );
});

it('does not leave a stale entry in the request-body property allowlist', function (): void {
    $documented = specRequestBodyProperties();
    $stale = [];

    foreach (undocumentedBodyPropertyAllowlist() as $endpoint => $properties) {
        foreach (array_keys($properties) as $property) {
            if (! in_array($property, $documented[$endpoint] ?? [], true)) {
                $stale[] = "{$endpoint}: {$property}";
            }
        }
    }

    expect($stale)->toBe([], 'Stale undocumentedBodyPropertyAllowlist entries: '.implode(', ', $stale));
});
