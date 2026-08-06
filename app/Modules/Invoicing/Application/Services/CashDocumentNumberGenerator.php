<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use Illuminate\Support\Facades\DB;

/**
 * `PPD-2026-001` / `VPD-2026-001`, sequential per account, type and year.
 *
 * Deliberately not user-configurable: invoices, quotes and supplier invoices
 * already carry three number masks between them, and a fourth nobody asked
 * for is configuration for its own sake.
 *
 * The next number is derived from the highest existing one rather than a
 * counter column, so a failed insert leaves no gap — and the row lock is
 * what keeps two concurrent receipts from claiming the same number.
 */
final readonly class CashDocumentNumberGenerator
{
    public function next(string $ownerId, CashDocumentType $type, int $year): string
    {
        $prefix = sprintf('%s-%d-', $type->numberPrefix(), $year);

        $last = CashDocument::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->where('type', $type->value)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Convenience wrapper for the common "inside a transaction" call.
     *
     * @template T
     *
     * @param  callable(string): T  $work
     * @return T
     */
    public function withNumber(string $ownerId, CashDocumentType $type, int $year, callable $work): mixed
    {
        return DB::transaction(fn (): mixed => $work($this->next($ownerId, $type, $year)));
    }
}
