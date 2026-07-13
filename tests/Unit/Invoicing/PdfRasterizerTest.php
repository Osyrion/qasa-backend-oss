<?php

declare(strict_types=1);

use App\Modules\Invoicing\Infrastructure\Ocr\PdfRasterizer;

/**
 * pdftoppm itself isn't guaranteed to be installed in every environment
 * that runs the suite, so these tests point pdftoppm_path at a small fake
 * binary (tests/Fixtures/ocr/fake-pdftoppm.sh) that mimics its I/O contract
 * — this still exercises PdfRasterizer's real Process invocation and file
 * discovery, just not the real poppler-utils rasterization itself.
 */
it('rasterizes a PDF into page images via the configured binary', function (): void {
    config(['invoicing.inbox.pdftoppm_path' => base_path('tests/Fixtures/ocr/fake-pdftoppm.sh')]);

    $pages = (new PdfRasterizer)->rasterize(base_path('tests/Fixtures/inbox/scanned.pdf'));

    expect($pages)->toHaveCount(2);

    foreach ($pages as $page) {
        expect($page)->toEndWith('.png');
        expect(file_exists($page))->toBeTrue();
    }

    (new PdfRasterizer)->cleanup($pages);

    foreach ($pages as $page) {
        expect(file_exists($page))->toBeFalse();
    }
});

it('passes the configured dpi and page limit to the binary', function (): void {
    config([
        'invoicing.inbox.pdftoppm_path' => base_path('tests/Fixtures/ocr/fake-pdftoppm.sh'),
        'invoicing.inbox.ocr_dpi' => 150,
        'invoicing.inbox.ocr_max_pages' => 3,
    ]);

    $pages = (new PdfRasterizer)->rasterize(base_path('tests/Fixtures/inbox/scanned.pdf'));

    $argsLog = dirname($pages[0]).'/page.args.log';
    $args = file_get_contents($argsLog);

    expect($args)->toContain('-r 150')->toContain('-l 3');

    (new PdfRasterizer)->cleanup($pages);
});

it('returns no pages when the configured binary is missing', function (): void {
    config(['invoicing.inbox.pdftoppm_path' => 'qasa-nonexistent-pdftoppm-binary']);

    $pages = (new PdfRasterizer)->rasterize(base_path('tests/Fixtures/inbox/scanned.pdf'));

    expect($pages)->toBe([]);
});
