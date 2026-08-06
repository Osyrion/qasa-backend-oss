<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Shared\Support\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The cash book (peňažný denník): every cash document in date order with a
 * running balance.
 *
 * Balances are kept **per currency**. One account can take cash in EUR and
 * CZK, and a single balance across both would be a number that means
 * nothing — the alternative, converting to one currency at today's rate,
 * would silently rewrite history every day.
 */
final readonly class CashBookService
{
    /**
     * @return array{
     *     opening_balances: array<string, string>,
     *     entries: list<array<string, mixed>>,
     *     closing_balances: array<string, string>
     * }
     */
    public function build(string $ownerId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        // Everything before the window, so the first entry starts from the
        // balance actually carried into the period rather than from zero.
        $opening = $from === null
            ? []
            : $this->balancesUpTo($ownerId, $from);

        $query = CashDocument::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->orderBy('issued_at')
            ->orderBy('number');

        if ($from !== null) {
            $query->whereDate('issued_at', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->whereDate('issued_at', '<=', $to->toDateString());
        }

        /** @var array<string, BigDecimal> $running */
        $running = [];

        foreach ($opening as $currency => $amount) {
            $running[$currency] = Decimal::of($amount);
        }

        $entries = [];

        foreach ($query->get() as $document) {
            $currency = $document->currency->value;
            $signed = Decimal::of((string) $document->amount)->multipliedBy($document->type->sign());

            $running[$currency] = ($running[$currency] ?? BigDecimal::zero())->plus($signed);

            $entries[] = [
                'id' => $document->id,
                'number' => $document->number,
                'type' => $document->type->value,
                'issued_at' => $document->issued_at->toDateString(),
                'description' => $document->description,
                'counterparty' => $document->counterparty,
                'currency' => $currency,
                'amount' => (string) $document->amount,
                'signed_amount' => Decimal::money($signed),
                'balance' => Decimal::money($running[$currency]),
                'is_reversal' => $document->isReversal(),
                'documents_existing_record' => ! $document->isStandalone(),
            ];
        }

        return [
            'opening_balances' => $opening,
            'entries' => $entries,
            'closing_balances' => array_map(
                static fn (BigDecimal $value): string => Decimal::money($value),
                $running,
            ),
        ];
    }

    /**
     * @return array<string, string> currency => balance
     */
    private function balancesUpTo(string $ownerId, CarbonImmutable $before): array
    {
        /** @var array<string, BigDecimal> $balances */
        $balances = [];

        $documents = CashDocument::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereDate('issued_at', '<', $before->toDateString())
            ->get(['type', 'amount', 'currency']);

        foreach ($documents as $document) {
            $currency = $document->currency->value;
            $signed = Decimal::of((string) $document->amount)->multipliedBy($document->type->sign());

            $balances[$currency] = ($balances[$currency] ?? BigDecimal::zero())->plus($signed);
        }

        return array_map(static fn (BigDecimal $value): string => Decimal::money($value), $balances);
    }
}
