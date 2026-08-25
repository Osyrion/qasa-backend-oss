<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientVatVerification;
use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Domain\ValueObjects\ClientVatStatus;
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
        private ClientVatVerification $clients,
    ) {}

    /**
     * @throws DomainException
     */
    public function ensureVerified(string $clientId, string $ownerId): void
    {
        $status = $this->clients->requireStatus($clientId, $ownerId);

        $result = $this->validator->verify($status->country, (string) $status->vatId);

        if ($result === null) {
            if ($this->withinGraceWindow($status)) {
                return;
            }

            throw DomainException::because(__('invoicing.eu_rc_requires_vies'));
        }

        if (! $result->valid) {
            throw DomainException::because(__('invoicing.eu_rc_requires_vies'));
        }

        $this->clients->markVerified($clientId, $ownerId);
    }

    private function withinGraceWindow(ClientVatStatus $status): bool
    {
        if ($status->verifiedAt === null) {
            return false;
        }

        $graceDays = (int) config('qasa.vies_grace_days', 30);

        return $status->verifiedAt->greaterThan(now()->subDays($graceDays));
    }
}
