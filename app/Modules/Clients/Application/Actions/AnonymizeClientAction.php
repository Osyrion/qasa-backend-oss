<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientAnonymizationContributor;
use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Honours an erasure request for a client who is a natural person
 * (docs/plans/GDPR_COMPLIANCE_PLAN.md, phase 5).
 *
 * DeleteClientAction refuses a client with non-cancelled invoices, and it is
 * right to: the invoice holds a foreign key to the row. So the row stays and
 * everything identifying a person comes off it instead.
 *
 * **This never touches invoices.client_snapshot or quotes.client_snapshot,
 * and that is a decision, not an oversight** (owner, 2026-08-07). An issued
 * invoice is an archived accounting record with its own legal basis under
 * Art. 6(1)(c), which Art. 17(3)(b) exempts from erasure. Rewriting the
 * snapshot would retroactively alter a document that must not change, and
 * would break the VAT control statement, which reads the partner's name and
 * VAT id straight out of it.
 *
 * Drafts are the other side of that line: IssueInvoiceAction freezes the
 * snapshot at issue, so a draft has none and falls back to the live client.
 * A draft is not a document and carries no archival duty, so reading as
 * anonymised is correct — but it is visible, which is why the count comes
 * back to the caller.
 */
readonly class AnonymizeClientAction
{
    /**
     * @param  iterable<ClientAnonymizationContributor>  $contributors  Columns owned by other modules.
     */
    public function __construct(private iterable $contributors = []) {}

    /**
     * @return int Drafts (invoices and quotes) that will now read as anonymised
     *
     * @throws Throwable
     */
    public function execute(Client $client): int
    {
        if ($client->anonymized_at !== null) {
            return $this->affectedDrafts($client);
        }

        $affected = $this->affectedDrafts($client);

        DB::transaction(function () use ($client): void {
            // Nothing has a foreign key to contact_persons, so these can go
            // outright — truer erasure than blanking two NOT NULL columns.
            $client->contactPersons()->delete();

            $client->forceFill([
                ...$this->clearedIdentity(),
                ...$this->tombstoneName($client),
                'anonymized_at' => Date::now(),
                // An anonymised client must not end up on a new document.
                // Archiving is the module's existing word for that, so it
                // needs no new flag of its own.
                'archived_at' => $client->archived_at ?? Date::now(),
            ])->save();

            foreach ($this->contributors as $contributor) {
                $contributor->anonymize($client);
            }
        });

        return $affected;
    }

    /**
     * client_type, country, currency, locale and the is_* flags stay: they
     * describe how the account does business with this row, and the tax
     * reports that read them are about the transaction, not the person.
     *
     * Only columns the *core* clients migration creates. bank_iban,
     * external_id and friends are hung off this table by premium modules and
     * do not exist in the generated OSS core — they are cleared by their own
     * module through ClientAnonymizationContributor.
     *
     * @return array<string, null>
     */
    private function clearedIdentity(): array
    {
        return [
            'title' => null,
            'name' => null,
            'surname' => null,
            'company_name' => null,
            'avatar_path' => null,
            'ico' => null,
            'dic' => null,
            'vat_id' => null,
            'email' => null,
            'phone' => null,
            'address' => null,
            'city' => null,
            'postal_code' => null,
            'note' => null,
        ];
    }

    /**
     * display_name reads a different column per client_type, so the tombstone
     * goes in the one that type actually shows. Filling all of them would
     * render "[anonymised] ([anonymised] )" for a self-employed client.
     *
     * @return array<string, string>
     */
    private function tombstoneName(Client $client): array
    {
        return $client->isIndividual()
            ? ['name' => Client::ANONYMISED]
            : ['company_name' => Client::ANONYMISED];
    }

    private function affectedDrafts(Client $client): int
    {
        return $client->invoices()->whereNull('client_snapshot')->count()
            + $client->quotes()->whereNull('client_snapshot')->count();
    }
}
