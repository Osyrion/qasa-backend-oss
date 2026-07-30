<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Contracts;

/**
 * Lets a module add its own data to the GDPR account export without Auth
 * knowing it exists. Implementations are tagged 'account.export' in their own
 * service provider; the OSS edition simply has no contributors, so those keys
 * are absent from the export.
 */
interface AccountExportContributor
{
    /**
     * Extra top-level export sections, keyed by section name.
     *
     * @return array<string, mixed>
     */
    public function exportFor(string $ownerId): array;
}
