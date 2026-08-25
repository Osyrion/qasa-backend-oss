<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * Every capability the edition's account model is guaranteed to provide,
 * named once.
 *
 * The narrow contracts are the point of this whole exercise and stay the
 * default: a signature asks for exactly what it reads, so a reader can tell
 * from the type what the body will touch. But two kinds of code do not read
 * anything — they *hand the account onward*: the `user` relation every
 * tenant-scoped record has (HasUserScope) and the by-id lookup console
 * commands use (AccountLocator). Those had to enumerate the intersection, and
 * every new contract made both signatures longer without making either
 * clearer. Same reasoning as Actor, one level up: writing the intersection
 * out at every forwarding point would say the same thing many times.
 *
 * Use it only where the account is being passed through. Taking it as a
 * parameter says "I might read anything", which for a specific action is
 * exactly the claim the narrow contracts exist to avoid.
 *
 * Deliberately not extended: Taxation's ProvidesVatFilingSettings. The shared
 * kernel does not reach into a module, and the one command that needs the
 * filing frequency narrows to it itself.
 */
interface FullAccount extends Account, HasLocalePreference, MustVerifyEmail, ProvidesAccountStatus, ProvidesAiPreferences, ProvidesInvoiceNumbering, ProvidesInvoicingPreferences, ProvidesNotificationPreferences, ProvidesPlanEntitlements, ProvidesSupplierProfile {}
