<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ExchangeRateData extends Data
{
    public function __construct(
        public readonly Currency $base_currency,
        public readonly Currency $target_currency,
        public readonly float $rate,
        public readonly string $date,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'rate' => ['numeric', 'min:0.000001'],
            'date' => ['date'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            base_currency: Currency::from($request->string('base_currency')->toString()),
            target_currency: Currency::from($request->string('target_currency')->toString()),
            rate: $request->float('rate'),
            date: $request->string('date')->toString(),
        );
    }
}
