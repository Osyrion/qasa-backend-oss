<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Content-Disposition headers built by the rules rather than by concatenation.
 *
 * Every download used to splice a filename straight into
 * `attachment; filename="…"`, and several of those filenames carry user
 * input: a vehicle's licence plate (validated as `string|max:20`, quotes and
 * all), an invoice number taken verbatim from a competitor import. A quote in
 * the name closes the quoted-string early and everything after it is read as
 * further parameters — enough to dictate the name the browser saves under.
 * PHP refuses CR/LF in a header value, so this was never response splitting;
 * it was still the wrong way to build a header.
 *
 * Symfony's HeaderUtils::makeDisposition() escapes the quoted form and adds
 * the RFC 5987 `filename*` form beside it, which fixes a second thing nobody
 * had filed: names with diacritics — "faktúra", "příkaz" — reached the
 * browser mangled, because the plain `filename` parameter is ASCII-only.
 */
final class ContentDisposition
{
    public static function attachment(string $filename): string
    {
        return self::build(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
    }

    public static function inline(string $filename): string
    {
        return self::build(HeaderUtils::DISPOSITION_INLINE, $filename);
    }

    private static function build(string $disposition, string $filename): string
    {
        $filename = self::sanitise($filename);

        return HeaderUtils::makeDisposition($disposition, $filename, self::asciiFallback($filename));
    }

    /**
     * Strip what a filename must never contain: path separators, so the name
     * can never read as a path, and control characters.
     */
    private static function sanitise(string $filename): string
    {
        $filename = str_replace(['/', '\\'], '-', $filename);
        $filename = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
        $filename = trim($filename);

        return $filename === '' ? 'download' : $filename;
    }

    /**
     * The ASCII `filename` parameter, for clients that do not read
     * `filename*`. makeDisposition() rejects a fallback containing `%` or a
     * path separator, so this transliterates and then keeps only what is
     * certainly safe.
     */
    private static function asciiFallback(string $filename): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $filename);

        if ($ascii === false) {
            $ascii = $filename;
        }

        $ascii = (string) preg_replace('/[^A-Za-z0-9._\- ]/', '_', $ascii);

        return trim($ascii) === '' ? 'download' : $ascii;
    }
}
