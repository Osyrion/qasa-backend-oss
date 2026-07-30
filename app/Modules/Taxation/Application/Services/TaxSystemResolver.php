<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Domain\Contracts\TaxSystem;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Infrastructure\Cz\CzTaxSystem;
use App\Modules\Taxation\Infrastructure\Sk\SkTaxSystem;

/**
 * The single place that picks between SkTaxSystem and CzTaxSystem. Every
 * other consumer programs against the TaxSystem contract.
 */
class TaxSystemResolver implements TaxSystemResolverInterface
{
    public function __construct(
        private readonly SkTaxSystem $skTaxSystem,
        private readonly CzTaxSystem $czTaxSystem,
    ) {}

    /**
     * @throws DomainException when the account hasn't completed residency (step 2) yet
     */
    public function forUser(User $user): TaxSystem
    {
        if ($user->country === null) {
            throw DomainException::because(__('taxation.residency_required'));
        }

        return $this->forResidency(TaxResidency::from($user->country));
    }

    public function forResidency(TaxResidency $residency): TaxSystem
    {
        return match ($residency) {
            TaxResidency::Sk => $this->skTaxSystem,
            TaxResidency::Cz => $this->czTaxSystem,
        };
    }
}
