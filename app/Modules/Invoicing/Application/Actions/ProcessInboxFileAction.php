<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Services\SupplierInvoiceParser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a single already-stored file (cron-scanned or manually uploaded)
 * into an InvoiceInboxItem: dedupes by content hash, copies it into
 * permanent storage, runs OCR/parsing, and dispatches InboxItemCreated.
 * Returns null when the file is a duplicate of an already-known hash —
 * callers decide how to handle that (e.g. move to processed, or reject).
 * Callers are expected to have already validated the MIME type and size.
 */
readonly class ProcessInboxFileAction
{
    public const array ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private InvoiceInboxRepositoryInterface $repository,
        private InvoiceTextExtractor $extractor,
        private SupplierInvoiceParser $parser,
        private ClientRepositoryInterface $clients,
    ) {}

    public function execute(User $owner, string $disk, string $path, ?string $originalFilename = null): ?InvoiceInboxItem
    {
        $userId = $owner->accountOwnerId();
        $mime = (string) Storage::disk($disk)->mimeType($path);
        $size = Storage::disk($disk)->size($path);
        $contents = (string) Storage::disk($disk)->get($path);
        $hash = hash('sha256', $contents);

        if ($this->repository->existsByHash($userId, $hash)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $targetPath = "supplier-invoices/inbox/{$userId}/".Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        Storage::disk('local')->put($targetPath, $contents);
        $absolutePath = Storage::disk('local')->path($targetPath);

        $fileData = $this->processFile($userId, $absolutePath, $mime);

        $item = $this->repository->create([
            'user_id' => $userId,
            'disk' => 'local',
            'path' => $targetPath,
            'original_filename' => $originalFilename ?? basename($path),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'file_hash' => $hash,
            'scanned_at' => now(),
            ...$fileData,
        ]);

        if ($fileData['status'] !== InvoiceInboxStatus::Failed->value) {
            event(new InboxItemCreated($item));
        }

        return $item;
    }

    /**
     * @return array{status: string, ocr_text: string, ocr_engine: string, suggestions: array<string, mixed>|null, matched_client_id: string|null, error: string|null}
     */
    private function processFile(string $userId, string $absolutePath, string $mime): array
    {
        $extraction = $this->extractor->extract($absolutePath, $mime);

        if (trim($extraction->text) === '') {
            return [
                'status' => InvoiceInboxStatus::Failed->value,
                'ocr_text' => $extraction->text,
                'ocr_engine' => $extraction->engine,
                'suggestions' => null,
                'matched_client_id' => null,
                'error' => __('invoicing.inbox.extraction_failed'),
            ];
        }

        $suggestions = $this->parser->parse($extraction->text);
        $matchedClientId = isset($suggestions['ico']) && is_string($suggestions['ico'])
            ? $this->clients->findVendorByIco($userId, $suggestions['ico'])?->id
            : null;

        return [
            'status' => InvoiceInboxStatus::Pending->value,
            'ocr_text' => $extraction->text,
            'ocr_engine' => $extraction->engine,
            'suggestions' => $suggestions,
            'matched_client_id' => $matchedClientId,
            'error' => null,
        ];
    }
}
