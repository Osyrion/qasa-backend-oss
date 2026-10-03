<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObjects\PeppolParticipantId;

/**
 * The address a business is reachable at on the Peppol network.
 *
 * Pinned here rather than left to the two regexes that used to hold it —
 * `ClientData` (core, for a counterparty) and `PeppolCredentialData` (premium,
 * for ourselves) — because they validate shape and nothing else, and every
 * genuinely dangerous mistake with a participant identifier passes a shape
 * check: the right format over the wrong scheme routes a legal document to
 * an address nobody reads, and reports success.
 */
it('parses a well-formed identifier', function (): void {
    $id = PeppolParticipantId::parse('0245:1020304050');

    expect($id)->not->toBeNull()
        ->and($id?->scheme)->toBe('0245')
        ->and($id?->value)->toBe('1020304050')
        ->and($id?->toString())->toBe('0245:1020304050');
});

it('refuses anything that is not scheme:value', function (?string $input): void {
    expect(PeppolParticipantId::parse($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'no scheme' => ['1020304050'],
    'scheme too short' => ['024:1020304050'],
    'scheme too long' => ['02455:1020304050'],
    'non-numeric scheme' => ['SK45:1020304050'],
    'empty value' => ['0245:'],
    'space in value' => ['0245:102 304050'],
    'value too long' => ['0245:'.str_repeat('9', 51)],
    'only a colon' => [':'],
]);

it('treats the identifier as case-insensitive but keeps what was written', function (): void {
    $lower = PeppolParticipantId::parse('9915:test');
    $upper = PeppolParticipantId::parse('9915:TEST');

    expect($lower?->equals($upper))->toBeTrue()
        ->and($upper?->toString())->toBe('9915:TEST');
});

/**
 * The regression that matters most in this file.
 *
 * The DNS label is how a lookup finds a participant at all, and the algorithm
 * changed: `B-<hex(md5)>` resolved as a CNAME is dead, replaced by
 * base32(SHA-256) resolved as a NAPTR. An implementation still computing the
 * old one finds nothing — which is indistinguishable, on every screen we own,
 * from "this customer never registered".
 *
 * The vector is the one in Peppol's own CNAME-to-NAPTR migration document, so
 * this test fails if anybody ever "simplifies" the hash.
 */
it('builds the documented DNS label', function (): void {
    expect(PeppolParticipantId::parse('9915:test')?->dnsLabel())
        ->toBe('eh5boavaktmbgzyh2a63dz4qov33fvp5nsdvqklucfraayoodw6a');
});

it('hashes case-insensitively, so the label does not depend on how it was typed', function (): void {
    expect(PeppolParticipantId::parse('9915:TEST')?->dnsLabel())
        ->toBe(PeppolParticipantId::parse('9915:test')?->dnsLabel());
});

it('derives a Slovak identifier from the tax number, without the country prefix', function (string $dic): void {
    $id = PeppolParticipantId::forAccount('SK', ico: '51234567', dic: $dic);

    // 0245 is "Tax identification number (DIČ), Slovakia" in the Peppol code
    // list — over the bare number, because the scheme already says Slovakia.
    expect($id?->toString())->toBe('0245:1020304050');
})->with([
    'bare' => ['1020304050'],
    'prefixed' => ['SK1020304050'],
    'lowercase prefix' => ['sk1020304050'],
    'spaced' => ['SK 1020304050'],
]);

/**
 * 0158, not 0060 and not 9928.
 *
 * Worth spelling out because both of the plausible-looking alternatives are
 * real codes for something else entirely: 0060 is D-U-N-S, 9928 is *Cyprus*
 * VAT. Either would produce a perfectly well-formed identifier pointing at an
 * address in the wrong registry, and nothing downstream would object.
 */
it('derives a Czech identifier from the ICO', function (): void {
    expect(PeppolParticipantId::forAccount('CZ', ico: '12345678', dic: 'CZ12345678')?->toString())
        ->toBe('0158:12345678');
});

it('has no opinion about a country it was not configured for', function (): void {
    expect(PeppolParticipantId::forAccount('DE', ico: '12345678', dic: 'DE123456789'))->toBeNull();
});

it('cannot derive an identifier when the source number is missing', function (): void {
    expect(PeppolParticipantId::forAccount('SK', ico: '51234567', dic: null))->toBeNull()
        ->and(PeppolParticipantId::forAccount('CZ', ico: null, dic: 'CZ12345678'))->toBeNull()
        ->and(PeppolParticipantId::forAccount('SK', ico: null, dic: '  '))->toBeNull();
});

it('reads the scheme map from config rather than deciding it in code', function (): void {
    // The Peppol code list is amended, and a country's scheme is exactly the
    // kind of fact that moves without anybody telling us. It moves in config.
    config()->set('invoicing.peppol.participant_schemes.CZ', [
        ['scheme' => '9929', 'source' => 'vat_id', 'strip_prefix' => null],
    ]);

    expect(PeppolParticipantId::forAccount('CZ', ico: '12345678', dic: 'CZ12345678', vatId: 'CZ12345678')?->toString())
        ->toBe('9929:CZ12345678');
});

/**
 * A country can have more than one plausible address, and for Czechia we do not
 * know which one a provider will actually publish a subject under — 0158 over
 * the IČO and 9929 over the VAT number are both real Peppol codes for Czech
 * businesses.
 *
 * Guessing is the one thing that must not happen: a wrong scheme produces a
 * perfectly well-formed identifier pointing at an address nobody reads, and
 * nothing downstream objects. So the country offers *candidates*, and which one
 * is real becomes an observation rather than a configuration decision.
 */
it('offers every plausible address for a country, most likely first', function (): void {
    $candidates = PeppolParticipantId::candidatesForAccount('CZ', ico: '12345678', dic: 'CZ12345678', vatId: 'CZ12345678');

    expect(array_map(fn (PeppolParticipantId $id): string => $id->toString(), $candidates))
        ->toBe(['0158:12345678', '9929:CZ12345678']);
});

it('has exactly one candidate where the scheme is not in doubt', function (): void {
    // Slovakia is settled: 0245 over the DIČ, verified against the code list.
    // A second lookup per check would be a round trip bought for nothing.
    expect(PeppolParticipantId::candidatesForAccount('SK', ico: '51234567', dic: 'SK1020304050'))
        ->toHaveCount(1);
});

it('skips a candidate whose source number the account does not have', function (): void {
    // No VAT number, so the 9929 candidate cannot be built — and an identifier
    // assembled from a missing field would be an invented address.
    $candidates = PeppolParticipantId::candidatesForAccount('CZ', ico: '12345678', dic: null, vatId: null);

    expect(array_map(fn (PeppolParticipantId $id): string => $id->toString(), $candidates))
        ->toBe(['0158:12345678']);
});

it('still names one primary candidate, for the screen that shows an address', function (): void {
    // `forAccount()` is what the wizard prints before anything is registered.
    // It is the most likely address, not a confirmed one.
    expect(PeppolParticipantId::forAccount('CZ', ico: '12345678', dic: 'CZ12345678', vatId: 'CZ12345678')?->toString())
        ->toBe('0158:12345678');
});
