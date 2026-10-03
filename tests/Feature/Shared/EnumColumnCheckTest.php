<?php

declare(strict_types=1);

use App\Modules\Shared\Support\EnumCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every enum-cast column must be constrained in the database to values its
 * enum can actually read.
 *
 * The cast is not a constraint. Eloquent reads one with `Enum::from()`, which
 * throws a \ValueError on anything it does not recognise, so a value written
 * outside the enum is a row that can no longer be *read*: a 500 on every list
 * that includes it, and unrepairable through the API, because every write path
 * hydrates the model first. Forty columns were in that state, and the ones
 * that mattered most were the externally fed ones — Peppol callbacks, imports,
 * provider webhooks — where an unexpected string is not hypothetical.
 *
 * Comparing the values and not merely the existence of a constraint is the
 * other half, and the two directions are not the same failure. A constraint
 * that admits *more* than the enum leaves the unreadable-row hole open and is
 * always wrong. One that admits *less* is safe but usually means an enum
 * gained a case and the CHECK did not follow, so legitimate writes start
 * failing — safe enough to allow deliberately, too quiet to allow silently.
 *
 * Needs a migrated database (it reads pg_constraint), so it lives under
 * Feature rather than Architecture.
 */

/**
 * Columns whose CHECK is deliberately *narrower* than the enum they cast to,
 * and why.
 *
 * A narrower constraint is safe — it can only reject values, never admit one
 * the model cannot read — but it is indistinguishable from an enum that gained
 * a case nobody propagated. Declaring it here is what tells the two apart.
 *
 * The test fails on an entry that turns out to match its enum exactly, so a
 * justification cannot outlive itself.
 *
 * @return array<string, string>
 */
function narrowerThanEnumByDesign(): array
{
    return [
        // InvoiceType covers every document the invoicing module issues, but
        // only two of them can recur: a credit note and a storno exist to undo
        // a specific earlier document, so a schedule that emits them makes no
        // sense. The column is cast to the shared enum and the database says
        // which half of it applies here.
        'recurring_invoice_templates.type' => 'a credit note or storno answers one earlier document; neither can be scheduled',
    ];
}

/**
 * @return list<array{table: string, column: string, enum: class-string<BackedEnum>}>
 */
function enumCastColumns(): array
{
    $found = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Modules/*/Domain/Models/*.php') ?: [] as $path) {
        $relative = str_replace([dirname(__DIR__, 3).'/app/Modules/', '.php'], '', $path);
        $class = 'App\\Modules\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            continue;
        }

        foreach ($model->getCasts() as $column => $cast) {
            $enum = EnumCheck::backedEnumCast($cast);

            if ($enum === null || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $found[$table.'.'.$column] = ['table' => $table, 'column' => $column, 'enum' => $enum];
        }
    }

    ksort($found);

    return array_values($found);
}

/**
 * The value lists of every single-column CHECK on $table.$column.
 *
 * Single-column only: a constraint spanning two columns is a rule about their
 * relationship (invoices' reverse_charge presence check), not a domain for
 * one of them.
 *
 * @return list<list<string>>
 */
function checkedValuesFor(string $table, string $column): array
{
    /** @var list<object{definition: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT pg_get_constraintdef(c.oid) AS definition
        FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE c.contype = 'c'
          AND c.connamespace = 'public'::regnamespace
          AND c.conrelid = ?::regclass
          AND a.attname = ?
          AND array_length(c.conkey, 1) = 1
    SQL, [$table, $column]);

    return array_map(static function (object $row): array {
        preg_match_all("/'([^']*)'::/", $row->definition, $matches);

        $values = $matches[1];
        sort($values);

        return $values;
    }, $rows);
}

it('constrains every enum-cast column to values its enum can read', function (): void {
    $narrower = narrowerThanEnumByDesign();

    $unconstrained = [];
    $unreadable = [];
    $undeclaredNarrowing = [];
    $staleAllowlist = [];

    foreach (enumCastColumns() as ['table' => $table, 'column' => $column, 'enum' => $enum]) {
        $key = $table.'.'.$column;

        $cases = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
        sort($cases);

        $checks = checkedValuesFor($table, $column);

        if ($checks === []) {
            $unconstrained[] = $key.' ('.class_basename($enum).')';

            continue;
        }

        // Several constraints may cover one column; what matters is that at
        // least one of them bounds it to values the enum can read.
        $admitted = null;

        foreach ($checks as $values) {
            if (array_diff($values, $cases) === []) {
                $admitted = $values;

                break;
            }
        }

        if ($admitted === null) {
            $unreadable[] = sprintf(
                '%s: enum reads [%s], no constraint keeps the column inside that — %s',
                $key,
                implode(', ', $cases),
                implode(' / ', array_map(static fn (array $v): string => '['.implode(', ', $v).']', $checks)),
            );

            continue;
        }

        $isNarrower = $admitted !== $cases;
        $isDeclared = array_key_exists($key, $narrower);

        if ($isNarrower && ! $isDeclared) {
            $undeclaredNarrowing[] = sprintf(
                '%s: enum has [%s], the constraint allows only [%s]',
                $key,
                implode(', ', $cases),
                implode(', ', $admitted),
            );
        }

        if (! $isNarrower && $isDeclared) {
            $staleAllowlist[] = $key;
        }
    }

    // The direction that breaks the application: a column able to hold a value
    // Enum::from() will throw on, which makes the row unreadable rather than
    // merely wrong.
    expect($unconstrained)->toBe([], "Enum-cast columns with no CHECK constraint:\n".implode("\n", $unconstrained));
    expect($unreadable)->toBe([], "Columns that can hold a value their enum cannot read:\n".implode("\n", $unreadable));

    // The direction that is safe but usually accidental: an enum gained a case
    // and the constraint did not follow, so legitimate writes start failing.
    expect($undeclaredNarrowing)->toBe([], implode("\n", [
        'CHECK constraints narrower than their enum, with no entry saying why.',
        'Either widen the constraint or declare it in narrowerThanEnumByDesign():',
        ...$undeclaredNarrowing,
    ]));

    expect($staleAllowlist)->toBe([], 'These match their enum exactly now — remove them from narrowerThanEnumByDesign(): '.implode(', ', $staleAllowlist));
});

it('rejects a value outside the enum at the database', function (): void {
    $owner = createUser();

    // tax_filings.status is a plain owned table with a short enum, so this
    // needs no premium module to demonstrate the mechanism the whole test
    // file exists for.
    expect(fn () => DB::table('tax_filings')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $owner->id,
        'type' => 'vat_return',
        'status' => 'definitely-not-a-status',
        'period_year' => 2026,
        'period_month' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
