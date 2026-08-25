<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

/**
 * Whether an account owns a record of some kind, asked by a wire type name
 * rather than by a class.
 *
 * The Documents module attaches a file to an invoice, a client, an order —
 * five kinds of record owned by four modules — and used to do it with a match
 * from the wire value to a model class. That map is a morph table of somebody
 * else's aggregates: a premium module holding five core models so it can call
 * `::find()` on them, which is also the wrong direction for the edition
 * boundary, since the core has to keep working with Documents deleted.
 *
 * Turned round, it is the same arrangement as DashboardStatsContributor: each
 * module answers for its own tables, tags itself `linkable.records` in its own
 * provider, and the asking module never learns which class it reached. A new
 * attachable kind is a registration, not a branch in Documents.
 *
 * Deliberately only existence. Nothing reads a linked record back today — the
 * API returns the type and the id it was given — so a "print its name" method
 * would be a contract written for nobody. Add it when a caller wants it.
 */
interface LinkableRecordResolver
{
    /**
     * The wire values this resolver answers for — 'invoice', 'client', …
     *
     * A list rather than one value because ownership runs by module, not by
     * table: Invoicing answers for invoices, supplier invoices and expenses
     * alike, and splitting that into three classes would say nothing extra.
     *
     * @return list<string>
     */
    public function recordTypes(): array;

    /**
     * Whether $ownerId's account has a record of $recordType with this id.
     *
     * Takes the owner explicitly instead of leaning on the global scope: the
     * caller already knows whose account it is acting for, and a resolver that
     * quietly asked `auth()` would answer differently in a queue job.
     */
    public function existsForAccount(string $recordType, string $recordId, string $ownerId): bool;
}
