<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Gates issuance of an intra-EU reverse-charge invoice on the client's VAT ID
 * being verified via VIES. A successful check stamps client.vat_verified_at,
 * which then serves as a fallback while VIES is unreachable — but only
 * within a grace window, and never for a number VIES actively rejects.
 */
readonly class ViesPreconditionService
{
    public function __construct(
        private VatValidatorInterface $validator,
    ) {}

    /**
     * @throws DomainException
     */
    public function ensureVerified(Client $client): void
    {
        $result = $this->validator->verify($client->country, (string) $client->vat_id);

        if ($result === null) {
            if ($this->withinGraceWindow($client)) {
                return;
            }

            throw DomainException::because(__('invoicing.eu_rc_requires_vies'));
        }

        if (! $result->valid) {
            throw DomainException::because(__('invoicing.eu_rc_requires_vies'));
        }

        $client->forceFill(['vat_verified_at' => now()])->save();
    }

    private function withinGraceWindow(Client $client): bool
    {
        if ($client->vat_verified_at === null) {
            return false;
        }

        $graceDays = (int) config('qasa.vies_grace_days', 30);

        return $client->vat_verified_at->greaterThan(now()->subDays($graceDays));
    }
}
