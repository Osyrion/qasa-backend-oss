<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\VatReturnReportData;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Builds a draft SK "Priznanie k dani z pridanej hodnoty" (DPHv25) XML
 * document — element names/structure follow dph2025.xsd exactly (root
 * `dokument`, no target namespace — unlike kv_dph_2025.xsd, this schema's
 * elements are unqualified, so no createElementNS here). See
 * tests/Fixtures/vat-return/dph2025.xsd.
 *
 * ROW MAPPING IS BEST-EFFORT, NOT VERIFIED AGAINST THE OFFICIAL POUČENIE
 * (line-by-line filing instructions) — only the XSD structure is verified.
 * Every row this builder fills is commented with what it's assumed to mean;
 * every row it deliberately leaves empty is listed in assumptions() with
 * why. This is a DRAFT for a human/accountant to review before filing —
 * same posture as KvDphXmlBuilder, but with a materially higher residual
 * risk of a wrong row *number* (not just an omitted category), since no
 * official line-by-line reference was available. Re-verify the row
 * assignment below against the current poučenie before this is ever
 * offered to a customer as filing-ready rather than draft.
 */
final class DphXmlBuilder
{
    /**
     * SkTaxSystem::vatRateCatalog(), non-zero, descending — r01/r02 gets the
     * highest (základná sadzba), r01a/r02a the next, r03/r04 the last.
     * Listed rather than keyed by rate — PHP would silently coerce a
     * numeric-string key like '23' to the int 23, which would then never
     * match VatReturnAggregationService::rateKey()'s string keys.
     */
    private const RATE_ROW_ORDER = [
        ['rate' => '23', 'base' => 'r01', 'vat' => 'r02'],
        ['rate' => '10', 'base' => 'r01a', 'vat' => 'r02a'],
        ['rate' => '5', 'base' => 'r03', 'vat' => 'r04'],
    ];

    public function build(VatReturnReportData $report, User $user): string
    {
        if ($report->month === null && $report->quarter === null) {
            throw new LogicException('DPH return requires either a month or a quarter — an annual-scope report cannot be filed.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('dokument');
        $root->appendChild($this->buildHlavicka($dom, $report, $user));
        $root->appendChild($this->buildTelo($dom, $report));

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
            'Riadkové mapovanie NEBOLO overené voči oficiálnemu poučeniu na vyplnenie DPHv25 — len voči XSD štruktúre. Pred podaním nechať skontrolovať účtovníkom/daňovým poradcom.',
            'Priradenie sadzieb k riadkom 01/01a/03 predpokladá poradie 23 % → r01, 10 % → r01a, 5 % → r03 (SkTaxSystem::vatRateCatalog() zostupne, bez 0 %) — over, ak sa poradie v oficiálnom tlačive líši.',
            'Riadky 05–09b (vývoz, dodania do EÚ bez DIČ, premiestnenie tovaru, nehnuteľnosti, trojstranný obchod) sú vždy prázdne — appka tieto kategórie osobitne nerozlišuje.',
            'Riadky 10/10a/10b (prijatie služby zo zahraničia) sú vždy prázdne — appka nerozlišuje nadobudnutie tovaru od prijatia služby pri samozdanení z EÚ; celé nadobudnutie sa mapuje pod r11/r11a.',
            'Riadky 11b–11e a 12b–12e (osobitné prípady nadobudnutia/dovozu, napr. trojstranný obchod) sú vždy prázdne.',
            'Riadky 13/14 (opravy základu dane a dane podľa §25/§53) sú vždy prázdne — opravné doklady (dobropisy) sa do tejto zostavy nepremietajú vôbec, nielen že chýba ich riadok.',
            'Riadok 17 predpokladá plné a okamžité právo na odpočet samozdanenej dane (r11a + r12a) v tom istom období — rovnaký predpoklad ako v kontrolnom výkaze.',
            'Riadky 18/18a (opravy odpočítanej dane) a všetko od riadku 22 (koeficient, ročné zúčtovanie, cestovné kancelárie, použitý tovar, žiadosť o vrátenie nadmerného odpočtu) sú vždy prázdne — appka tieto neeviduje.',
            'Daňový úrad (hlavicka.danovyUrad) treba doplniť ručne — appka ho netrackuje.',
        ];
    }

    private function buildHlavicka(DOMDocument $dom, VatReturnReportData $report, User $user): DOMElement
    {
        $el = $dom->createElement('hlavicka');

        $identifikacneCislo = $dom->createElement('identifikacneCislo');
        $identifikacneCislo->appendChild($this->textEl($dom, 'kodStatu', 'SK'));
        $identifikacneCislo->appendChild($this->textEl($dom, 'cislo', (string) ($user->ico ?? '')));
        $el->appendChild($identifikacneCislo);

        $el->appendChild($this->textEl($dom, 'dic', (string) ($user->dic ?? '')));
        $el->appendChild($this->textEl($dom, 'danovyUrad', ''));
        $el->appendChild($this->textEl($dom, 'nevzniklaPov', '0'));

        $typDP = $dom->createElement('typDP');
        $typDP->appendChild($this->textEl($dom, 'rdp', '1'));
        $typDP->appendChild($this->textEl($dom, 'odp', '0'));
        $typDP->appendChild($this->textEl($dom, 'ddp', '0'));
        $typDP->appendChild($this->textEl($dom, 'datumZisteniaDdp', ''));
        $el->appendChild($typDP);

        $osoba = $dom->createElement('osoba');
        $osoba->appendChild($this->textEl($dom, 'platitel', '1'));
        $osoba->appendChild($this->textEl($dom, 'registrovana', '0'));
        $osoba->appendChild($this->textEl($dom, 'inaPovinna', '0'));
        $osoba->appendChild($this->textEl($dom, 'zdanitelna', '0'));
        $osoba->appendChild($this->textEl($dom, 'zastupca', '0'));
        $osoba->appendChild($this->textEl($dom, 'zastupca69aa', '0'));
        $el->appendChild($osoba);

        $zdanObd = $dom->createElement('zdanObd');
        $zdanObd->appendChild($this->textEl($dom, 'mesiac', $report->month !== null ? (string) $report->month : ''));
        $zdanObd->appendChild($this->textEl($dom, 'stvrtrok', $report->quarter !== null ? (string) $report->quarter : ''));
        $zdanObd->appendChild($this->textEl($dom, 'rok', (string) $report->year));
        $el->appendChild($zdanObd);

        $meno = $dom->createElement('meno');
        $meno->appendChild($this->textEl($dom, 'riadok', $user->supplierName()));
        $el->appendChild($meno);

        $adresa = $dom->createElement('adresa');
        $adresa->appendChild($this->textEl($dom, 'ulica', (string) ($user->address ?? '')));
        $adresa->appendChild($this->textEl($dom, 'cislo', ''));
        $adresa->appendChild($this->textEl($dom, 'psc', (string) ($user->postal_code ?? '')));
        $adresa->appendChild($this->textEl($dom, 'obec', (string) ($user->city ?? '')));
        $adresa->appendChild($this->textEl($dom, 'telefon', (string) ($user->phone ?? '')));
        $adresa->appendChild($this->textEl($dom, 'email', $user->email));
        $el->appendChild($adresa);

        $opravnenaOsoba = $dom->createElement('opravnenaOsoba');
        $opravnenaOsoba->appendChild($this->textEl($dom, 'menoPriezvisko', $user->supplierName()));
        $opravnenaOsoba->appendChild($this->textEl($dom, 'telefon', (string) ($user->phone ?? '')));
        $opravnenaOsoba->appendChild($this->textEl($dom, 'email', $user->email));
        $el->appendChild($opravnenaOsoba);

        $el->appendChild($this->textEl($dom, 'datumVyhlasenia', now()->format('j.n.Y')));

        return $el;
    }

    private function buildTelo(DOMDocument $dom, VatReturnReportData $report): DOMElement
    {
        $el = $dom->createElement('telo');

        $rows = $this->computeRows($report);

        foreach ([
            'r01', 'r01a', 'r02', 'r02a', 'r03', 'r04', 'r05', 'r05a', 'r06', 'r06a', 'r07', 'r08', 'r09', 'r09a', 'r09b',
            'r10', 'r10a', 'r10b', 'r11', 'r11a', 'r11b', 'r11c', 'r11d', 'r11e', 'r12', 'r12a', 'r12b', 'r12c', 'r12d', 'r12e',
            'r13', 'r14', 'r15', 'r16', 'r17', 'r18', 'r18a', 'r19', 'r20', 'r20a', 'r21', 'r22', 'r22a',
            'r23', 'r23a', 'r23b', 'r23c', 'r24', 'r25', 'r26', 'r27', 'r28', 'r29', 'r30', 'r31', 'r32',
        ] as $rowKey) {
            $el->appendChild($this->textEl($dom, $rowKey, isset($rows[$rowKey]) ? $this->number($rows[$rowKey]) : ''));

            if ($rowKey === 'r32') {
                // splneniePodmienok sits between r32 and r33 in the XSD
                // sequence — always "0" (not claiming any special
                // condition), never left blank since it's a required field.
                $el->appendChild($this->textEl($dom, 'splneniePodmienok', '0'));
            }
        }

        foreach (['r33', 'r34', 'r35', 'r36', 'r37'] as $rowKey) {
            $el->appendChild($this->textEl($dom, $rowKey, ''));
        }

        return $el;
    }

    /**
     * @return array<string, float>
     */
    private function computeRows(VatReturnReportData $report): array
    {
        $rows = [];

        foreach (self::RATE_ROW_ORDER as ['rate' => $rate, 'base' => $baseRow, 'vat' => $vatRow]) {
            if (! array_key_exists($rate, $report->outputByRate)) {
                continue;
            }

            $bucket = $report->outputByRate[$rate];
            $rows[$baseRow] = round($bucket['base'], 2);
            $rows[$vatRow] = round($bucket['vat'], 2);
        }

        if ($report->euAcquisitionBase !== 0.0 || $report->euAcquisitionVat !== 0.0) {
            $rows['r11'] = $report->euAcquisitionBase;
            $rows['r11a'] = $report->euAcquisitionVat;
        }

        if ($report->importBase !== 0.0 || $report->importVat !== 0.0) {
            $rows['r12'] = $report->importBase;
            $rows['r12a'] = $report->importVat;
        }

        $outputVat = $this->sumRows($rows, ['r02', 'r02a', 'r04', 'r11a', 'r12a']);
        $rows['r15'] = round($outputVat, 2);

        $deductibleSelfAssessed = round($report->euAcquisitionVat + $report->importVat, 2);

        if ($report->domesticInputVat !== 0.0) {
            $rows['r16'] = $report->domesticInputVat;
        }

        if ($deductibleSelfAssessed !== 0.0) {
            $rows['r17'] = $deductibleSelfAssessed;
        }

        $totalDeductible = round($this->sumRows($rows, ['r16', 'r17']), 2);
        $rows['r19'] = $totalDeductible;

        if ($outputVat >= $totalDeductible) {
            $rows['r20'] = round($outputVat - $totalDeductible, 2);
        } else {
            $rows['r21'] = round($totalDeductible - $outputVat, 2);
        }

        return $rows;
    }

    /**
     * @param  array<string, float>  $rows
     * @param  list<string>  $keys
     */
    private function sumRows(array $rows, array $keys): float
    {
        $sum = 0.0;

        foreach ($keys as $key) {
            if (array_key_exists($key, $rows)) {
                $sum += $rows[$key];
            }
        }

        return $sum;
    }

    private function textEl(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($value));

        return $el;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
