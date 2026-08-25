<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Auth\Application\Contracts\AccountExportContributor;
use App\Modules\Orders\Domain\Models\Order;

/**
 * Orders' section of the GDPR account export.
 *
 * Attachments are listed as metadata only — filename, label, mime type,
 * size — never the stored bytes: a portability export is the record of what
 * exists, and the files are downloaded through their own endpoint.
 */
final class OrdersAccountData implements AccountExportContributor
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(string $ownerId): array
    {
        return [
            'orders' => Order::forUser($ownerId)->with(['items', 'notes', 'attachments'])->get()
                ->map(fn (Order $order): array => [
                    ...$order->toArray(),
                    'attachments' => $order->attachments->map(fn ($attachment): array => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'label' => $attachment->label,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                        'created_at' => $attachment->created_at?->toISOString(),
                    ])->toArray(),
                ])->toArray(),
        ];
    }
}
