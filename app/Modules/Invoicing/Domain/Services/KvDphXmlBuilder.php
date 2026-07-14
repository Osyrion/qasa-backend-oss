<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Builds a draft SK "kontrolný výkaz DPH" (KVDPH_2025) XML document —
 * element names, attributes, namespace and types follow kv_dph_2025.xsd
 * exactly (elements namespace-qualified, attributes unqualified, ISO dates —
 * see tests/Fixtures/vat-control-statement/kv_dph_2025.xsd).
 *
 * This is a DRAFT for a human/accountant to review before filing — B.3
 * (zjednodušené doklady), C.2 (dobropisy prijaté) and D.1/D.2 (e-kasa) are
 * always omitted because this application doesn't track that data; A.2
 * (intra-EU dispatch) is intentionally omitted because those supplies belong
 * to the EU sales list, not this statement.
 */
final class KvDphXmlBuilder
{
    private const NS = 'https://ekr.financnasprava.sk/Formulare/XSD/kv_dph_2025.xsd';

    private const SUBMISSION_TYPE_RIADNY = 'R';

    public function build(VatControlStatementReportData $report, User $user): string
    {
        if ($report->month === null && $report->quarter === null) {
            throw new LogicException('KV DPH requires either a month or a quarter — an annual-scope report cannot be filed.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'KVDPH_2025');
        $root->appendChild($this->buildIdentifikacia($dom, $report, $user));
        $root->appendChild($this->buildTransakcie($dom, $report));

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
            'IČ DPH platiteľa je odvodené z profilu — pred podaním overte formát SK + 10 číslic.',
            'Pri oddiele C.1 sa číslo pôvodnej faktúry (FP) dopĺňa z prepojenej faktúry; ak prepojenie chýba, použije sa číslo opravného dokladu ako záloha — nutné overiť.',
            'Tuzemské prenesenie daňovej povinnosti (JSON sekcia A.2) sa v XML exportuje ako riadok <A1> s D=0 — reálna schéma KV DPH nemá samostatný oddiel pre tuzemský reverse charge vydané.',
        ];
    }

    private function buildIdentifikacia(DOMDocument $dom, VatControlStatementReportData $report, User $user): DOMElement
    {
        $el = $dom->createElementNS(self::NS, 'Identifikacia');

        $el->appendChild($this->textEl($dom, 'IcDphPlatitela', $this->filerVatId($user)));
        $el->appendChild($this->textEl($dom, 'Druh', self::SUBMISSION_TYPE_RIADNY));
        $el->appendChild($this->buildObdobie($dom, $report));
        $el->appendChild($this->textEl($dom, 'Nazov', $user->full_name));
        $el->appendChild($this->textEl($dom, 'Stat', (string) ($user->country ?? 'SK')));
        $el->appendChild($this->textEl($dom, 'Obec', (string) ($user->city ?? '')));

        if ($user->postal_code !== null) {
            $el->appendChild($this->textEl($dom, 'PSC', $user->postal_code));
        }

        if ($user->address !== null) {
            $el->appendChild($this->textEl($dom, 'Ulica', $user->address));
        }

        if ($user->phone !== null) {
            $el->appendChild($this->textEl($dom, 'Tel', $user->phone));
        }

        $el->appendChild($this->textEl($dom, 'Email', $user->email));

        return $el;
    }

    private function buildObdobie(DOMDocument $dom, VatControlStatementReportData $report): DOMElement
    {
        $el = $dom->createElementNS(self::NS, 'Obdobie');
        $el->appendChild($this->textEl($dom, 'Rok', (string) $report->year));

        if ($report->month !== null) {
            $el->appendChild($this->textEl($dom, 'Mesiac', (string) $report->month));
        } else {
            $el->appendChild($this->textEl($dom, 'Stvrtrok', (string) $report->quarter));
        }

        return $el;
    }

    private function buildTransakcie(DOMDocument $dom, VatControlStatementReportData $report): DOMElement
    {
        $el = $dom->createElementNS(self::NS, 'Transakcie');

        // The real KV DPH schema has no distinct section for domestic
        // reverse-charge issued supplies — per Financial Administration
        // practice they're declared under A.1 too, with D=0 (no tax charged,
        // the customer self-assesses). The JSON report keeps A.1/A.2 as
        // separate section codes for readability; both map to XML <A1> here.
        foreach ([...$report->rowSections['A1'] ?? [], ...$report->rowSections['A2'] ?? []] as $row) {
            $el->appendChild($this->buildRow($dom, 'A1', [
                'Odb' => $row->partnerTaxId,
                'F' => $row->documentNumber,
                'Den' => $row->date,
                'Z' => $this->number($row->base),
                'D' => $this->number($row->vat),
                'S' => $this->rateAttr($row->rate),
            ]));
        }

        foreach ($report->rowSections['B1'] ?? [] as $row) {
            $el->appendChild($this->buildRow($dom, 'B1', [
                'Dod' => $row->partnerTaxId,
                'F' => $row->documentNumber,
                'Den' => $row->date,
                'Z' => $this->number($row->base),
                'D' => $this->number($row->vat),
                'S' => $this->rateAttr($row->rate),
                // Full deduction assumed — see VatControlStatementReportData::$assumptions.
                'O' => $this->number($row->vat),
            ]));
        }

        foreach ($report->rowSections['B2'] ?? [] as $row) {
            $el->appendChild($this->buildRow($dom, 'B2', [
                'Dod' => $row->partnerTaxId,
                'F' => $row->documentNumber,
                'Den' => $row->date,
                'Z' => $this->number($row->base),
                'D' => $this->number($row->vat),
                'S' => $this->rateAttr($row->rate),
                'O' => $this->number($row->vat),
            ]));
        }

        foreach ($report->rowSections['C1'] ?? [] as $row) {
            $el->appendChild($this->buildC1($dom, $row));
        }

        return $el;
    }

    private function buildC1(DOMDocument $dom, VatControlStatementRowData $row): DOMElement
    {
        $el = $dom->createElementNS(self::NS, 'C1');

        if ($row->partnerTaxId !== null) {
            $el->setAttribute('Odb', $row->partnerTaxId);
        }

        $el->setAttribute('FO', $row->documentNumber);
        $el->setAttribute('FP', $row->relatedDocumentNumber ?? $row->documentNumber);
        $el->setAttribute('ZR', $this->number($row->base));
        $el->setAttribute('DR', $this->number($row->vat));
        $el->setAttribute('S', $this->rateAttr($row->rate));

        return $el;
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function buildRow(DOMDocument $dom, string $elementName, array $attributes): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $elementName);

        foreach ($attributes as $name => $value) {
            if ($value === null) {
                continue;
            }

            $el->setAttribute($name, $value);
        }

        return $el;
    }

    private function textEl(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));

        return $el;
    }

    private function filerVatId(User $user): string
    {
        $vatId = strtoupper((string) ($user->vat_id ?? ''));

        if (preg_match('/^SK\d{10}$/', $vatId) === 1) {
            return $vatId;
        }

        $digits = preg_replace('/\D/', '', $user->dic ?? '') ?? '';

        return 'SK'.str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    private function rateAttr(float $rate): string
    {
        return (string) (int) round($rate);
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
