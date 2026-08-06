<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `ENGINEERING_GUARDRAILS_PLAN.md` part A3: response messages and
 * domain-exception messages must never be inline string literals — CLAUDE.md's
 * localization rule, converted from prose (only checked by the `i18n-check`
 * skill on request) into something CI actually runs.
 *
 * Found and fixed three real violations while building this: Orders'
 * CreateOrderAction/UpdateOrderAction threw DomainException with hardcoded
 * Slovak sentences instead of __('orders.*') keys. Also removed
 * DomainException::resourceNotFound()/unauthorized()/forbidden()/withCode()/
 * businessRule()/configuration() — dead factory methods (zero callers
 * anywhere) whose hardcoded English defaults were exactly the kind of latent
 * trap this test exists to catch before someone starts relying on them.
 */

/**
 * Extracts the raw text of every balanced-parentheses argument list
 * following $needle — e.g. "DomainException::because(...)" — tracking
 * single/double-quoted strings (backslash-escape aware) so a literal '('
 * or ')' inside a message string doesn't miscount the depth.
 *
 * @return list<string>
 */
function extractCallBodies(string $content, string $needle): array
{
    $bodies = [];
    $offset = 0;
    $length = strlen($content);

    while (($pos = strpos($content, $needle, $offset)) !== false) {
        $start = $pos + strlen($needle);

        if (($content[$start] ?? '') !== '(') {
            $offset = $start;

            continue;
        }

        $depth = 1;
        $i = $start + 1;
        $quote = null;

        while ($i < $length && $depth > 0) {
            $char = $content[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            $i++;
        }

        $bodies[] = substr($content, $start + 1, max(0, $i - $start - 2));
        $offset = $i;
    }

    return $bodies;
}

/**
 * Route-relative file paths exempt from the response-message literal check,
 * with the reason a hardcoded string there is not user-facing product text.
 *
 * @return array<string, string>
 */
function hardcodedResponseMessageAllowlist(): array
{
    return [
        // Premium-module files are contributed by the SaaS test overlay
        // (tests/Pest.edition.php) — naming them here would trip the
        // stale-entry guard in the generated core, where they do not exist.
        ...(function_exists('premiumHardcodedResponseMessageAllowlist') ? premiumHardcodedResponseMessageAllowlist() : []),

        // The API docs landing page is a developer tool, not translated
        // product UI — OpenAPI documentation itself is conventionally English.
        'app/Modules/Shared/Presentation/Controllers/SwaggerController.php' => 'API documentation landing page, not translated product UI',
    ];
}

function phpFilesUnder(string $dir): Finder
{
    return (new Finder)->files()->in($dir)->name('*.php');
}

it('never passes a hardcoded string literal to DomainException::because/validation/invalidState', function (): void {
    $needles = ['DomainException::because', 'DomainException::validation', 'DomainException::invalidState'];
    $violations = [];

    foreach (phpFilesUnder(dirname(__DIR__, 2).'/app') as $file) {
        $content = $file->getContents();

        if (! str_contains($content, 'DomainException::')) {
            continue;
        }

        $relativePath = 'app/'.ltrim(str_replace(dirname(__DIR__, 2).'/app', '', (string) $file->getRealPath()), '/');

        foreach ($needles as $needle) {
            foreach (extractCallBodies($content, $needle) as $body) {
                if (! str_contains($body, '__(')) {
                    $violations[] = sprintf('%s: %s(%s) has no __() call', $relativePath, $needle, mb_substr(trim($body), 0, 60));
                }
            }
        }
    }

    expect($violations)->toBe(
        [],
        "These DomainException messages are hardcoded literals instead of __() translation calls:\n".implode("\n", $violations),
    );
});

it('never passes a hardcoded string literal to a custom validation Rule\'s $fail()', function (): void {
    $violations = [];

    foreach (phpFilesUnder(dirname(__DIR__, 2).'/app') as $file) {
        $content = $file->getContents();

        if (! str_contains($content, 'implements ValidationRule')) {
            continue;
        }

        $relativePath = 'app/'.ltrim(str_replace(dirname(__DIR__, 2).'/app', '', (string) $file->getRealPath()), '/');

        foreach (extractCallBodies($content, '$fail') as $body) {
            if (! str_contains($body, '__(')) {
                $violations[] = sprintf('%s: $fail(%s) has no __() call', $relativePath, mb_substr(trim($body), 0, 60));
            }
        }
    }

    expect($violations)->toBe(
        [],
        "These validation Rule failures are hardcoded literals instead of __() translation calls:\n".implode("\n", $violations),
    );
});

it('never assigns a hardcoded string literal to a JSON response\'s message key, unless allowlisted', function (): void {
    $allowlist = hardcodedResponseMessageAllowlist();
    $violations = [];

    foreach (phpFilesUnder(dirname(__DIR__, 2).'/app') as $file) {
        $content = $file->getContents();

        if (! str_contains($content, "'message'")) {
            continue;
        }

        $relativePath = 'app/'.ltrim(str_replace(dirname(__DIR__, 2).'/app', '', (string) $file->getRealPath()), '/');

        if (array_key_exists($relativePath, $allowlist)) {
            continue;
        }

        if (preg_match_all("/'message'\\s*=>\\s*(['\"])/", $content, $matches) > 0) {
            $violations[] = "{$relativePath}: 'message' => a literal string, not __() or a variable";
        }
    }

    expect($violations)->toBe(
        [],
        "These response messages are hardcoded literals — use __(), or justify in hardcodedResponseMessageAllowlist():\n".implode("\n", $violations),
    );
});

it('does not allowlist a file that no longer hardcodes a response message', function (): void {
    $stale = [];

    foreach (hardcodedResponseMessageAllowlist() as $relativePath => $reason) {
        $fullPath = dirname(__DIR__, 2).'/'.$relativePath;

        if (! file_exists($fullPath)) {
            $stale[] = "{$relativePath} (file no longer exists)";

            continue;
        }

        if (preg_match_all("/'message'\\s*=>\\s*(['\"])/", (string) file_get_contents($fullPath)) === 0) {
            $stale[] = "{$relativePath} (no longer hardcodes a message literal)";
        }
    }

    expect($stale)->toBe([], 'Stale hardcodedResponseMessageAllowlist entries — remove them: '.implode(', ', $stale));
});
