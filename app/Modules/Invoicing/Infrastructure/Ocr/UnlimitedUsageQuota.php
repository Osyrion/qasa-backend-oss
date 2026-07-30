<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;

/**
 * OSS core default: no plans, no metering — every call succeeds. Mirrors
 * User::hasFeature()/withinLimit()'s "core edition is never limited" stance.
 */
final class UnlimitedUsageQuota implements UsageQuotaInterface
{
    public function remaining(User $owner, string $feature): int
    {
        return PHP_INT_MAX;
    }

    public function consume(User $owner, string $feature, int $amount = 1): bool
    {
        return true;
    }

    public function refund(User $owner, string $feature, int $amount = 1): void {}
}
