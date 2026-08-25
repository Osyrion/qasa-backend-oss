<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * OSS core default: no plans, no metering — every call succeeds. Mirrors
 * User::hasFeature()/withinLimit()'s "core edition is never limited" stance.
 */
final class UnlimitedUsageQuota implements UsageQuotaInterface
{
    public function remaining(Account&ProvidesPlanEntitlements $owner, string $feature): int
    {
        return PHP_INT_MAX;
    }

    public function consume(Account&ProvidesPlanEntitlements $owner, string $feature, int $amount = 1): bool
    {
        return true;
    }

    public function refund(Account&ProvidesPlanEntitlements $owner, string $feature, int $amount = 1): void {}
}
