<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\Enums;

/**
 * What another module can ask permission for on a client.
 *
 * The same shape as InvoiceAbility, for the same reason: the Gate finds a
 * policy by model class, so a module that must not name `Client` cannot use
 * it. Clients' own controllers keep calling `$this->authorize('update',
 * $client)` — they hold the model legitimately.
 */
enum ClientAbility: string
{
    case View = 'view';
    case Update = 'update';
}
