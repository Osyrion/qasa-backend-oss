<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Rules;

use App\Modules\Shared\Support\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! WebhookUrlGuard::isSafe($value)) {
            $fail(__('integrations.unsafe_webhook_url'));
        }
    }
}
