<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use BackedEnum;
use Illuminate\Support\Facades\DB;
use ReflectionEnum;
use RuntimeException;

/**
 * A CHECK constraint that spells out a backed enum's cases.
 *
 * Why the database has to say this and not just the model: Eloquent's enum
 * cast reads with `Enum::from()`, which throws a \ValueError on a value it
 * does not recognise. A row written with a string outside the enum is
 * therefore not a wrong value that shows up oddly in the UI — it is a row that
 * cannot be *read* at all, a 500 on every list containing it, and one no API
 * call can repair because every write path hydrates the model first. The
 * columns this went missing on were the externally fed ones (Peppol
 * callbacks, imports, provider webhooks), which is exactly where an unexpected
 * string arrives.
 *
 * Derived from the enum rather than a list written out in the migration, so
 * the constraint cannot drift from the PHP it mirrors, and
 * tests/Architecture/EnumColumnCheckTest.php compares the two on every run.
 *
 * NOT VALID and then VALIDATE, rather than a plain ADD: identical strictness —
 * validation still fails the migration on data that does not fit — but the
 * exclusive lock lasts for the ADD alone, while the scan of existing rows
 * takes only SHARE UPDATE EXCLUSIVE and lets writes through.
 */
final class EnumCheck
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function add(string $table, string $column, string $enum): void
    {
        $name = self::name($table, $column);

        $values = implode(', ', array_map(
            static fn (BackedEnum $case): string => DB::getPdo()->quote((string) $case->value),
            $enum::cases(),
        ));

        if ($values === '') {
            throw new RuntimeException("Enum {$enum} has no cases; a CHECK would reject every row.");
        }

        // The cast keeps the comparison off the column's own type: `varchar IN
        // (text…)` is what Postgres writes for these anyway, and naming it
        // means an int-backed enum works here unchanged.
        $expression = sprintf(
            '%s::text IN (%s)',
            self::identifier($column),
            $values,
        );

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s) NOT VALID',
            self::identifier($table),
            self::identifier($name),
            $expression,
        ));

        DB::statement(sprintf(
            'ALTER TABLE %s VALIDATE CONSTRAINT %s',
            self::identifier($table),
            self::identifier($name),
        ));
    }

    public static function drop(string $table, string $column): void
    {
        DB::statement(sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
            self::identifier($table),
            self::identifier(self::name($table, $column)),
        ));
    }

    /**
     * The name Laravel's own `$table->enum()` would have produced, so the
     * constraints added here and the ones already in the schema read alike.
     */
    public static function name(string $table, string $column): string
    {
        return $table.'_'.$column.'_check';
    }

    /**
     * The enum a column is cast to, or null if it is not cast to one.
     *
     * Shared with the architecture test so that "which columns need a check"
     * has one definition rather than two that can disagree.
     *
     * @return class-string<BackedEnum>|null
     */
    public static function backedEnumCast(mixed $cast): ?string
    {
        if (! is_string($cast) || ! enum_exists($cast)) {
            return null;
        }

        /** @var class-string<BackedEnum> $cast */
        return (new ReflectionEnum($cast))->isBacked() ? $cast : null;
    }

    private static function identifier(string $value): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $value) !== 1) {
            throw new RuntimeException("Unsafe identifier: {$value}");
        }

        return '"'.$value.'"';
    }
}
