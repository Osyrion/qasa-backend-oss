<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Invoicing\Domain\ValueObjects\VatReturnReportData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Builds a draft CZ "Přiznání k dani z přidané hodnoty" (DPH3) XML document
 * — element/attribute names follow dphdp3_epo2.xsd exactly (unnamespaced,
 * attribute-based, DD.MM.RRRR dates — see tests/Fixtures/vat-return/
 * dphdp3_epo2.xsd). Unlike dph2025.xsd (SK), this schema carries genuinely
 * official field-level documentation (§ references to the CZ VAT Act) in
 * its own annotations — row semantics here are meaningfully better sourced
 * than the SK builder's, though still a DRAFT: many optional Veta2/Veta3/
 * Veta5 fields (triangulation, own-account fixed assets, export, OSS
 * distance sales, partial-deduction coefficient, year-end settlement) are
 * never populated, same "not tracked, left out" posture as DphKh1XmlBuilder.
 *
 * Two structural gaps mirror DphKh1XmlBuilder exactly and reuse its
 * resolution: c_ufo (tax office code) is a required 3-digit field this
 * application doesn't track anywhere — same TAX_OFFICE_PLACEHOLDER.
 * The "23"/"5" suffix on rate-bearing attributes (dan23/dan5, obrat23/
 * obrat5, dan_pzb23/dan_pzb5, …) is NOT read as literal percentages —
 * DphKh1XmlBuilder's own VetaA4/B1/B2 columns (zakl_dane1/zakl_dane2) prove
 * this schema family uses tier position ("tier 1" = standard rate, "tier
 * 2" = reduced rate), not the rate's numeric value, and CZ's current
 * catalog (CzTaxSystem::vatRateCatalog() = [0, 12, 21]) has neither 23 nor
 * 5 as an actual rate. "23"-suffixed attributes are treated as tier 1
 * (21 %, standard), "5"-suffixed as tier 2 (12 %, reduced).
 */
final class DphDp3XmlBuilder
{
    private const string TAX_OFFICE_PLACEHOLDER = '000';

    public function build(VatReturnReportData $report, SupplierProfile $supplier): string
    {
        if ($report->month === null && $report->quarter === null) {
            throw new LogicException('DPH3 requires either a month or a quarter — an annual-scope report cannot be filed.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Pisemnost');
        $root->setAttribute('nazevSW', (string) config('app.name'));

        $rows = $this->computeRows($report);

        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->appendChild($this->buildVetaD($dom, $report, $rows));
        $dphdp3->appendChild($this->buildVetaP($dom, $supplier));
        $dphdp3->appendChild($this->buildVeta1($dom, $report));
        $dphdp3->appendChild($this->buildVeta4($dom, $report));
        $dphdp3->appendChild($this->buildVeta6($dom, $rows));

        $root->appendChild($dphdp3);
        $dom->appendChild($root);

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    /**
     * @return list<string> caveats specific to this XML draft
     */
    public function assumptions(): array
    {
        return [
            "Kód finančního úřadu (c_ufo) nie je v aplikácii evidovaný a je vyplnený placeholderom '".self::TAX_OFFICE_PLACEHOLDER."' — pred podaním nutné doplniť.",
            '"23"/"5" v názvoch atribútov (dan23/dan5, obrat23/obrat5, dan_pzb23/dan_pzb5…) sú tier pozície (1=základná, 2=znížená sadzba), nie doslovné percentá — rovnaké zdôvodnenie ako DphKh1XmlBuilder::rateTier().',
            'Samozdanenie (nadobudnutie z EÚ aj dovoz) nie je rozdelené podľa sadzby — appka drží jeden súčet, mapuje sa vždy do tier 1 (dan_pzb23/dan_dzb23).',
            'Prijatie služby zo zahraničia (dan_psl23_e/z, dan_psl5_e/z) je vždy prázdne — appka nerozlišuje nadobudnutie tovaru od prijatia služby pri samozdanení; celé samozdanenie sa mapuje pod dan_pzb23.',
            'Dovoz cez colný úrad (dov_cu/odp_cu) je vždy prázdny — appka nerozlišuje "samozdanenie na priznaní" od "vymerania colným úradom"; celý dovoz sa mapuje pod dan_dzb23/odp_zdp23 ekvivalentom.',
            'Odpočet — použité sú len "V plné výši" stĺpce (odp_tuz23/odp_tuz5 pre tuzemské, od_zdp23/od_zdp5 pre samozdanené) — "Krácený odpočet" (odkr_*) a "nar_zdp"/"pln" varianty rovnakého konceptu sú vždy prázdne, appka nemá koeficient/pomerný odpočet.',
            'Riadky mimo Veta1/Veta4/Veta6 (Veta2/3/5 — vývoz, trojstranný obchod, vlastný dlhodobý majetok, OSS predaj na diaľku, koeficient, ročné vyrovnanie) sú vždy vynechané — appka tieto neeviduje.',
            'Riadkové mapovanie je best-effort — dokumentácia priamo v XSD je oficiálna a presnejšia než pri SK, ale kombinácia do jedného celku (najmä Veta6 súčty) nebola overená voči skutočne podanému priznaniu. Pred podaním nechať skontrolovať účtovníkom/daňovým poradcom.',
        ];
    }

    /**
     * @param  array<string, float>  $rows
     */
    private function buildVetaD(DOMDocument $dom, VatReturnReportData $report, array $rows): DOMElement
    {
        $el = $dom->createElement('VetaD');
        $el->setAttribute('dokument', 'DP3');
        $el->setAttribute('k_uladis', 'DPH');
        $el->setAttribute('rok', (string) $report->year);

        if ($report->month !== null) {
            $el->setAttribute('mesic', (string) $report->month);
        } else {
            $el->setAttribute('ctvrt', (string) $report->quarter);
        }

        $el->setAttribute('dapdph_forma', 'B');
        $el->setAttribute('typ_platce', 'P');
        $el->setAttribute('trans', $rows['dan_zocelk'] > 0.0 ? 'A' : 'N');

        return $el;
    }

    /**
     * typ_ds ("F" = fyzická osoba) is hardcoded — same reasoning as
     * DphKh1XmlBuilder::buildVetaP(): no legal-form field on the profile,
     * freelancers/SZČO are the primary audience.
     */
    private function buildVetaP(DOMDocument $dom, SupplierProfile $supplier): DOMElement
    {
        $el = $dom->createElement('VetaP');
        $el->setAttribute('c_ufo', self::TAX_OFFICE_PLACEHOLDER);
        $el->setAttribute('dic', $this->digitsOnly($supplier->dic ?? $supplier->vatId));
        $el->setAttribute('typ_ds', 'F');
        $el->setAttribute('jmeno', $supplier->firstName);
        $el->setAttribute('prijmeni', $supplier->lastName);

        if ($supplier->address !== null) {
            $el->setAttribute('ulice', $supplier->address);
        }

        if ($supplier->city !== null) {
            $el->setAttribute('naz_obce', $supplier->city);
        }

        if ($supplier->postalCode !== null) {
            $el->setAttribute('psc', $supplier->postalCode);
        }

        $el->setAttribute('email', $supplier->email);

        return $el;
    }

    private function buildVeta1(DOMDocument $dom, VatReturnReportData $report): DOMElement
    {
        $el = $dom->createElement('Veta1');

        $tier1 = $this->tierBucket($report, 21.0);
        $tier2 = $this->tierBucket($report, 12.0);

        if ($tier1 !== null) {
            $el->setAttribute('obrat23', $this->number($tier1['base']));
            $el->setAttribute('dan23', $this->number($tier1['vat']));
        }

        if ($tier2 !== null) {
            $el->setAttribute('obrat5', $this->number($tier2['base']));
            $el->setAttribute('dan5', $this->number($tier2['vat']));
        }

        if ($report->euAcquisitionBase !== 0.0 || $report->euAcquisitionVat !== 0.0) {
            $el->setAttribute('dan_pzb23', $this->number($report->euAcquisitionVat));
        }

        if ($report->importBase !== 0.0 || $report->importVat !== 0.0) {
            $el->setAttribute('dan_dzb23', $this->number($report->importVat));
        }

        return $el;
    }

    private function buildVeta4(DOMDocument $dom, VatReturnReportData $report): DOMElement
    {
        $el = $dom->createElement('Veta4');

        if ($report->domesticInputVat !== 0.0) {
            $el->setAttribute('odp_tuz23', $this->number($report->domesticInputVat));
        }

        $selfAssessedDeductible = round($report->euAcquisitionVat + $report->importVat, 2);

        if ($selfAssessedDeductible !== 0.0) {
            $el->setAttribute('od_zdp23', $this->number($selfAssessedDeductible));
        }

        return $el;
    }

    /**
     * @param  array<string, float>  $rows
     */
    private function buildVeta6(DOMDocument $dom, array $rows): DOMElement
    {
        $el = $dom->createElement('Veta6');
        $el->setAttribute('dan_zocelk', $this->number($rows['dan_zocelk']));
        $el->setAttribute('odp_zocelk', $this->number($rows['odp_zocelk']));

        if ($rows['dan_zocelk'] >= $rows['odp_zocelk']) {
            $el->setAttribute('dano_da', $this->number($rows['dan_zocelk'] - $rows['odp_zocelk']));
        } else {
            $el->setAttribute('dano_no', $this->number($rows['odp_zocelk'] - $rows['dan_zocelk']));
        }

        return $el;
    }

    /**
     * @return array<string, float>
     */
    private function computeRows(VatReturnReportData $report): array
    {
        $tier1 = $this->tierBucket($report, 21.0);
        $tier2 = $this->tierBucket($report, 12.0);

        $outputVat = ($tier1['vat'] ?? 0.0) + ($tier2['vat'] ?? 0.0) + $report->euAcquisitionVat + $report->importVat;
        $deductible = round($report->domesticInputVat + $report->euAcquisitionVat + $report->importVat, 2);

        return [
            'dan_zocelk' => round($outputVat, 2),
            'odp_zocelk' => $deductible,
        ];
    }

    /**
     * @return array{base: float, vat: float}|null
     */
    private function tierBucket(VatReturnReportData $report, float $rate): ?array
    {
        foreach ($report->outputByRate as $key => $bucket) {
            if (abs((float) $key - $rate) < 0.001) {
                return $bucket;
            }
        }

        return null;
    }

    private function digitsOnly(?string $value): string
    {
        return preg_replace('/\D/', '', $value ?? '') ?? '';
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
