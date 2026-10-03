<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

/**
 * The address a business is reachable at on the Peppol network — a scheme and
 * an identifier, e.g. `0245:1020304050`.
 *
 * In `Shared` rather than in `Integrations`, even though the transport is
 * premium, because both editions need it: `clients.peppol_id` is a column on a
 * **core** table, so core has to validate and render a counterparty's address
 * whether or not this build can send anything to it. The rule the edition
 * boundary actually draws is format in core, transport in premium, and an
 * address is format.
 *
 * The scheme is the dangerous half. Every wrong-scheme mistake produces a
 * perfectly well-formed identifier — it passes validation, renders in the UI,
 * and is accepted by an access point — that names an address in the wrong
 * registry, where nobody is listening. Two codes that look like plausible
 * guesses for a Czech business are `0060` (D-U-N-S, a global commercial
 * number) and `9928` (**Cyprus** VAT). Neither is Czech. That is why the map
 * lives in configuration next to a comment saying where it came from, and why
 * it is not derived from the country code by any rule in code.
 */
final readonly class PeppolParticipantId
{
    /**
     * Four digits, a colon, then the identifier.
     *
     * Published so `ClientData` and `PeppolCredentialData` validate against
     * the same expression this class parses — they each carried their own
     * copy, which is two places for it to drift from one meaning.
     */
    public const string PATTERN = '/^[0-9]{4}:[A-Za-z0-9._~\-]{1,50}$/';

    private function __construct(
        /** Peppol EAS/ICD code, e.g. "0245" for a Slovak DIČ. */
        public string $scheme,
        public string $value,
    ) {}

    public static function parse(?string $identifier): ?self
    {
        $identifier = trim((string) $identifier);

        if (preg_match(self::PATTERN, $identifier) !== 1) {
            return null;
        }

        [$scheme, $value] = explode(':', $identifier, 2);

        return new self($scheme, $value);
    }

    /**
     * The address this account most likely publishes itself under.
     *
     * The first of {@see candidatesForAccount()} — what the wizard prints on
     * screen before anything has been registered. Most likely, not confirmed:
     * for a country whose scheme is settled they are the same thing, and for
     * one where it is not, only the network can say.
     *
     * Returns null rather than guessing when the country is not configured or
     * the source number is missing: an address invented from an incomplete
     * profile is worse than no address, because the flow that receives one
     * stops asking for the real thing.
     */
    public static function forAccount(?string $country, ?string $ico, ?string $dic, ?string $vatId = null): ?self
    {
        return self::candidatesForAccount($country, $ico, $dic, $vatId)[0] ?? null;
    }

    /**
     * Every address this account could plausibly be published under, most
     * likely first.
     *
     * A country can have more than one, and for Czechia we genuinely do not
     * know which a provider will use: 0158 over the IČO and 9929 over the VAT
     * number are both real Peppol codes for a Czech business, and the provider
     * that registers the subject decides. Slovakia has one, verified, so the
     * list is one long and costs nothing.
     *
     * Offering candidates rather than picking is the whole point. A wrong
     * scheme produces a perfectly well-formed identifier — it validates, it
     * renders, an access point accepts it — that names an address in the wrong
     * registry where nobody is listening. Guessing has no failure mode that
     * announces itself, so the guess is replaced by a lookup: whichever
     * candidate the network answers on is the real one.
     *
     * @return list<self>
     */
    public static function candidatesForAccount(?string $country, ?string $ico, ?string $dic, ?string $vatId = null): array
    {
        // Typed as mixed rather than as the shape it should have: this is a
        // config file an operator edits, and a malformed entry there should
        // skip a candidate rather than fatal on a country nobody was asking
        // about.
        /** @var mixed $rules */
        $rules = config('invoicing.peppol.participant_schemes.'.strtoupper((string) $country));

        if (! is_array($rules)) {
            return [];
        }

        $candidates = [];

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_string($rule['scheme'] ?? null) || ! is_string($rule['source'] ?? null)) {
                continue;
            }

            $stripPrefix = $rule['strip_prefix'] ?? null;

            $raw = match ($rule['source']) {
                'ico' => $ico,
                'dic' => $dic,
                'vat_id' => $vatId,
                default => null,
            };

            $value = self::normalise($raw, is_string($stripPrefix) ? $stripPrefix : null);

            // A candidate whose source number the account does not have is not
            // a weaker candidate, it is not one at all — an identifier
            // assembled from a missing field is an invented address.
            if ($value === null) {
                continue;
            }

            $candidate = self::parse($rule['scheme'].':'.$value);

            if ($candidate instanceof self) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    public function toString(): string
    {
        return $this->scheme.':'.$this->value;
    }

    /**
     * Peppol identifiers are case-insensitive, so two spellings of one address
     * are one address. Comparing the strings instead is how a lookup for
     * `9915:TEST` decides a participant registered as `9915:test` does not
     * exist.
     */
    public function equals(?self $other): bool
    {
        return $other instanceof self && strcasecmp($this->toString(), $other->toString()) === 0;
    }

    /**
     * The DNS label a participant is published under in the SML.
     *
     * ```
     * base32(sha256(lowercase("<scheme>:<value>")))   padding stripped, lowercased
     * ```
     *
     * Two details here are load-bearing and both are recent changes:
     *
     * - **It is not MD5, and it is not a CNAME.** The original scheme was
     *   `B-<hex(md5(id))>` resolved as a CNAME; Peppol replaced it with this
     *   one (CNAME to NAPTR migration, 2025-04) and the old form no longer
     *   resolves. Base32 rather than hex because a SHA-256 written in hex is
     *   64 characters and a DNS label may hold 63.
     * - **A wrong hash is silent.** It does not error; it finds nothing, which
     *   every screen downstream renders as "this participant is not
     *   registered". `PeppolParticipantIdTest` pins the vector from Peppol's
     *   own migration document for exactly that reason.
     *
     * The zone this label is prefixed to is *not* here — it moved from the
     * European Commission to OpenPeppol and is configuration.
     */
    public function dnsLabel(): string
    {
        $digest = hash('sha256', strtolower($this->toString()), binary: true);

        return strtolower(rtrim(self::base32($digest), '='));
    }

    /**
     * Strip the country prefix a tax number is usually written with, plus the
     * spacing people type.
     *
     * `SK1020304050` and `1020304050` are the same taxpayer, and the scheme
     * code already says which country — carrying the prefix as well would
     * publish an address nobody looks up.
     */
    private static function normalise(?string $raw, ?string $stripPrefix): ?string
    {
        $value = preg_replace('/\s+/u', '', (string) $raw) ?? '';

        if ($stripPrefix !== null && $stripPrefix !== '' && stripos($value, $stripPrefix) === 0) {
            $value = substr($value, strlen($stripPrefix));
        }

        return $value === '' ? null : $value;
    }

    /**
     * RFC 4648 base32. Hand-rolled because PHP ships base64 and hex but not
     * this, and pulling a package in for thirteen lines used in one place
     * would be the larger dependency.
     */
    private static function base32(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // RFC 4648 pads to a multiple of eight characters. Peppol strips the
        // padding again, but producing it here keeps this a base32 encoder
        // rather than a Peppol-shaped approximation of one.
        return str_pad($encoded, (int) (ceil(strlen($encoded) / 8) * 8), '=');
    }
}
