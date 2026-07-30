<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class ContributionPaymentData extends Data
{
    public function __construct(
        public readonly ContributionType $type,
        public readonly int $period_year,
        public readonly float $amount,
        public readonly Currency $currency,
        public readonly string $paid_at,

        #[Nullable]
        public readonly ?int $period_month = null,

        #[Nullable]
        public readonly ?string $note = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'period_year' => ['integer', 'min:2000', 'max:2100'],
            'period_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'amount' => ['numeric', 'min:0.01'],
            'paid_at' => ['date'],
        ];
    }
}
