<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Rules;

use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * KvDphXmlBuilder/DphKh1XmlBuilder/DphXmlBuilder always require a period
 * scope — a control statement or VAT return has no "whole year" variant,
 * unlike the EU sales list, which happily defaults to the full year when
 * neither is given.
 */
final class RequiresPeriodScope implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, [TaxFilingType::ControlStatement->value, TaxFilingType::VatReturn->value], true)) {
            return;
        }

        if (($this->data['period_quarter'] ?? null) === null && ($this->data['period_month'] ?? null) === null) {
            $fail(__('taxation.control_statement_requires_period'));
        }
    }
}
