<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Jobs\ProcessInboxItemJob;
use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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
                "Faktúra číslo: INV-2026-001\nIČO: 12345678\nCelkom k úhrade: 120,00 EUR",
                'stub',
            );
        }
    });
});

it('uploads a PDF and creates a pending inbox item synchronously', function (): void {
    $user = createUser();
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 unique-pdf-contents');

    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(202);
    expect($response->json('status'))->toBe('pending')
        ->and($response->json('original_filename'))->toBe('invoice.pdf');

    $item = InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail();
    expect($item->user_id)->toBe($user->id)
        ->and($item->ocr_engine)->toBe('stub');
});

it('uploads an image file and creates a pending inbox item', function (): void {
    $user = createUser();
    $file = UploadedFile::fake()->createWithContent('receipt.png', 'fake-png-bytes');

    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(202);
    expect($response->json('status'))->toBe('pending')
        ->and($response->json('mime_type'))->toBe('image/png');
});

it('rejects an unsupported file type', function (): void {
    $user = createUser();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'plain text content');

    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(422);
    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(0);
});

it('rejects a file larger than the configured limit', function (): void {
    $user = createUser();
    config(['invoicing.inbox.max_bytes' => 1024]);
    $file = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf');

    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(422);
    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(0);
});

it('rejects uploading a duplicate file by content hash', function (): void {
    $user = createUser();
    $first = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 same-contents');
    $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $first])->assertStatus(202);

    $second = UploadedFile::fake()->createWithContent('invoice-copy.pdf', '%PDF-1.4 same-contents');
    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $second]);

    $response->assertStatus(422);
    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(1);
});

it('does not treat identical content from another account as a duplicate', function (): void {
    $user = createUser();
    $other = createUser();

    $file1 = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 shared-contents');
    $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file1])->assertStatus(202);

    $file2 = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 shared-contents');
    $this->actingAs($other)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file2])->assertStatus(202);

    expect(InvoiceInboxItem::withoutGlobalScope('user')->count())->toBe(2);
});

it('requires authentication to upload', function (): void {
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 contents');

    $this->postJson('/api/v1/invoice-inbox/upload', ['file' => $file])->assertUnauthorized();
});

it('uploads independently of the invoice_inbox_enabled flag', function (): void {
    $user = createUser(['invoice_inbox_enabled' => false]);
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 unique-contents-flag-off');

    $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file])->assertStatus(202);
});

it('accepts the upload immediately and processes OCR in the background', function (): void {
    Queue::fake();
    $user = createUser();
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 async-contents');

    $response = $this->actingAs($user)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(202);
    expect($response->json('status'))->toBe('processing')
        ->and($response->json('ocr_engine'))->toBeNull()
        ->and($response->json('suggestions'))->toBeNull();

    $item = InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail();
    expect($item->status)->toBe('processing');

    Queue::assertPushed(ProcessInboxItemJob::class, fn (ProcessInboxItemJob $job): bool => $job->inboxItemId === $item->id);
});
