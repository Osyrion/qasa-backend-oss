<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Unlike ScanInboxCommandTest/InvoiceInboxUploadTest, this file deliberately
 * does NOT stub InvoiceTextExtractor — it exercises the real
 * CompositeExtractor -> PdfRasterizer -> TesseractExtractor pipeline, so it
 * only runs where pdftoppm and tesseract are actually installed.
 */
beforeEach(function (): void {
    Storage::fake('local');

    if (trim((string) shell_exec('which pdftoppm')) === '' || trim((string) shell_exec('which tesseract')) === '') {
        $this->markTestSkipped('pdftoppm and/or tesseract are not installed in this environment.');
    }
});

it('uploads a scanned PDF and lands as pending via the pdftoppm+tesseract fallback', function (): void {
    $user = createUser();
    $file = UploadedFile::fake()->createWithContent(
        'scan.pdf',
        (string) file_get_contents(base_path('tests/Fixtures/inbox/scanned-with-text.pdf')),
    );

    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(202);

    $item = InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail();

    expect($item->status)->toBe('pending')
        ->and($item->ocr_engine)->toBe('pdftoppm+tesseract')
        ->and($item->ocr_text)->toContain('INV-2026-999');
});
