<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Jobs;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Services\SupplierInvoiceParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs OCR + field-suggestion parsing + vendor matching for an inbox item
 * created by ProcessInboxFileAction, out of the HTTP/cron request path.
 * Never retried — a single failed attempt (bad OCR, exception, worker
 * crash) settles the item as `failed` rather than leaving it stuck in
 * `processing` forever or replaying a possibly-expensive OCR pass.
 */
final class ProcessInboxItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $inboxItemId,
    ) {}

    public function handle(
        InvoiceTextExtractor $extractor,
        SupplierInvoiceParser $parser,
        ClientRepositoryInterface $clients,
    ): void {
        $item = $this->findItem();

        if ($item === null) {
            return;
        }

        $absolutePath = Storage::disk($item->disk)->path($item->path);
        $extraction = $extractor->extract($absolutePath, $item->mime_type);

        if (trim($extraction->text) === '') {
            $item->status = InvoiceInboxStatus::Failed->value;
            $item->ocr_text = $extraction->text;
            $item->ocr_engine = $extraction->engine;
            $item->error = __('invoicing.inbox.extraction_failed');
            $item->save();

            return;
        }

        $suggestions = $parser->parse($extraction->text);
        $matchedClientId = isset($suggestions['ico']) && is_string($suggestions['ico'])
            ? $clients->findVendorByIco($item->user_id, $suggestions['ico'])?->id
            : null;

        $item->status = InvoiceInboxStatus::Pending->value;
        $item->ocr_text = $extraction->text;
        $item->ocr_engine = $extraction->engine;
        $item->suggestions = $suggestions;
        $item->matched_client_id = $matchedClientId;
        $item->error = null;
        $item->save();

        event(new InboxItemCreated($item));
    }

    public function failed(?Throwable $exception): void
    {
        $item = $this->findItem();

        if ($item === null || $item->status === InvoiceInboxStatus::Failed->value) {
            return;
        }

        $item->status = InvoiceInboxStatus::Failed->value;
        $item->error = __('invoicing.inbox.processing_failed');
        $item->save();
    }

    private function findItem(): ?InvoiceInboxItem
    {
        /** @var InvoiceInboxItem|null */
        return InvoiceInboxItem::withoutGlobalScope('user')->find($this->inboxItemId);
    }
}
