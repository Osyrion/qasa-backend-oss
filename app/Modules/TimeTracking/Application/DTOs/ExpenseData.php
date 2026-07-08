<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use App\Modules\TimeTracking\Domain\Enums\ExpenseCategory;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class ExpenseData extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly ExpenseCategory $category,
        public readonly float $amount,
        public readonly Currency $currency,
        public readonly string $date,

        #[Nullable]
        public readonly ?string $note = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'amount' => ['numeric', 'min:0.01'],
            'date' => ['date'],
        ];
    }
}
