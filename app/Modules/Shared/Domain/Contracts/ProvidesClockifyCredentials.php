<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * The account's Clockify credentials, for the import that pulls its time
 * entries in.
 *
 * The columns sit on `users` and the importer lives in TimeTracking, which is
 * premium — so the core model cannot be handed a TimeTracking contract to
 * implement (the edition boundary runs one way only) and the contract lands
 * here, in the shared kernel, like every other capability both editions'
 * account models answer.
 *
 * The owner's credentials, never a member's own: one account, one Clockify
 * workspace.
 */
interface ProvidesClockifyCredentials
{
    public function clockifyApiKey(): ?string;

    /** Null falls back to whatever Clockify reports as the active workspace. */
    public function clockifyWorkspaceId(): ?string;
}
