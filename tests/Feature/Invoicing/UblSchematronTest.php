<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/UblFixtures.php';

/**
 * The official EN 16931 Schematron (ConnectingEurope/eInvoicing-EN16931
 * 1.3.16), not just the XSD.
 *
 * This is the check that turns "built to EN 16931" into "conforms to
 * EN 16931" — the XSD is far looser than the norm, and a document can pass
 * it while breaking a business rule.
 *
 * Skipped when Java is absent. The compiled Schematron is XSLT 2.0 and PHP's
 * libxslt only does 1.0 (php-xsl does not close that gap — it was tried),
 * so it needs Saxon; adding a JRE to the runtime image for a development
 * check is not a trade worth making. tools/validate-en16931.sh fetches the
 * jars on demand.
 */
function schematronAvailable(): bool
{
    return (new Process(['which', 'java']))->run() === 0;
}

/**
 * @return string combined stdout/stderr of tools/validate-en16931.sh
 */
function validateAgainstSchematron(string $xml): string
{
    $path = sys_get_temp_dir().'/ubl-'.uniqid().'.xml';
    file_put_contents($path, $xml);

    $process = new Process(['bash', 'tools/validate-en16931.sh', $path], base_path());
    $process->setTimeout(300);
    $process->run();

    @unlink($path);

    return $process->getOutput().$process->getErrorOutput();
}

it('produces an invoice that conforms to EN 16931, not merely to the UBL schema', function (): void {
    $output = validateAgainstSchematron(buildUbl(ublInvoice()));

    expect($output)->toContain('failed assertions: 0')
        // A stylesheet matching nothing would also report zero failures.
        ->and($output)->not->toContain('rules fired: 0');
})->skip(fn (): bool => ! schematronAvailable(), 'needs a JRE — see tools/validate-en16931.sh');

it('produces a conforming credit note', function (): void {
    $owner = createUser(['country' => 'SK']);
    $original = ublInvoice(owner: $owner);

    $creditNote = ublInvoice([
        'type' => InvoiceType::CreditNote->value,
        'invoice_number' => 'DO-2026-001',
        'related_invoice_id' => $original->id,
    ], owner: $owner);

    expect(validateAgainstSchematron(buildUbl($creditNote)))->toContain('failed assertions: 0');
})->skip(fn (): bool => ! schematronAvailable(), 'needs a JRE — see tools/validate-en16931.sh');

it('catches a document that breaks a business rule the XSD would accept', function (): void {
    $xml = buildUbl(ublInvoice());

    // Dropping the supplier's VAT identifier keeps the document
    // schema-valid, and breaks BR-S-02 — the exact gap this test exists for.
    $broken = preg_replace('/<cac:PartyTaxScheme.*?<\/cac:PartyTaxScheme>/s', '', $xml, 1) ?? '';

    expect(validateAgainstSchematron($broken))->toContain('BR-S-02');
})->skip(fn (): bool => ! schematronAvailable(), 'needs a JRE — see tools/validate-en16931.sh');
