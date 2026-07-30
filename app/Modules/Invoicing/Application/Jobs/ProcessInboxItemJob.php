<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Jobs;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Services\FieldExtractorFactory;
use App\Modules\Invoicing\Domain\Contracts\FieldExtractionInput;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Infrastructure\Ocr\LlmExtractionException;
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

        $input = new FieldExtractionInput(
            text: $extraction->text,
            absolutePath: $absolutePath,
            mime: $item->mime_type,
        );

        $owner = $this->resolveOwner($item->user_id);

        [$suggestions, $source, $provider] = $owner === null
            ? [$regexExtractor->parse($input)->suggestions, 'regex', null]
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
        $item->matched_client_id = $matchedClientId;
        $item->error = null;
        $item->save();

        event(new InboxItemCreated($item));
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string|null}
     */
    private function extractFields(
        User $owner,
        FieldExtractionInput $input,
        FieldExtractorFactory $extractorFactory,
        InvoiceFieldExtractor $regexExtractor,
    ): array {
        $selection = $extractorFactory->forOwner($owner);

        if ($selection->quotaExhausted) {
            $suggestions = $regexExtractor->parse($input)->suggestions;
            $suggestions['quota_exhausted'] = true;

            return [$suggestions, 'regex', null];
        }

        try {
            $result = $selection->extractor->parse($input);

            return [$result->suggestions, $result->source, $result->provider];
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

            return [$suggestions, 'regex', null];
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
     * InvoiceInboxItem::user() is hardcoded to the core Auth\Domain\Models\User
     * class (a plain Eloquent belongsTo can't resolve the edition's swapped
     * model), so it would silently skip the SaaS User subclass's real
     * hasFeature()/currentPlan() overrides — always reporting every
     * feature as granted. FieldExtractorFactory needs the real edition
     * model to gate correctly, so the owner is re-fetched through the
     * configured auth provider, exactly like RegisterUserAction does.
     */
    private function resolveOwner(string $userId): ?User
    {
        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        /** @var User|null */
        return $model::query()->find($userId);
    }
}
