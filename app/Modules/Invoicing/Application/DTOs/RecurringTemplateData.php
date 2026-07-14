<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\RecurringPeriod;
use App\Modules\Invoicing\Domain\Enums\TaxDateMode;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class RecurringTemplateData extends Data
{
    /**
     * @param  list<RecurringTemplateItemData>  $items
     */
    public function __construct(
        public readonly string $name,
        public readonly string $client_id,
        public readonly RecurringPeriod $period,
        public readonly string $first_issue_date,
        public readonly Currency $currency,
        public readonly array $items,

        public readonly int $day_of_month = 1,
        public readonly bool $last_day_of_month = false,
        public readonly ?string $end_date = null,
        public readonly InvoiceType $type = InvoiceType::Invoice,
        public readonly int $due_days = 14,
        public readonly ?float $discount_percent = null,
        public readonly bool $reverse_charge = false,
        public readonly TaxDateMode $tax_date_mode = TaxDateMode::IssueDate,
        public readonly bool $auto_send = false,
        public readonly ?string $note_above = null,
        public readonly ?string $note_below = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?string $userId = null, ?string $country = null): array
    {
        $itemRules = [];
        foreach (RecurringTemplateItemData::rules($userId, $country) as $field => $rule) {
            $itemRules["items.*.{$field}"] = $rule;
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'uuid'],
            'period' => ['required', Rule::enum(RecurringPeriod::class)],
            'day_of_month' => ['required_unless:last_day_of_month,true', 'integer', 'between:1,28'],
            'last_day_of_month' => ['sometimes', 'boolean'],
            'first_issue_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:first_issue_date'],
            'type' => ['sometimes', Rule::in([InvoiceType::Invoice->value, InvoiceType::Proforma->value])],
            'currency' => ['required', Rule::enum(Currency::class)],
            'due_days' => ['required', 'integer', 'between:0,365'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reverse_charge' => ['sometimes', 'boolean'],
            'tax_date_mode' => ['sometimes', Rule::enum(TaxDateMode::class)],
            'auto_send' => ['sometimes', 'boolean'],
            'note_above' => ['nullable', 'string', 'max:2000'],
            'note_below' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            ...$itemRules,
        ];
    }

    public static function fromRequest(Request $request): self
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $request->input('items', []);

        return new self(
            name: $request->string('name')->toString(),
            client_id: $request->string('client_id')->toString(),
            period: RecurringPeriod::from($request->string('period')->toString()),
            first_issue_date: $request->string('first_issue_date')->toString(),
            currency: Currency::from($request->string('currency')->toString()),
            items: array_map(RecurringTemplateItemData::fromArray(...), array_values($items)),
            day_of_month: $request->integer('day_of_month', 1),
            last_day_of_month: $request->boolean('last_day_of_month'),
            end_date: $request->filled('end_date') ? $request->string('end_date')->toString() : null,
            type: $request->filled('type')
                ? InvoiceType::from($request->string('type')->toString())
                : InvoiceType::Invoice,
            due_days: $request->integer('due_days', 14),
            discount_percent: $request->filled('discount_percent') ? (float) $request->input('discount_percent') : null,
            reverse_charge: $request->boolean('reverse_charge'),
            tax_date_mode: $request->filled('tax_date_mode')
                ? TaxDateMode::from($request->string('tax_date_mode')->toString())
                : TaxDateMode::IssueDate,
            auto_send: $request->boolean('auto_send'),
            note_above: $request->filled('note_above') ? $request->string('note_above')->toString() : null,
            note_below: $request->filled('note_below') ? $request->string('note_below')->toString() : null,
        );
    }
}
