<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Services\SupplierInvoiceParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scans one account's inbox folder (config('invoicing.inbox.*')), turning
 * new PDF/image files into pending InvoiceInboxItem rows with OCR text and
 * parsed field suggestions. Source files are moved to a processed/
 * subfolder once handled (including duplicates), so a run never reprocesses
 * the same file twice. Unsupported/oversized files are left in place and
 * simply skipped again on the next run.
 */
readonly class ScanInboxAction
{
    private const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private InvoiceInboxRepositoryInterface $repository,
        private InvoiceTextExtractor $extractor,
        private SupplierInvoiceParser $parser,
        private ClientRepositoryInterface $clients,
    ) {}

    /**
     * @return array{scanned: int, failed: int, skipped: int}
     */
    public function execute(User $owner): array
    {
        $userId = $owner->accountOwnerId();
        $disk = (string) config('invoicing.inbox.disk', 'local');
        $basePath = (string) config('invoicing.inbox.path', 'inbox');
        $accountDir = "{$basePath}/{$userId}";
        $processedDir = "{$accountDir}/processed";

        $scanned = 0;
        $failed = 0;
        $skipped = 0;

        foreach (Storage::disk($disk)->files($accountDir) as $path) {
            try {
                $mime = (string) Storage::disk($disk)->mimeType($path);

                if (! in_array($mime, self::ALLOWED_MIMES, true)) {
                    $skipped++;

                    continue;
                }

                $maxBytes = (int) config('invoicing.inbox.max_bytes', 20 * 1024 * 1024);
                $size = Storage::disk($disk)->size($path);

                if ($size > $maxBytes) {
                    $skipped++;

                    continue;
                }

                $contents = (string) Storage::disk($disk)->get($path);
                $hash = hash('sha256', $contents);

                if ($this->repository->existsByHash($userId, $hash)) {
                    $skipped++;
                    Storage::disk($disk)->move($path, $processedDir.'/'.basename($path));

                    continue;
                }

                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $targetPath = "supplier-invoices/inbox/{$userId}/".Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
                Storage::disk('local')->put($targetPath, $contents);
                $absolutePath = Storage::disk('local')->path($targetPath);

                $fileData = $this->processFile($userId, $absolutePath, $mime);

                $item = $this->repository->create([
                    ...[
                        'user_id' => $userId,
                        'disk' => 'local',
                        'path' => $targetPath,
                        'original_filename' => basename($path),
                        'mime_type' => $mime,
                        'size_bytes' => $size,
                        'file_hash' => $hash,
                        'scanned_at' => now(),
                    ],
                    ...$fileData,
                ]);

                $scanned++;

                if ($fileData['status'] === InvoiceInboxStatus::Failed->value) {
                    $failed++;
                } else {
                    event(new InboxItemCreated($item));
                }

                Storage::disk($disk)->move($path, $processedDir.'/'.basename($path));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                Log::error('Invoice inbox scan failed for a file', [
                    'user_id' => $userId,
                    'path' => $path,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return ['scanned' => $scanned, 'failed' => $failed, 'skipped' => $skipped];
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
