<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Contracts\TaxSystem;
use App\Modules\Taxation\Domain\Enums\TaxResidency;

/**
 * Cross-module boundary for TaxSystemResolver — other modules' Application
 * layers depend on this contract, never the concrete resolver (see
 * tests/Architecture/ModuleBoundariesTest.php).
 */
interface TaxSystemResolverInterface
{
    /**
     * @throws DomainException when the account hasn't completed residency (step 2) yet
     */
    public function forUser(User $user): TaxSystem;

    public function forResidency(TaxResidency $residency): TaxSystem;
}
