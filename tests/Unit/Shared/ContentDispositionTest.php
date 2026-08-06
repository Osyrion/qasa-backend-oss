<?php

declare(strict_types=1);

use App\Modules\Shared\Support\ContentDisposition;

it('escapes a quote instead of letting it close the filename', function (): void {
    // A licence plate is validated as string|max:20 — a quote is allowed
    // through, and concatenation let it dictate the saved filename.
    $header = ContentDisposition::attachment('logbook_BA"; filename="payload.exe_2026-01.csv');

    // The injected parameter is neutralised, not honoured: no second
    // filename= survives in the header, and the quote is gone from the
    // quoted form (it lives on percent-encoded inside filename*).
    expect($header)->not->toContain('filename="payload.exe')
        ->and(substr_count($header, 'filename='))->toBe(1)
        ->and($header)->toContain("filename*=utf-8''");
});

it('keeps a path separator out of the filename', function (): void {
    $header = ContentDisposition::attachment('../../etc/passwd');

    expect($header)->not->toContain('/')
        ->and($header)->not->toContain('\\\\');
});

it('carries diacritics through the RFC 5987 form', function (): void {
    $header = ContentDisposition::attachment('faktúra-2026-001.pdf');

    expect($header)->toContain("filename*=utf-8''")
        ->and($header)->toContain('fakt%C3%BAra')
        // and still offers an ASCII name to clients that ignore filename*
        ->and($header)->toContain('filename=faktura-2026-001.pdf');
});

it('builds the two dispositions it is asked for', function (): void {
    expect(ContentDisposition::attachment('a.pdf'))->toStartWith('attachment; ')
        ->and(ContentDisposition::inline('a.pdf'))->toStartWith('inline; ');
});

it('never produces an empty filename', function (): void {
    expect(ContentDisposition::attachment(''))->toContain('download')
        ->and(ContentDisposition::attachment("\x00\x01"))->toContain('download');
});
