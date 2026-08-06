<?php
/** @var \App\Modules\Invoicing\Domain\Models\CashDocument $document */
/** @var array<string, mixed> $supplier */
$isIncome = $document->type === \App\Modules\Invoicing\Domain\Enums\CashDocumentType::Income;
$money = static fn ($v): string => number_format((float) $v, 2, ',', ' ').' '.$document->currency->value;
?>
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.6; }
        .page { padding: 32px 40px; }
        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; padding: 6px 8px; }
        .doc-title { font-size: 20px; font-weight: bold; }
        .doc-number { font-size: 13px; color: #444; margin-bottom: 24px; }
        .party { font-size: 11px; color: #444; margin-bottom: 20px; }
        .rows tr { border-bottom: 1px solid #f0f0f0; }
        .rows td.label { color: #666; width: 34%; }
        .amount { margin-top: 20px; border-top: 2px solid #111; padding-top: 10px; font-size: 16px; font-weight: bold; text-align: right; }
        .sign { margin-top: 56px; }
        .sign td { border-top: 1px solid #999; padding-top: 6px; font-size: 10px; color: #666; text-align: center; width: 40%; }
        .storno { margin-top: 16px; padding: 8px 10px; border: 1px solid #f59e0b; background: #fef3c7; font-size: 10px; color: #78350f; }
    </style>
</head>
<body>
<div class="page">
    <div class="doc-title">{{ $isIncome ? __('invoicing.cash_pdf_income_title') : __('invoicing.cash_pdf_expense_title') }}</div>
    <div class="doc-number">{{ $document->number }}</div>

    <div class="party">
        <strong>{{ $supplier['name'] ?? '' }}</strong><br>
        {{ $supplier['address'] ?? '' }}{{ ($supplier['address'] ?? '') !== '' ? ', ' : '' }}{{ $supplier['postal_code'] ?? '' }} {{ $supplier['city'] ?? '' }}<br>
        @if (($supplier['ico'] ?? '') !== ''){{ __('invoicing.cash_pdf_ico') }}: {{ $supplier['ico'] }}@endif
        @if (($supplier['vat_id'] ?? '') !== '') &nbsp;·&nbsp; {{ __('invoicing.cash_pdf_vat_id') }}: {{ $supplier['vat_id'] }}@endif
    </div>

    <table class="rows">
        <tr><td class="label">{{ __('invoicing.cash_pdf_issued_at') }}</td><td>{{ $document->issued_at->format('d.m.Y') }}</td></tr>
        <tr>
            <td class="label">{{ $isIncome ? __('invoicing.cash_pdf_received_from') : __('invoicing.cash_pdf_paid_to') }}</td>
            <td>{{ $document->counterparty ?? '—' }}</td>
        </tr>
        <tr><td class="label">{{ __('invoicing.cash_pdf_description') }}</td><td>{{ $document->description }}</td></tr>
        @if ($document->vat_rate !== null)
            <tr><td class="label">{{ __('invoicing.cash_pdf_vat_rate') }}</td><td>{{ rtrim(rtrim((string) $document->vat_rate, '0'), '.,') }} %</td></tr>
            <tr><td class="label">{{ __('invoicing.cash_pdf_vat_amount') }}</td><td>{{ $money($document->vat_amount) }}</td></tr>
        @endif
        @if ($document->note !== null)
            <tr><td class="label">{{ __('invoicing.cash_pdf_note') }}</td><td>{{ $document->note }}</td></tr>
        @endif
    </table>

    <div class="amount">{{ $money($document->amount) }}</div>

    @if ($document->isReversal())
        <div class="storno">{{ __('invoicing.cash_pdf_is_reversal') }}</div>
    @endif

    <table class="sign">
        <tr>
            <td>{{ __('invoicing.cash_pdf_issued_by') }}</td>
            <td style="border: none; width: 20%;"></td>
            <td>{{ $isIncome ? __('invoicing.cash_pdf_paid_by') : __('invoicing.cash_pdf_received_by') }}</td>
        </tr>
    </table>
</div>
</body>
</html>
