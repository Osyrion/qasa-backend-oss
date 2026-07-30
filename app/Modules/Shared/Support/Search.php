<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use InvalidArgumentException;

/**
 * Case- and diacritics-insensitive substring search over text columns:
 * "cafe" finds "Café" and "Café" finds "cafe", because both sides of the
 * comparison are folded through immutable_unaccent(lower(...)) — the
 * IMMUTABLE wrapper created in
 * 0001_01_01_000000_add_postgres_extensions.php.
 *
 * The expression below is written exactly as the matching GIN trigram
 * indexes in 2026_07_28_000001_add_search_indexes.php are, schema
 * qualification included. A functional index only serves a query whose
 * expression is character-for-character the same one, so rewording this
 * without reworking the indexes silently downgrades every search to a
 * sequential scan — searches keep returning correct results, they just stop
 * being indexed. SearchIndexUsageTest guards that pairing.
 */
final class Search
{
    /**
     * Turn a user-supplied needle into a "contains" LIKE pattern.
     *
     * % and _ are escaped because LIKE would otherwise read them as
     * wildcards: without this, searching for "50%" matches every row, and
     * "a_b" matches "axb".
     */
    public static function term(string $needle): string
    {
        return '%'.addcslashes($needle, '%_\\').'%';
    }

    /**
     * SQL predicate for "$column contains the term, ignoring case and
     * diacritics", with one positional placeholder for the term. Pass it to
     * whereRaw()/orWhereRaw() together with a term() binding.
     *
     * LIKE rather than ILIKE on purpose — the expression is already folded
     * to lower case on both sides, so ILIKE would only add work.
     *
     * $column is literal-string because whereRaw()/orWhereRaw() accept
     * nothing else, which is exactly the right constraint: it makes a
     * request-supplied column name a static error rather than a runtime one.
     * The guard below covers the callers PHPStan cannot see.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function unaccentedLike(string $column): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
            throw new InvalidArgumentException("Unsafe search column: {$column}");
        }

        return "public.immutable_unaccent(lower({$column})) LIKE public.immutable_unaccent(lower(?))";
    }

    /**
     * Full-text predicate over one or more text columns, with one positional
     * placeholder for a term() built by fullTextTerm().
     *
     * Paired with a GIN index on the identical expression, the same way
     * unaccentedLike() is — see 2026_07_29_000010_add_full_text_indexes.
     *
     * The 'simple' configuration is deliberate. PostgreSQL ships no Slovak or
     * Czech configuration and the image carries no hunspell dictionaries, so
     * there is no stemming to be had; 'simple' tokenises and folds case, and
     * unaccent handles the diacritics. fullTextTerm() makes every token a
     * prefix, which covers Slovak and Czech inflection well because it is
     * suffixal — "rekonstrukci:*" matches Rekonštrukcia, Rekonštrukcie and
     * Rekonštrukciou alike. It does not cover a changing stem.
     *
     * @param  literal-string  ...$columns
     * @return literal-string
     */
    public static function fullTextMatch(string ...$columns): string
    {
        $parts = [];

        foreach ($columns as $column) {
            if (preg_match('/^[a-z_][a-z0-9_.]*$/', $column) !== 1) {
                throw new InvalidArgumentException("Unsafe search column: {$column}");
            }

            $parts[] = "coalesce({$column}, '')";
        }

        /** @var literal-string $document */
        $document = implode(" || ' ' || ", $parts);

        return "to_tsvector('simple', public.immutable_unaccent({$document}))
            @@ to_tsquery('simple', public.immutable_unaccent(?))";
    }

    /**
     * Turn a user's needle into a prefix tsquery, or null when it holds
     * nothing searchable.
     *
     * Splitting on everything that is not a letter or digit is what makes
     * this safe: the tokens that survive cannot carry tsquery's own operators
     * (& | ! : * parentheses), so there is no query syntax to inject.
     */
    public static function fullTextTerm(string $needle): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return null;
        }

        return implode(' & ', array_map(
            static fn (string $token): string => mb_strtolower($token).':*',
            $tokens,
        ));
    }
}
