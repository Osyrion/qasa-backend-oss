<?php

declare(strict_types=1);

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Jobs\ProcessInboxItemJob;
use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Services\SupplierInvoiceParser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('settles a processing item into pending with suggestions on successful OCR', function (): void {
    Event::fake([InboxItemCreated::class]);

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

    $user = createUser();
    Storage::disk('local')->put('supplier-invoices/inbox/'.$user->id.'/doc.pdf', '%PDF-1.4 contents');
    $item = InvoiceInboxItem::factory()->processing()->create([
        'user_id' => $user->id,
        'path' => 'supplier-invoices/inbox/'.$user->id.'/doc.pdf',
    ]);

    (new ProcessInboxItemJob($item->id))->handle(
        app(InvoiceTextExtractor::class),
        app(SupplierInvoiceParser::class),
        app(ClientRepositoryInterface::class),
    );

    $item->refresh();
    expect($item->status)->toBe('pending')
        ->and($item->ocr_engine)->toBe('stub')
        ->and($item->suggestions['supplier_invoice_number'] ?? null)->toBe('INV-2026-001');

    Event::assertDispatched(InboxItemCreated::class, fn (InboxItemCreated $event): bool => $event->item->id === $item->id);
});

it('settles a processing item into failed when OCR yields no text', function (): void {
    Event::fake([InboxItemCreated::class]);

    $this->app->bind(InvoiceTextExtractor::class, fn () => new class implements InvoiceTextExtractor
    {
        public function extract(string $absolutePath, string $mime): ExtractionResult
        {
            return new ExtractionResult('', 'stub');
        }
    });

    $user = createUser();
    Storage::disk('local')->put('supplier-invoices/inbox/'.$user->id.'/doc.pdf', '%PDF-1.4 contents');
    $item = InvoiceInboxItem::factory()->processing()->create([
        'user_id' => $user->id,
        'path' => 'supplier-invoices/inbox/'.$user->id.'/doc.pdf',
    ]);

    (new ProcessInboxItemJob($item->id))->handle(
        app(InvoiceTextExtractor::class),
        app(SupplierInvoiceParser::class),
        app(ClientRepositoryInterface::class),
    );

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error)->not->toBeNull();

    Event::assertNotDispatched(InboxItemCreated::class);
});

it('marks the item as failed via the failed() hook when the job exhausts retries', function (): void {
    $user = createUser();
    $item = InvoiceInboxItem::factory()->processing()->create(['user_id' => $user->id]);

    (new ProcessInboxItemJob($item->id))->failed(new RuntimeException('boom'));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error)->not->toBeNull();
});
