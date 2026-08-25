<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\ValueObjects;

use App\Modules\Shared\Domain\ValueObjects\PartyProfile;
use Carbon\CarbonImmutable;

/**
 * What a VAT check needs to know about a client, and nothing else.
 *
 * Deliberately not part of {@see PartyProfile}:
 * that value is the identity *printed on a document* and frozen into
 * `client_snapshot` at issue, while `vat_verified_at` is a live fact about the
 * client record that changes every time VIES is asked again.
 */
final readonly class ClientVatStatus
{
    public function __construct(
        /** ISO 3166-1 alpha-2. */
        public string $country,
        /** IČ DPH / VAT ID, as VIES expects it. */
        public ?string $vatId,
        /** When VIES last confirmed the number, or null if it never has. */
        public ?CarbonImmutable $verifiedAt,
    ) {}
}
