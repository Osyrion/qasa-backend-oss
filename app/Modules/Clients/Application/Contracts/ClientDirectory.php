<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Shared\Domain\ValueObjects\PartyProfile;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * How another module asks about a client.
 *
 * Two questions came up over and over, each answered by a hand-rolled
 * `Client::query()` in the asking module — which is how eight modules ended up
 * querying the `clients` table directly:
 *
 *   1. "print who these ids are" (a report, a statistics table)
 *   2. "does this account have any client at all?" (onboarding checklist)
 *
 * Neither needs the aggregate, and neither should be re-deciding which global
 * scope to drop.
 *
 * The document paths ask a third and a fourth, and the two are deliberately
 * different: issuing a *new* document refuses an archived client, while
 * updating an existing one does not — the document already names it, and
 * archiving is not meant to freeze the past. Merging them into one "is this
 * client ok" would quietly change one of the two.
 */
interface ClientDirectory
{
    /**
     * The client a new document is being issued to.
     *
     * Both failure modes keep the status code they had when every caller
     * wrote this out by hand: an id the account does not own was a
     * `findOrFail()` (404), an archived or plan-blocked one a DomainException
     * (422).
     *
     * @throws ModelNotFoundException when $ownerId does not own $clientId
     * @throws DomainException when the client is archived, or the plan no longer covers it
     */
    public function requireForNewDocument(string $clientId, string $ownerId): PartyProfile;

    /**
     * Plan-limit check alone, for updating a document that already names the
     * client. Deliberately silent about archiving — see the class docblock —
     * and a no-op for an id the account does not own, which the caller's own
     * lookup rejects.
     *
     * @throws DomainException when the plan no longer covers the client
     */
    public function assertWithinPlanLimits(?string $clientId): void;

    /**
     * Archiving *and* plan-limit check for a client something new is about to
     * be attached to, where the caller's own validation has already decided
     * what an unknown id means — an id that resolves to nothing is a no-op
     * here rather than a 404.
     *
     * @throws DomainException when the client is archived, or the plan no longer covers it
     */
    public function assertUsableForNewRecord(?string $clientId): void;

    /**
     * The client, if the account owns it — no archiving or plan check.
     *
     * For the update paths, which need the client's tax details to recompute a
     * document that already names it.
     *
     * @throws ModelNotFoundException when $ownerId does not own $clientId
     */
    public function requireOwnedProfile(string $clientId, string $ownerId): PartyProfile;

    /** Whether $ownerId owns $clientId. Each caller keeps its own error shape. */
    public function existsForAccount(string $clientId, string $ownerId): bool;

    /**
     * The same question for the *authenticated* account, decided by the global
     * scope rather than by an id the caller had to resolve first.
     */
    public function existsForCurrentAccount(string $clientId): bool;

    /**
     * @param  iterable<int, string|null>  $clientIds
     * @return array<string, PartyProfile> keyed by client id
     */
    public function profilesFor(iterable $clientIds): array;

    /**
     * As profilesFor(), but reaching across accounts and including clients
     * that have since been deleted — a statistics table covering a past period
     * still has to print who the counterparty was.
     *
     * @param  iterable<int, string|null>  $clientIds
     * @return array<string, PartyProfile> keyed by client id
     */
    public function profilesIncludingDeleted(iterable $clientIds): array;

    /**
     * The client's Peppol participant id, or null when they have none.
     *
     * Not on `PartyProfile`: that value object is the identity *printed on a
     * document*, frozen into `client_snapshot` at issue, and a participant id
     * is neither printed nor frozen — the counterparty may register with the
     * network long after the invoice was raised, and the send has to use the
     * address they have today, not the one they had then.
     *
     * @throws ModelNotFoundException when $ownerId does not own $clientId
     */
    public function requirePeppolId(string $clientId, string $ownerId): ?string;

    /** Whether the account has at least one client — the onboarding checklist. */
    public function accountHasAny(string $ownerId): bool;

    /** How many clients the account has — the dashboard tile. */
    public function countForAccount(string $ownerId): int;
}
