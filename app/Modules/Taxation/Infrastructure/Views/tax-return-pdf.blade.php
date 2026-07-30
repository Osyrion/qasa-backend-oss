<?php
/** @var \App\Modules\Taxation\Domain\ValueObjects\TaxReturnResult $result */
$fmt = static fn ($v): string => number_format((float) $v, 2, ',', ' ').' '.$result->currency;
?>
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        .page { padding: 24px 32px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        td, th { vertical-align: top; padding: 5px 8px; }
        .right { text-align: right; }
        .doc-title { font-size: 18px; font-weight: bold; color: #111; margin-bottom: 4px; }
        .doc-subtitle { font-size: 10px; color: #6b7280; margin-bottom: 20px; }
        .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; margin: 18px 0 6px; }
        .rows tr { border-bottom: 1px solid #f3f4f6; }
        .rows td.label { color: #444; }
        .rows td.value { font-weight: bold; }
        .total-row td { border-top: 2px solid #111; font-size: 12px; font-weight: bold; padding-top: 8px; }
        .disclaimer { margin-top: 24px; padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; font-size: 9px; color: #78350f; }
        .notes { margin-top: 12px; font-size: 9px; color: #6b7280; }
        .footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="page">
    <div class="doc-title">{{ __('taxation.pdf_title', ['year' => $result->year]) }}</div>
    <div class="doc-subtitle">{{ __('taxation.pdf_subtitle') }}</div>

    <div class="section-title">{{ __('taxation.pdf_section_income') }}</div>
    <table class="rows">
        <tr><td class="label">{{ __('taxation.pdf_partial_tax_base_business') }}</td><td class="value right">{{ $fmt($result->partialTaxBaseBusiness) }}</td></tr>
        <tr><td class="label">{{ __('taxation.pdf_expenses_used') }} ({{ $result->usedFlatRateExpenses ? __('taxation.pdf_flat_rate') : __('taxation.pdf_actual') }})</td><td class="value right">{{ $fmt($result->expensesUsed) }}</td></tr>
        <tr><td class="label">{{ __('taxation.pdf_total_tax_base') }}</td><td class="value right">{{ $fmt($result->totalTaxBase) }}</td></tr>
    </table>

    <div class="section-title">{{ __('taxation.pdf_section_tax') }}</div>
    <table class="rows">
        <tr><td class="label">{{ __('taxation.pdf_tax_before_credits') }}</td><td class="value right">{{ $fmt($result->taxBeforeCredits) }}</td></tr>
        <tr><td class="label">{{ __('taxation.pdf_tax_credits') }}</td><td class="value right">-{{ $fmt($result->taxCredits) }}</td></tr>
        <tr><td class="label">{{ __('taxation.pdf_child_tax_bonus') }}</td><td class="value right">-{{ $fmt($result->childTaxBonus) }}</td></tr>
        <tr class="total-row"><td>{{ __('taxation.pdf_final_tax') }}</td><td class="right">{{ $fmt($result->finalTax) }}</td></tr>
        <tr><td class="label">{{ __('taxation.pdf_advances_paid') }}</td><td class="value right">{{ $fmt($result->advancesPaid) }}</td></tr>
        <tr class="total-row"><td>{{ __('taxation.pdf_tax_balance') }}</td><td class="right">{{ $fmt($result->taxBalance) }}</td></tr>
    </table>

    <div class="section-title">{{ __('taxation.pdf_section_contributions') }}</div>
    <table class="rows">
        @foreach ($result->contributions as $type => $amount)
            <tr><td class="label">{{ __('taxation.pdf_contribution_'.$type) }}</td><td class="value right">{{ $fmt($amount) }}</td></tr>
        @endforeach
    </table>

    @if (! empty($result->notes))
        <div class="notes">
            @foreach ($result->notes as $note)
                <p>&bull; {{ $note }}</p>
            @endforeach
        </div>
    @endif

    <div class="disclaimer">{{ __('taxation.worksheet_disclaimer') }}</div>

    <div class="footer">{{ __('taxation.pdf_generated_at', ['date' => now()->toDateTimeString()]) }}</div>
</div>
</body>
</html>
