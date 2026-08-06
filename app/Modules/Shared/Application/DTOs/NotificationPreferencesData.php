<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\DTOs;

use Spatie\LaravelData\Data;

/**
 * Validation only, same reason as AutomationSettingsData: a partial update
 * needs to distinguish "field omitted" from "field explicitly sent", which a
 * constructed Data object with defaulted values can't represent.
 */
class NotificationPreferencesData extends Data
{
    public function __construct(
        public readonly ?bool $notify_invoice_enabled = null,
        public readonly ?bool $notify_quote_enabled = null,
        public readonly ?bool $notify_tax_enabled = null,
        public readonly ?bool $notify_billing_enabled = null,
        public readonly ?bool $notify_banking_enabled = null,
        public readonly ?bool $notify_system_enabled = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'notify_invoice_enabled' => ['sometimes', 'boolean'],
            'notify_quote_enabled' => ['sometimes', 'boolean'],
            'notify_tax_enabled' => ['sometimes', 'boolean'],
            'notify_billing_enabled' => ['sometimes', 'boolean'],
            'notify_banking_enabled' => ['sometimes', 'boolean'],
            'notify_system_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
