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
    /**
     * Every door into the inbox gates on this list — the upload endpoint, the
     * watched-folder scanner and the inbound e-mail webhook — so a format
     * missing here is unreachable no matter what the pipeline behind it can
     * do. That is exactly what happened to UBL: ProcessInboxItemJob could
     * settle an e-invoice without OCR, but no XML could ever get in.
     *
     * Both XML spellings are listed because the three doors learn the type
     * three different ways: finfo says text/xml, the extension map says
     * application/xml, and an e-mail attachment carries whatever the sender's
     * client wrote.
     */
    public const array ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/xml',
        'text/xml',
    ];

    public function execute(User $owner, string $disk, string $path, ?string $originalFilename = null): ?InvoiceInboxItem;
}
