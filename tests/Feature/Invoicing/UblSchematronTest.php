<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/UblFixtures.php';

/**
 * The official Schematrons, not just the XSD — two of them:
 *
 *   en16931  ConnectingEurope/eInvoicing-EN16931 1.3.16, the European norm.
 *   peppol   OpenPEPPOL PEPPOL-EN16931-UBL v3.0.20, layered on top.
 *
 * Together they turn "built to EN 16931" into "conforms to EN 16931 *and*
 * will be accepted by an access point". The XSD is far looser than the norm,
 * and the norm is looser than Peppol — a document can clear each of those and
 * still be rejected by the next one up.
 *
 * The Peppol ruleset is the one that matters commercially from 2027: we
 * already stamp ProfileID as Peppol BIS Billing 3.0 on every export, which is
 * a claim about the document, and a claim nobody had checked.
 *
 * Skipped when Java is absent. A compiled Schematron is XSLT 2.0 and PHP's
 * libxslt only does 1.0 (php-xsl does not close that gap — it was tried),
 * so it needs Saxon; adding a JRE to the runtime image for a development
 * check is not a trade worth making. tools/validate-einvoice.sh fetches the
 * jars on demand.
 */
function schematronAvailable(): bool
{
    return (new Process(['which', 'java']))->run() === 0;
}

/**
 * @param  string  $ruleset  en16931, peppol, or all
 * @return string combined stdout/stderr of tools/validate-einvoice.sh
 */
function validateAgainstSchematron(string $xml, string $ruleset = 'all'): string
{
    $path = sys_get_temp_dir().'/ubl-'.uniqid().'.xml';
    file_put_contents($path, $xml);

    $process = new Process(['bash', 'tools/validate-einvoice.sh', $path, $ruleset], base_path());
    $process->setTimeout(300);
    $process->run();

    @unlink($path);

    return $process->getOutput().$process->getErrorOutput();
}

it('produces an invoice that conforms to EN 16931, not merely to the UBL schema', function (): void {
    $output = validateAgainstSchematron(buildUbl(ublInvoice()), 'en16931');

    expect($output)->toContain('failed assertions: 0')
        // A stylesheet matching nothing would also report zero failures.
        ->and($output)->not->toContain('rules fired: 0');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('produces a conforming credit note', function (): void {
    $owner = createUser(['country' => 'SK']);
    $original = ublInvoice(owner: $owner);

    $creditNote = ublInvoice([
        'type' => InvoiceType::CreditNote->value,
        'invoice_number' => 'DO-2026-001',
        'related_invoice_id' => $original->id,
    ], owner: $owner);

    expect(validateAgainstSchematron(buildUbl($creditNote), 'en16931'))->toContain('failed assertions: 0');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('catches a document that breaks a business rule the XSD would accept', function (): void {
    $xml = buildUbl(ublInvoice());

    // Dropping the supplier's VAT identifier keeps the document
    // schema-valid, and breaks BR-S-02 — the exact gap this test exists for.
    $broken = preg_replace('/<cac:PartyTaxScheme.*?<\/cac:PartyTaxScheme>/s', '', $xml, 1) ?? '';

    expect(validateAgainstSchematron($broken, 'en16931'))->toContain('BR-S-02');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('produces an invoice a Peppol access point will accept, not only one EN 16931 allows', function (): void {
    $output = validateAgainstSchematron(buildUbl(ublInvoice()), 'peppol');

    expect($output)->toContain('[peppol] failed assertions: 0')
        ->and($output)->not->toContain('[peppol] rules fired: 0');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('produces a credit note a Peppol access point will accept', function (): void {
    $owner = createUser(['country' => 'SK']);
    $original = ublInvoice(owner: $owner);

    $creditNote = ublInvoice([
        'type' => InvoiceType::CreditNote->value,
        'invoice_number' => 'DO-2026-002',
        'related_invoice_id' => $original->id,
    ], owner: $owner);

    expect(validateAgainstSchematron(buildUbl($creditNote), 'peppol'))
        ->toContain('[peppol] failed assertions: 0');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('proves the Peppol ruleset rejects what EN 16931 alone accepts', function (): void {
    // The exact defect this whole ruleset was added to catch, kept as a
    // negative control so the two tests above cannot pass by being vacuous.
    //
    // Until 2026-08-12 the export stamped ProfileID with Peppol BIS Billing
    // 3.0 while CustomizationID said plain EN 16931 — a document claiming two
    // different specifications at once. EN 16931 does not care and passes it;
    // Peppol fails it on PEPPOL-EN16931-R004, and so would every access point.
    $xml = str_replace(
        (string) config('invoicing.ubl.customization_id'),
        'urn:cen.eu:en16931:2017',
        buildUbl(ublInvoice()),
    );

    expect(validateAgainstSchematron($xml, 'en16931'))->toContain('failed assertions: 0')
        ->and(validateAgainstSchematron($xml, 'peppol'))->toContain('PEPPOL-EN16931-R004');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('produces a conforming invoice when the document carries a discount', function (): void {
    // Until 2026-08-13 this produced four fatal violations at once —
    // BR-CO-10, BR-CO-11, BR-CO-15 and BR-S-08 — because AllowanceTotalAmount
    // was written with no cac:AllowanceCharge behind it and the header totals
    // were computed off the wrong side of the discount. No test covered a
    // discounted invoice, so every one of them shipped green.
    $output = validateAgainstSchematron(buildUbl(ublInvoice(['discount_percent' => 10])));

    expect($output)->toContain('[en16931] failed assertions: 0')
        ->and($output)->toContain('[peppol] failed assertions: 0');
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));

it('splits a discount across every VAT rate on the document', function (): void {
    // The case the single-rate fixture cannot reach: BR-S-08 reconciles each
    // rate's taxable amount against that rate's lines minus that rate's
    // allowances, so one lump-sum allowance would fail even while the
    // document total came out right.
    $xml = buildUbl(ublInvoice(['discount_percent' => 10], [23.0, 10.0]));

    expect(validateAgainstSchematron($xml))->toContain('[en16931] failed assertions: 0')
        ->and(substr_count($xml, '<cac:AllowanceCharge>'))->toBe(2);
})->skip(fn (): bool => skipUnlessInstalled(schematronAvailable(), 'a JRE for Saxon', 'the JDK preinstalled on the runner — see tools/validate-einvoice.sh'));
