<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementSummaryRowData;
use DOMDocument;
use DOMElement;

/**
 * Builds a draft CZ "kontrolní hlášení" (DPHKH1) XML document — element
 * names, attributes and types follow dphkh1_epo2.xsd exactly (unnamespaced,
 * DD.MM.RRRR dates, see tests/Fixtures/vat-control-statement/dphkh1_epo2.xsd).
 *
 * This is a DRAFT for a human/accountant to review before filing, not a
 * ready-to-submit document: a handful of required schema fields aren't
 * collected anywhere in this application (tax office code, the §92a "predmět
 * plnění" commodity code for domestic reverse charge) and are emitted as
 * clearly-placeholder values — see buildAssumptions().
 */
final class DphKh1XmlBuilder
{
    private const SUBMISSION_TYPE_RADNE = 'B';

    private const TAX_OFFICE_PLACEHOLDER = '000';

    private const REVERSE_CHARGE_CODE_PLACEHOLDER = '1';

    public function build(VatControlStatementReportData $report, User $user): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Pisemnost');
        $root->setAttribute('nazevSW', (string) config('app.name'));

        $dphkh1 = $dom->createElement('DPHKH1');
        $dphkh1->appendChild($this->buildVetaD($dom, $report));
        $dphkh1->appendChild($this->buildVetaP($dom, $user));

        foreach ($this->groupByDocument($report->rowSections['A1'] ?? []) as $group) {
            $dphkh1->appendChild($this->buildVetaA1($dom, $group));
        }

        foreach ($this->groupByDocumentAndTier($report->rowSections['A4'] ?? []) as $group) {
            $dphkh1->appendChild($this->buildVetaA4($dom, $group));
        }

        $a5 = $this->buildSummaryElement($dom, 'VetaA5', $report->summarySections['A5'] ?? []);
        if ($a5 !== null) {
            $dphkh1->appendChild($a5);
        }

        foreach ($this->groupByDocumentAndTier($report->rowSections['B1'] ?? []) as $group) {
            $dphkh1->appendChild($this->buildVetaB1($dom, $group));
        }

        foreach ($this->groupByDocumentAndTier($report->rowSections['B2'] ?? []) as $group) {
            $dphkh1->appendChild($this->buildVetaB2($dom, $group));
        }

        $b3 = $this->buildSummaryElement($dom, 'VetaB3', $report->summarySections['B3'] ?? []);
        if ($b3 !== null) {
            $dphkh1->appendChild($b3);
        }

        $root->appendChild($dphkh1);
        $dom->appendChild($root);

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    /**
     * @return list<string> caveats specific to this XML draft, to surface
     *                      alongside the report's own assumptions
     */
    public function assumptions(): array
    {
        return [
            "Kód finančního úřadu (c_ufo) nie je v aplikácii evidovaný a je vyplnený placeholderom '".self::TAX_OFFICE_PLACEHOLDER."' — pred podaním nutné doplniť.",
            "Kód predmetu plnenia pri tuzemskom prenesení daňovej povinnosti (kod_pred_pl, oddíly A.1/B.1) je vyplnený placeholderom '".self::REVERSE_CHARGE_CODE_PLACEHOLDER."' — nutné overiť a opraviť podľa skutočného predmetu plnenia.",
            'Oddíl B.1 zahŕňa všetky samozdanené prijaté doklady (EU reverse charge aj import) ako aproximáciu tuzemského prenesenia daňovej povinnosti podľa §92a-92g — skutočné zaradenie je nutné overiť s účtovníkom.',
        ];
    }

    /**
     * @param  list<VatControlStatementRowData>  $rows
     * @return array<string, array{documentNumber: string, date: string, partnerTaxId: ?string, base: float, vat: float}>
     */
    private function groupByDocument(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->documentNumber] ??= [
                'documentNumber' => $row->documentNumber,
                'date' => $row->date,
                'partnerTaxId' => $row->partnerTaxId,
                'base' => 0.0,
                'vat' => 0.0,
            ];

            $groups[$row->documentNumber]['base'] += $row->base;
            $groups[$row->documentNumber]['vat'] += $row->vat;
        }

        return $groups;
    }

    /**
     * Groups per-rate rows into one entry per document with up to two rate
     * tiers (tier 1 = standard rate, tier 2 = reduced rate) matching the
     * VetaA4/VetaB1/VetaB2 zakl_dane1/zakl_dane2 column pairs.
     *
     * @param  list<VatControlStatementRowData>  $rows
     * @return array<string, array{documentNumber: string, date: string, partnerTaxId: ?string, tiers: array<int, array{base: float, vat: float}>}>
     */
    private function groupByDocumentAndTier(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->documentNumber] ??= [
                'documentNumber' => $row->documentNumber,
                'date' => $row->date,
                'partnerTaxId' => $row->partnerTaxId,
                'tiers' => [],
            ];

            $tier = $this->rateTier($row->rate);
            $groups[$row->documentNumber]['tiers'][$tier] ??= ['base' => 0.0, 'vat' => 0.0];
            $groups[$row->documentNumber]['tiers'][$tier]['base'] += $row->base;
            $groups[$row->documentNumber]['tiers'][$tier]['vat'] += $row->vat;
        }

        return $groups;
    }

    /**
     * Current CZ VAT scheme has one standard and one reduced non-zero rate
     * (config('taxation.CZ.vat_rates') = [0, 12, 21]); the schema's third
     * tier (second reduced rate) is a pre-2024 leftover and stays unused.
     * A 0% base has no dedicated column, so it's folded into tier 1.
     */
    private function rateTier(float $rate): int
    {
        $rates = array_filter((array) config('taxation.CZ.vat_rates', [0, 12, 21]), static fn ($r): bool => (float) $r > 0.0);
        sort($rates);
        $reduced = $rates[0] ?? null;

        return $reduced !== null && abs($rate - (float) $reduced) < 0.001 ? 2 : 1;
    }

    private function buildVetaD(DOMDocument $dom, VatControlStatementReportData $report): DOMElement
    {
        $el = $dom->createElement('VetaD');
        $el->setAttribute('dokument', 'KH1');
        $el->setAttribute('k_uladis', 'DPH');

        if ($report->month !== null) {
            $el->setAttribute('mesic', (string) $report->month);
        }

        $el->setAttribute('rok', (string) $report->year);

        if ($report->quarter !== null) {
            $el->setAttribute('ctvrt', (string) $report->quarter);
        }

        $el->setAttribute('khdph_forma', self::SUBMISSION_TYPE_RADNE);

        return $el;
    }

    /**
     * typ_ds ("F" = fyzická osoba) is hardcoded: this application doesn't
     * track a legal-form field on the user profile, and freelancers/SZČO are
     * the primary target audience.
     */
    private function buildVetaP(DOMDocument $dom, User $user): DOMElement
    {
        $el = $dom->createElement('VetaP');
        $el->setAttribute('c_ufo', self::TAX_OFFICE_PLACEHOLDER);
        $el->setAttribute('dic', $this->digitsOnly($user->dic ?? $user->vat_id));
        $el->setAttribute('typ_ds', 'F');
        $el->setAttribute('jmeno', (string) $user->name);
        $el->setAttribute('prijmeni', (string) ($user->surname ?? ''));

        if ($user->address !== null) {
            $el->setAttribute('ulice', $user->address);
        }

        if ($user->city !== null) {
            $el->setAttribute('naz_obce', $user->city);
        }

        if ($user->postal_code !== null) {
            $el->setAttribute('psc', $user->postal_code);
        }

        $el->setAttribute('email', $user->email);

        return $el;
    }

    /**
     * @param  array{documentNumber: string, date: string, partnerTaxId: ?string, base: float, vat: float}  $group
     */
    private function buildVetaA1(DOMDocument $dom, array $group): DOMElement
    {
        $el = $dom->createElement('VetaA1');
        $el->setAttribute('dic_odb', $this->digitsOnly($group['partnerTaxId']));
        $el->setAttribute('c_evid_dd', $group['documentNumber']);
        $el->setAttribute('duzp', $this->czDate($group['date']));
        $el->setAttribute('zakl_dane1', $this->number($group['base']));
        $el->setAttribute('kod_pred_pl', self::REVERSE_CHARGE_CODE_PLACEHOLDER);

        return $el;
    }

    /**
     * @param  array{documentNumber: string, date: string, partnerTaxId: ?string, tiers: array<int, array{base: float, vat: float}>}  $group
     */
    private function buildVetaA4(DOMDocument $dom, array $group): DOMElement
    {
        $el = $dom->createElement('VetaA4');
        $el->setAttribute('dic_odb', $this->digitsOnly($group['partnerTaxId']));
        $el->setAttribute('c_evid_dd', $group['documentNumber']);
        $el->setAttribute('dppd', $this->czDate($group['date']));
        $this->appendTiers($el, $group['tiers']);
        $el->setAttribute('kod_rezim_pl', '0');
        $el->setAttribute('zdph_44', 'N');

        return $el;
    }

    /**
     * @param  array{documentNumber: string, date: string, partnerTaxId: ?string, tiers: array<int, array{base: float, vat: float}>}  $group
     */
    private function buildVetaB1(DOMDocument $dom, array $group): DOMElement
    {
        $el = $dom->createElement('VetaB1');
        $el->setAttribute('dic_dod', $this->digitsOnly($group['partnerTaxId']));
        $el->setAttribute('c_evid_dd', $group['documentNumber']);
        $el->setAttribute('duzp', $this->czDate($group['date']));
        $this->appendTiers($el, $group['tiers']);
        $el->setAttribute('kod_pred_pl', self::REVERSE_CHARGE_CODE_PLACEHOLDER);

        return $el;
    }

    /**
     * @param  array{documentNumber: string, date: string, partnerTaxId: ?string, tiers: array<int, array{base: float, vat: float}>}  $group
     */
    private function buildVetaB2(DOMDocument $dom, array $group): DOMElement
    {
        $el = $dom->createElement('VetaB2');
        $el->setAttribute('dic_dod', $this->digitsOnly($group['partnerTaxId']));
        $el->setAttribute('c_evid_dd', $group['documentNumber']);
        $el->setAttribute('dppd', $this->czDate($group['date']));
        $this->appendTiers($el, $group['tiers']);
        // Full deduction assumed — see VatControlStatementReportData::$assumptions.
        $el->setAttribute('pomer', 'N');
        $el->setAttribute('zdph_44', 'N');

        return $el;
    }

    /**
     * @param  array<int, array{base: float, vat: float}>  $tiers
     */
    private function appendTiers(DOMElement $el, array $tiers): void
    {
        if (isset($tiers[1])) {
            $el->setAttribute('zakl_dane1', $this->number($tiers[1]['base']));
            $el->setAttribute('dan1', $this->number($tiers[1]['vat']));
        }

        if (isset($tiers[2])) {
            $el->setAttribute('zakl_dane2', $this->number($tiers[2]['base']));
            $el->setAttribute('dan2', $this->number($tiers[2]['vat']));
        }
    }

    /**
     * @param  list<VatControlStatementSummaryRowData>  $rows
     */
    private function buildSummaryElement(DOMDocument $dom, string $elementName, array $rows): ?DOMElement
    {
        if ($rows === []) {
            return null;
        }

        $tiers = [];

        foreach ($rows as $row) {
            $tier = $this->rateTier($row->rate);
            $tiers[$tier] ??= ['base' => 0.0, 'vat' => 0.0];
            $tiers[$tier]['base'] += $row->base;
            $tiers[$tier]['vat'] += $row->vat;
        }

        $el = $dom->createElement($elementName);
        $this->appendTiers($el, $tiers);

        return $el;
    }

    private function digitsOnly(?string $value): string
    {
        return preg_replace('/\D/', '', $value ?? '') ?? '';
    }

    /**
     * @param  string  $isoDate  "Y-m-d"
     */
    private function czDate(string $isoDate): string
    {
        [$year, $month, $day] = explode('-', $isoDate);

        return sprintf('%02d.%02d.%04d', (int) $day, (int) $month, (int) $year);
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
