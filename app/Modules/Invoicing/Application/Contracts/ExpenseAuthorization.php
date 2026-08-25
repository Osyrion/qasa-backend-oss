<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Actor;

/**
 * May this person record an expense?
 *
 * Only the class-level ability, because that is the only one asked: suggesting
 * a category is a step in the create flow, run before there is an expense to
 * authorise against. The Gate keys on the model class, which the module doing
 * the suggesting must not name.
 */
interface ExpenseAuthorization
{
    public function allowsCreate(Actor $actor): bool;
}
