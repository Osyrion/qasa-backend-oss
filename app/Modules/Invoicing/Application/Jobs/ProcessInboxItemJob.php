<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Jobs;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\UblInvoiceParserInterface;
use App\Modules\Invoicing\Application\Services\FieldExtractorFactory;
use App\Modules\Invoicing\Domain\Contracts\FieldExtractionInput;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Exceptions\LlmExtractionException;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Shared\Domain\Contracts\AccountLocator;
use App\Modules\Shared\Domain\Contracts\FullAccount;
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
        FieldExtractorFactory $extractorFactory,
        InvoiceFieldExtractor $regexExtractor,
        ClientRepositoryInterface $clients,
        UblInvoiceParserInterface $ublParser,
        AccountLocator $accounts,
    ): void {
        $item = $this->findItem();

        if ($item === null) {
            return;
        }

        $absolutePath = Storage::disk($item->disk)->path($item->path);

        // A structured e-invoice states its fields instead of rendering them,
        // so there is nothing to OCR and nothing to guess. Short-circuiting
        // here is the whole reason to support UBL on the receiving side.
        if ($this->settleFromUbl($item, $ublParser, $clients)) {
            return;
        }

        $extraction = $extractor->extract($absolutePath, $item->mime_type);

        if (trim($extraction->text) === '') {
            $item->status = InvoiceInboxStatus::Failed->value;
            $item->ocr_text = $extraction->text;
            $item->ocr_engine = $extraction->engine;
            $item->error = __('invoicing.inbox.extraction_failed');
            $item->save();

            return;
        }

        $input = new FieldExtractionInput(
            text: $extraction->text,
            absolutePath: $absolutePath,
            mime: $item->mime_type,
        );

        $owner = $this->resolveOwner($item->user_id, $accounts);

        [$suggestions, $source, $provider, $model] = $owner === null
            ? [$regexExtractor->parse($input)->suggestions, 'regex', null, null]
            : $this->extractFields($owner, $input, $extractorFactory, $regexExtractor);

        $matchedClientId = isset($suggestions['ico']) && is_string($suggestions['ico'])
            ? $clients->findVendorByIco($item->user_id, $suggestions['ico'])?->id
            : null;

        $item->status = InvoiceInboxStatus::Pending->value;
        $item->ocr_text = $extraction->text;
        $item->ocr_engine = $extraction->engine;
        $item->suggestions = $suggestions;
        $item->suggestions_source = $source;
        $item->suggestions_provider = $provider;
        $item->suggestions_model = $model;
        $item->matched_client_id = $matchedClientId;
        $item->error = null;
        $item->save();

        event(new InboxItemCreated($item));
    }

    /**
     * Settles the item straight from a UBL payload, or reports that this is
     * not one and leaves it to OCR.
     */
    private function settleFromUbl(
        InvoiceInboxItem $item,
        UblInvoiceParserInterface $ublParser,
        ClientRepositoryInterface $clients,
    ): bool {
        // Cheap gate first: only an XML-ish payload is worth reading into a
        // DOM at all, and the inbox mostly carries PDFs and scans.
        if (! str_contains($item->mime_type, 'xml')) {
            return false;
        }

        $suggestions = $ublParser->parse((string) Storage::disk($item->disk)->get($item->path));

        if ($suggestions === null) {
            return false;
        }

        $ico = $suggestions['ico'] ?? null;

        $item->status = InvoiceInboxStatus::Pending->value;
        // No OCR ran, so there is no extracted text and no engine to name —
        // recording either would misrepresent where the fields came from.
        $item->ocr_text = null;
        $item->ocr_engine = null;
        $item->suggestions = $suggestions;
        $item->suggestions_source = 'ubl';
        $item->suggestions_provider = null;
        $item->suggestions_model = null;
        $item->matched_client_id = is_string($ico) ? $clients->findVendorByIco($item->user_id, $ico)?->id : null;
        $item->error = null;
        $item->save();

        event(new InboxItemCreated($item));

        return true;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string|null, 3: string|null}
     */
    private function extractFields(
        FullAccount $owner,
        FieldExtractionInput $input,
        FieldExtractorFactory $extractorFactory,
        InvoiceFieldExtractor $regexExtractor,
    ): array {
        $selection = $extractorFactory->forOwner($owner);

        if ($selection->quotaExhausted) {
            $suggestions = $regexExtractor->parse($input)->suggestions;
            $suggestions['quota_exhausted'] = true;

            return [$suggestions, 'regex', null, null];
        }

        try {
            $result = $selection->extractor->parse($input);

            return [$result->suggestions, $result->source, $result->provider, $result->model];
        } catch (LlmExtractionException $e) {
            report($e);

            if ($selection->quotaConsumed) {
                $extractorFactory->refund($owner);
            }

            $suggestions = $regexExtractor->parse($input)->suggestions;

            if ($e->isAuthError()) {
                $suggestions['byok_key_invalid'] = true;

                // Tenant can see, in the ai-credentials list, exactly which
                // key started failing — never re-attempted automatically.
                $selection->credential?->update([
                    'verified_at' => null,
                    'last_error' => $e->getMessage(),
                ]);
            }

            return [$suggestions, 'regex', null, null];
        }
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

    /**
     * Through AccountLocator rather than the item's own `user` relation:
     * FieldExtractorFactory gates on plan features, and only the edition's
     * own model carries the real hasFeature()/currentPlan() overrides — a
     * core instance reports every feature as granted.
     */
    private function resolveOwner(string $userId, AccountLocator $accounts): ?FullAccount
    {
        return $accounts->find($userId);
    }
}
