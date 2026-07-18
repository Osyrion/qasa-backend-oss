<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Application\Jobs\ProcessInboxItemJob;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ingests a single already-stored file (cron-scanned or manually uploaded)
 * into an InvoiceInboxItem: dedupes by content hash, copies it into
 * permanent storage, and creates the item in `processing` status. OCR,
 * parsing, and vendor matching happen out-of-process in ProcessInboxItemJob
 * so this stays cheap enough to run inline in the upload request/cron scan.
 * Returns null when the file is a duplicate of an already-known hash —
 * callers decide how to handle that (e.g. move to processed, or reject).
 * Callers are expected to have already validated the MIME type and size.
 */
readonly class ProcessInboxFileAction
{
    public const array ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private InvoiceInboxRepositoryInterface $repository,
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

        $item = $this->repository->create([
            'user_id' => $userId,
            'status' => InvoiceInboxStatus::Processing->value,
            'disk' => 'local',
            'path' => $targetPath,
            'original_filename' => $originalFilename ?? basename($path),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'file_hash' => $hash,
            'scanned_at' => now(),
        ]);

        ProcessInboxItemJob::dispatch($item->id);

        // Under the sync queue connection (tests, some deployments) the job
        // above already ran and updated the row — reload so the caller sees
        // the settled state instead of the stale in-memory `processing` one.
        return $item->refresh();
    }
}
