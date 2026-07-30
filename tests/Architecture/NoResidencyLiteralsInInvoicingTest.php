<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Invoicing must never branch on a hardcoded 'SK'/'CZ' literal for tax
 * residency — that's exactly the coupling the Taxation module (Sk/CzTaxSystem,
 * TaxResidency enum) exists to remove. Country-code literals that are NOT
 * about tenant residency (an IBAN's ISO prefix, a client/vendor's own
 * country, a bank account's country) are legitimate and allowlisted below,
 * each with the reason it isn't a residency branch.
 *
 * @return array<string, string> file path relative to app/Modules/Invoicing => reason
 */
function residencyLiteralAllowlist(): array
{
    return [
        // The plan's explicit exception: a CLIENT can be from any country
        // (including SK/CZ, among DE/AT/PL/HU/...) — this is not tenant
        // residency branching, see App\Modules\Taxation's Sk/CzTaxLabels
        // for the actual supplier/residency-driven labels.
        'Domain/Services/ClientTaxLabelMap.php' => 'client country can be anything, not tenant residency',

        // 'CZ' here is the ISO 3166-1 country prefix being written into the
        // IBAN string being constructed — intrinsic to the IBAN format
        // itself, not a residency check.
        'Domain/Banking/CzechIbanConverter.php' => 'ISO country prefix of the IBAN being built, not tenant residency',

        // Classifies a bank account's own country (SK IBAN / CZ IBAN or
        // domestic / other) — Phase 3's banking layer decides by account +
        // currency, deliberately never by tenant residency.
        'Domain/Banking/BankAccountIdentity.php' => "classifies a bank account's own country, not tenant residency",
    ];
}

it('does not branch on SK/CZ residency literals in Invoicing — use TaxResidency instead', function (): void {
    $basePath = dirname(__DIR__, 2).'/app/Modules/Invoicing';
    $allowlist = residencyLiteralAllowlist();

    $finder = (new Finder)->files()->in([$basePath.'/Application', $basePath.'/Domain'])->name('*.php');

    $violations = [];

    foreach ($finder as $file) {
        $relativePath = str_replace($basePath.'/', '', (string) $file->getRealPath());

        if (array_key_exists($relativePath, $allowlist)) {
            continue;
        }

        if (preg_match('/([\'"])(SK|CZ)\1/', $file->getContents()) === 1) {
            $violations[] = $relativePath;
        }
    }

    expect($violations)->toBe(
        [],
        "These Invoicing files branch on a raw 'SK'/'CZ' literal — use App\\Modules\\Taxation\\Domain\\Enums\\TaxResidency instead, or add a justified entry to residencyLiteralAllowlist(): ".implode(', ', $violations),
    );
});

it('does not allowlist a file that no longer contains an SK/CZ literal', function (): void {
    $basePath = dirname(__DIR__, 2).'/app/Modules/Invoicing';
    $stale = [];

    foreach (residencyLiteralAllowlist() as $relativePath => $reason) {
        $fullPath = $basePath.'/'.$relativePath;

        if (! file_exists($fullPath)) {
            $stale[] = $relativePath.' (file no longer exists)';

            continue;
        }

        if (preg_match('/([\'"])(SK|CZ)\1/', (string) file_get_contents($fullPath)) !== 1) {
            $stale[] = $relativePath.' (no longer contains an SK/CZ literal)';
        }
    }

    expect($stale)->toBe([], 'Stale allowlist entries — remove them: '.implode(', ', $stale));
});
