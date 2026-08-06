<?php

declare(strict_types=1);

/**
 * `ENGINEERING_GUARDRAILS_PLAN.md` part A2: every translation key that
 * exists in one locale under lang/ must exist in every other locale, and
 * vice versa. Until now this was only checked by the i18n-check skill
 * (.claude/skills/i18n-check/scripts/check.php) — a skill invoked by
 * request, not something CI ever sees. This test runs the same comparison
 * as a build gate, so a module file that grows a new __() key in en/ without
 * its sk/cs counterpart fails the suite instead of shipping a fallback
 * string (or a missing-translation notice) to real users.
 *
 * Pure file/array comparison — no app boot needed, hence no uses() here,
 * same as ModuleBoundariesTest and NoResidencyLiteralsInInvoicingTest.
 */

/**
 * Flatten a nested translation array into dot-notated keys, the same way
 * __() addresses a nested value ("module.section.key").
 *
 * @param  array<array-key, mixed>  $values
 * @return list<string>
 */
function flattenLocaleKeys(array $values, string $prefix = ''): array
{
    $keys = [];

    foreach ($values as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            array_push($keys, ...flattenLocaleKeys($value, $full));
        } else {
            $keys[] = $full;
        }
    }

    return $keys;
}

/**
 * @return list<string>
 */
function availableLocaleDirectories(): array
{
    $langDir = dirname(__DIR__, 2).'/lang';

    $entries = scandir($langDir);

    if ($entries === false) {
        return [];
    }

    return array_values(array_filter(
        $entries,
        fn (string $entry): bool => $entry[0] !== '.' && is_dir($langDir.'/'.$entry),
    ));
}

/**
 * @return list<string> module file basenames present in at least one locale
 */
function localeModuleFiles(): array
{
    $langDir = dirname(__DIR__, 2).'/lang';
    $files = [];

    foreach (availableLocaleDirectories() as $locale) {
        foreach (glob($langDir.'/'.$locale.'/*.php') ?: [] as $path) {
            $files[basename($path)] = true;
        }
    }

    return array_keys($files);
}

/**
 * @return list<string>
 */
function localeKeysFor(string $locale, string $file): array
{
    $path = dirname(__DIR__, 2)."/lang/{$locale}/{$file}";

    if (! is_file($path)) {
        return [];
    }

    /** @var mixed $data */
    $data = require $path;

    return is_array($data) ? flattenLocaleKeys($data) : [];
}

it('declares config(qasa.locales.available) as a subset of the locale directories that actually exist under lang/', function (): void {
    /** @var array<string, mixed> $qasaConfig */
    $qasaConfig = require dirname(__DIR__, 2).'/config/qasa.php';
    $configured = $qasaConfig['locales']['available'] ?? null;

    expect($configured)->toBeArray();

    $missingDirs = array_values(array_diff($configured, availableLocaleDirectories()));

    expect($missingDirs)->toBe(
        [],
        'config(qasa.locales.available) lists a locale with no lang/ directory: '.implode(', ', $missingDirs),
    );
});

it('has every lang/<locale>/<module>.php file present in every other locale', function (): void {
    $locales = availableLocaleDirectories();

    expect(count($locales))->toBeGreaterThanOrEqual(2);

    $missingFiles = [];

    foreach (localeModuleFiles() as $file) {
        foreach ($locales as $locale) {
            if (! is_file(dirname(__DIR__, 2)."/lang/{$locale}/{$file}")) {
                $missingFiles[] = "{$locale}/{$file}";
            }
        }
    }

    expect($missingFiles)->toBe([], 'These locale files are missing entirely: '.implode(', ', $missingFiles));
});

it('has every translation key in parity across all locales', function (): void {
    $locales = availableLocaleDirectories();
    $report = [];

    foreach (localeModuleFiles() as $file) {
        $keysPerLocale = [];

        foreach ($locales as $locale) {
            $keysPerLocale[$locale] = localeKeysFor($locale, $file);
        }

        $union = array_values(array_unique(array_merge(...array_values($keysPerLocale))));

        foreach ($locales as $locale) {
            $missing = array_values(array_diff($union, $keysPerLocale[$locale]));

            if ($missing !== []) {
                $report[] = "{$locale}/{$file} missing: ".implode(', ', $missing);
            }
        }
    }

    expect($report)->toBe([], "Translation keys out of sync:\n".implode("\n", $report));
});
