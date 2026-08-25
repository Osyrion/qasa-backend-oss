<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;

/**
 * An account that can describe itself as a supplier.
 *
 * Implemented by the edition's User model. It exists so that a document
 * renderer or a tax-filing builder can depend on the *profile* rather than on
 * the account aggregate that happens to store it — see SupplierProfile.
 */
interface ProvidesSupplierProfile
{
    public function supplierProfile(): SupplierProfile;
}
