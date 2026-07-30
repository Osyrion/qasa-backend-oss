<?php

declare(strict_types=1);

use App\Modules\Invoicing\Infrastructure\Ocr\TesseractExtractor;
use Illuminate\Support\Facades\Process;

/**
 * A minimal real 1x1 PNG (not GD-generated — GD isn't guaranteed to be
 * installed everywhere the suite runs). getimagesize() only needs to parse
 * the header, so this is enough to exercise the pixel-limit guard without
 * a binary fixture file.
 */
function tinyPngPath(): string
{
    $path = sys_get_temp_dir().'/tesseract-extractor-test-'.uniqid().'.png';
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    ));

    return $path;
}

it('rejects an image above the configured pixel limit before running tesseract', function (): void {
    Process::fake();
    config(['invoicing.inbox.ocr_max_pixels_per_side' => 0]);

    $path = tinyPngPath();

    try {
        $result = (new TesseractExtractor)->extract($path, 'image/png');

        expect($result->text)->toBe('')
            ->and($result->engine)->toBe('tesseract');

        Process::assertNothingRan();
    } finally {
        @unlink($path);
    }
});

it('runs tesseract on an image within the configured pixel limit', function (): void {
    Process::fake([
        '*' => Process::result(output: 'INV-2026-STUB'),
    ]);
    config(['invoicing.inbox.ocr_max_pixels_per_side' => 10000, 'invoicing.inbox.ocr_languages' => 'eng']);

    $path = tinyPngPath();

    try {
        $result = (new TesseractExtractor)->extract($path, 'image/png');

        expect($result->text)->toBe('INV-2026-STUB')
            ->and($result->engine)->toBe('tesseract');

        Process::assertRan(fn ($process): bool => str_contains($process->command[0] ?? '', 'tesseract')
            && in_array($path, $process->command, true)
            && in_array('stdout', $process->command, true)
            && in_array('eng', $process->command, true));
    } finally {
        @unlink($path);
    }
});

it('degrades to an empty result when getimagesize cannot read the file', function (): void {
    Process::fake();
    $path = sys_get_temp_dir().'/tesseract-extractor-test-'.uniqid().'.png';
    file_put_contents($path, 'not-a-real-image');

    try {
        $result = (new TesseractExtractor)->extract($path, 'image/png');

        expect($result->text)->toBe('');
        Process::assertNothingRan();
    } finally {
        @unlink($path);
    }
});

it('returns empty text for non-image mime types without touching the filesystem', function (): void {
    Process::fake();

    $result = (new TesseractExtractor)->extract('/nonexistent/path.pdf', 'application/pdf');

    expect($result->text)->toBe('')
        ->and($result->engine)->toBe('tesseract');

    Process::assertNothingRan();
});
