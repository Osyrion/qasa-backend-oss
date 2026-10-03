<?php

declare(strict_types=1);

/**
 * Offset pagination is only defined under a total order, and almost none of
 * the columns these lists sort by are unique: issued_at and expenses.date are
 * `date`, and every created_at in the schema is timestamp(0). A page of rows
 * written in one batch — a recurring-invoice run, an import — ties outright,
 * and Postgres does not promise an order for tied rows across two LIMIT/OFFSET
 * queries. The reader sees one row twice and never sees another.
 *
 * Shared\Support\Pagination::of() appends the primary key so that cannot
 * happen. These are the tests that keep it the only way in: one that it
 * actually does it, one that nothing bypasses it.
 */
it('routes every paginated list through Pagination', function (): void {
    $root = dirname(__DIR__, 2).'/app';

    $offenders = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
        '/\.php$/',
    );

    foreach ($files as $path => $_) {
        $path = (string) $path;

        // Pagination is where the one permitted call lives.
        if (str_ends_with($path, 'Shared/Support/Pagination.php')) {
            continue;
        }

        foreach (file($path) ?: [] as $number => $line) {
            if (! str_contains($line, '->paginate(')) {
                continue;
            }

            // A controller handing off to an injected repository or service is
            // not itself paginating — the repository behind it is, and that is
            // where this rule catches it. Anything else building a query and
            // paginating it is the case this exists to stop.
            if (preg_match('/\$this->[A-Za-z_][A-Za-z0-9_]*->paginate\(/', $line) === 1) {
                continue;
            }

            $offenders[] = str_replace($root.'/', '', $path).':'.($number + 1);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These paginate a query without a guaranteed total order.',
        'Use Pagination::of($query, $request) instead:',
        ...$offenders,
    ]));
});
