<?php

declare(strict_types=1);

/**
 * A migration's timestamp has to be unique across every path Laravel loads.
 *
 * Migrations are gathered from `database/migrations` plus each module's own
 * directory, and then sorted by *file name*. Two files sharing a
 * YYYY_MM_DD_HHMMSS prefix are therefore ordered by whatever comes after it —
 * so `add_…` runs before `enable_…` because "a" sorts before "e", and nothing
 * about that is a decision anyone made.
 *
 * Today every collision is between migrations that touch different tables, so
 * the accidental order happens to be fine. The first one that is not — a
 * module migration that has to follow a core one raised on the same day —
 * breaks only on a fresh `migrate`, passes on every incremental deploy, and
 * gives no clue why.
 *
 * The existing collisions cannot be renamed: the `migrations` table records
 * the file name, so a rename re-runs the migration everywhere it is already
 * applied. They are pinned below instead, and the rule holds for everything
 * new.
 */

/**
 * Timestamp prefixes that already collided when this rule was introduced
 * (2026-09-03), with the files sharing each.
 *
 * This list may only shrink, and only by a migration being deleted — never by
 * one being renamed.
 *
 * @return array<string, int>
 */
function knownMigrationTimestampCollisions(): array
{
    return [
        '2026_07_09_000001' => 2,
        '2026_07_11_000001' => 2,
        '2026_07_11_000002' => 2,
        '2026_07_14_000002' => 2,
        '2026_07_14_000003' => 2,
        '2026_07_21_000002' => 2,
        '2026_07_21_000003' => 2,
        '2026_07_21_000004' => 2,
        '2026_07_30_000001' => 2,
        '2026_07_30_000002' => 2,
        '2026_07_31_000001' => 3,
        '2026_07_31_000002' => 2,
        '2026_08_03_000001' => 2,
        '2026_08_03_000002' => 2,
        '2026_08_03_000003' => 2,
        '2026_08_03_000004' => 2,
        '2026_08_05_000001' => 2,
        '2026_08_05_100010' => 2,
        '2026_08_06_000001' => 3,
    ];
}

/**
 * @return array<string, list<string>>
 */
function migrationsByTimestamp(): array
{
    $root = dirname(__DIR__, 2);

    $paths = array_merge(
        glob($root.'/database/migrations/*.php') ?: [],
        glob($root.'/app/Modules/*/Database/Migrations/*.php') ?: [],
    );

    $byTimestamp = [];

    foreach ($paths as $path) {
        $name = basename($path);

        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $matches) !== 1) {
            continue;
        }

        $byTimestamp[$matches[1]][] = str_replace($root.'/', '', $path);
    }

    ksort($byTimestamp);

    return $byTimestamp;
}

it('gives every new migration a timestamp of its own', function (): void {
    $known = knownMigrationTimestampCollisions();

    $new = [];

    foreach (migrationsByTimestamp() as $timestamp => $files) {
        if (count($files) < 2) {
            continue;
        }

        // An entry only excuses the number of files it was written for, so a
        // *third* migration landing on an already-collided timestamp is still
        // caught.
        if (($known[$timestamp] ?? 0) >= count($files)) {
            continue;
        }

        $new[] = $timestamp.":\n  ".implode("\n  ", $files);
    }

    expect($new)->toBe([], implode("\n", [
        'These migrations share a timestamp, so their order is decided by the',
        'rest of the file name rather than by anyone. Pick another second:',
        ...$new,
    ]));
});

it('does not excuse a collision that is no longer there', function (): void {
    $actual = migrationsByTimestamp();

    $stale = [];

    foreach (knownMigrationTimestampCollisions() as $timestamp => $count) {
        if (count($actual[$timestamp] ?? []) < $count) {
            $stale[] = $timestamp;
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'These timestamps no longer carry the collisions they are pinned for.',
        'Lower the count, or drop the entry:',
        ...$stale,
    ]));
})->skip(
    // Almost every collision is between a core migration and a premium one,
    // and the generated core has had the premium half deleted — so seventeen
    // of these entries legitimately have nothing to match there. The list is
    // maintained against the full tree; the forward-looking check above still
    // runs in both editions, which is the half that stops a new collision.
    fn (): bool => ! is_dir(dirname(__DIR__, 2).'/app/Modules/Saas'),
    'the pinned collisions are a property of the full tree, not the generated core',
);
