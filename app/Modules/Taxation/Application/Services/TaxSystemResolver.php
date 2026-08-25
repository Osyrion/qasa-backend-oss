<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Domain\Contracts\TaxSystem;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use InvalidArgumentException;

/**
 * The single place that picks a TaxSystem for a residency. Every other
 * consumer programs against the TaxSystem contract.
 *
 * Which class serves which residency is wiring, so the map arrives from the
 * provider (the composition root) rather than being named here — naming
 * SkTaxSystem and CzTaxSystem is what made an Application service depend on
 * Infrastructure. The completeness check replaces what the `match` used to
 * give for free: a residency with no system now fails at boot, in every
 * environment, instead of throwing UnhandledMatchError at request time.
 */
class TaxSystemResolver implements TaxSystemResolverInterface
{
    /** @var array<string, TaxSystem> */
    private readonly array $systems;

    /**
     * @param  array<string, TaxSystem>  $systems  keyed by TaxResidency->value
     */
    public function __construct(array $systems)
    {
        $missing = array_diff(
            array_map(static fn (TaxResidency $r): string => $r->value, TaxResidency::cases()),
            array_keys($systems),
        );

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'No TaxSystem registered for residency: '.implode(', ', $missing)
            );
        }

        $this->systems = $systems;
    }

    /**
     * @throws DomainException when the account hasn't completed residency (step 2) yet
     */
    public function forSupplier(SupplierProfile $supplier): TaxSystem
    {
        if ($supplier->country === null) {
            throw DomainException::because(__('taxation.residency_required'));
        }

        return $this->forResidency(TaxResidency::from($supplier->country));
    }

    public function forResidency(TaxResidency $residency): TaxSystem
    {
        return $this->systems[$residency->value];
    }
}
