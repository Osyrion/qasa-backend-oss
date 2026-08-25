<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * The account's `{token}@in.<domain>` inbound address.
 *
 * The token is the address: anything sent to it lands in this account's
 * invoice inbox, so enabling and rotating are the same operation (a fresh
 * token every time, which is why there is no "set" taking one from outside).
 *
 * The owner's, always — one account, one inbound address. The endpoint on top
 * of this refuses a team member outright rather than quietly writing their own
 * row, which is what a plain column write would have done.
 */
interface ManagesInboundEmailAddress
{
    /** Null when the account has no inbound address. */
    public function inboundEmailToken(): ?string;

    /**
     * Issue a new token, invalidating the previous address immediately.
     *
     * @return string the token now in force
     */
    public function rotateInboundEmailToken(): string;

    public function clearInboundEmailToken(): void;
}
