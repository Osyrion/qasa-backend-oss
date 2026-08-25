<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\ValueObjects;

/**
 * How an import decides that a row it is reading is a client the account
 * already has.
 *
 * Three tiers, tried in order and deliberately not merged into one query: the
 * external id is an exact identity from the source system, the IČO is an exact
 * identity from the state, and the name is a guess — so a name match must
 * never win over an identifier that disagrees with it.
 */
final readonly class ClientMatchCriteria
{
    public function __construct(
        /** The importing driver, e.g. 'superfaktura'. */
        public string $source,
        public ?string $externalId,
        public ?string $ico,
        /** Company name, or "first last" — matched case-insensitively. */
        public ?string $displayName,
        /** Narrows a name match only; ignored by the identifier tiers. */
        public ?string $city,
    ) {}
}
