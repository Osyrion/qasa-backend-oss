<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scans one account's inbox folder (config('invoicing.inbox.*')), turning
 * new PDF/image files into pending InvoiceInboxItem rows with OCR text and
 * parsed field suggestions (delegated to ProcessInboxFileAction). Source
 * files are moved to a processed/ subfolder once handled (including
 * duplicates), so a run never reprocesses the same file twice.
 * Unsupported/oversized files are left in place and simply skipped again
 * on the next run.
 */
readonly class ScanInboxAction
{
    public function __construct(
        private ProcessInboxFileAction $processInboxFile,
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

                if (! in_array($mime, ProcessInboxFileAction::ALLOWED_MIMES, true)) {
                    $skipped++;

                    continue;
                }

                $maxBytes = (int) config('invoicing.inbox.max_bytes', 20 * 1024 * 1024);

                if (Storage::disk($disk)->size($path) > $maxBytes) {
                    $skipped++;

                    continue;
                }

                $item = $this->processInboxFile->execute($owner, $disk, $path);

                if ($item === null) {
                    $skipped++;
                    Storage::disk($disk)->move($path, $processedDir.'/'.basename($path));

                    continue;
                }

                $scanned++;

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
}
