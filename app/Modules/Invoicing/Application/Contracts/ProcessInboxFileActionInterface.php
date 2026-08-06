<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;

/**
 * The contract other modules reach through — ModuleBoundariesTest requires
 * cross-module Application-layer dependencies to go via an interface, not a
 * concrete Action class, same shape as RecordPaymentActionInterface. Used by
 * Integrations\ProcessInboundEmailAction (N1, e-mail-in) to ingest a
 * Postmark attachment through the exact same pipeline as a directory-scanned
 * or manually uploaded file.
 */
interface ProcessInboxFileActionInterface
{
    public const array ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function execute(User $owner, string $disk, string $path, ?string $originalFilename = null): ?InvoiceInboxItem;
}
