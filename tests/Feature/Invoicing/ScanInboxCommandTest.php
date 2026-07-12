<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    // Deterministic OCR result — real pdfparser/tesseract need actual
    // document binaries and system binaries that a unit run can't rely on.
    $this->app->bind(InvoiceTextExtractor::class, fn () => new class implements InvoiceTextExtractor
    {
        public function extract(string $absolutePath, string $mime): ExtractionResult
        {
            return new ExtractionResult(
                "Faktúra číslo: INV-2026-001\nIČO: 12345678\nCelkom k úhrade: 120,00 EUR\nČíslo účtu: 19-2000145399/0800\nIBAN: CZ6508000000192000145399",
                'stub',
            );
        }
    });
});

it('scans new files into pending inbox items and moves them to processed', function (): void {
    $user = createUser(['invoice_inbox_enabled' => true]);

    Storage::disk('local')->put("inbox/{$user->id}/invoice.pdf", '%PDF-1.4 stub-pdf-contents');

    $this->artisan('qasa:invoices:scan-inbox')->assertSuccessful();

    $item = InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail();

    expect($item->status)->toBe('pending')
        ->and($item->user_id)->toBe($user->id)
        ->and($item->ocr_engine)->toBe('stub')
        ->and($item->suggestions['supplier_invoice_number'] ?? null)->toBe('INV-2026-001')
        ->and($item->suggestions['account_number'] ?? null)->toBe('19-2000145399')
        ->and($item->suggestions['bank_code'] ?? null)->toBe('0800')
        ->and($item->suggestions['iban'] ?? null)->toBe('CZ6508000000192000145399');

    Storage::disk('local')->assertMissing("inbox/{$user->id}/invoice.pdf");
    Storage::disk('local')->assertExists("inbox/{$user->id}/processed/invoice.pdf");
});

it('skips duplicate files by hash and still moves them to processed', function (): void {
    $user = createUser(['invoice_inbox_enabled' => true]);

    Storage::disk('local')->put("inbox/{$user->id}/first.pdf", '%PDF-1.4 same-contents');
    $this->artisan('qasa:invoices:scan-inbox')->assertSuccessful();

    Storage::disk('local')->put("inbox/{$user->id}/second.pdf", '%PDF-1.4 same-contents');
    $this->artisan('qasa:invoices:scan-inbox')->assertSuccessful();

    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(1);
    Storage::disk('local')->assertExists("inbox/{$user->id}/processed/second.pdf");
});

it('skips accounts with the inbox scanner disabled', function (): void {
    $user = createUser(['invoice_inbox_enabled' => false]);

    Storage::disk('local')->put("inbox/{$user->id}/invoice.pdf", '%PDF-1.4 stub-pdf-contents');

    $this->artisan('qasa:invoices:scan-inbox')->assertSuccessful();

    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(0);
    Storage::disk('local')->assertExists("inbox/{$user->id}/invoice.pdf");
});

it('only scans the requested account when --account is given', function (): void {
    $user = createUser(['invoice_inbox_enabled' => true]);
    $other = createUser(['invoice_inbox_enabled' => true]);

    Storage::disk('local')->put("inbox/{$user->id}/invoice.pdf", '%PDF-1.4 stub-pdf-contents');
    Storage::disk('local')->put("inbox/{$other->id}/invoice.pdf", '%PDF-1.4 stub-pdf-contents');

    $this->artisan('qasa:invoices:scan-inbox', ['--account' => $user->id])->assertSuccessful();

    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(1)
        ->and(InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail()->user_id)->toBe($user->id);

    Storage::disk('local')->assertExists("inbox/{$other->id}/invoice.pdf");
});
