@php
    /** @var \App\Modules\Invoicing\Domain\Models\PaymentOrder $order */
    $payer = $order->payer_snapshot;
    $fmt   = static fn ($v): string => number_format((float) $v, 2, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        .page { padding: 24px 32px; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .right { text-align: right; }

        .doc-title { font-size: 18px; font-weight: bold; color: #111; margin-bottom: 16px; }

        .meta { margin-bottom: 20px; }
        .meta-label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
        .meta-value { font-size: 10.5px; font-weight: bold; }
        .meta td { padding: 2px 18px 2px 0; }

        .items { margin-bottom: 16px; }
        .items thead tr { background: #f9fafb; }
        .items th { padding: 7px 8px; text-align: left; font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; border-bottom: 2px solid #e5e7eb; }
        .items th.right { text-align: right; }
        .items td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; font-size: 9.5px; }
        .items tfoot td { border-top: 2px solid #111; padding-top: 7px; font-size: 11px; font-weight: bold; }

        .note { margin-top: 20px; padding: 10px 12px; background: #f9fafb; font-size: 9.5px; color: #555; }
        .note-label { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; }
        .footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="page">

    <div class="doc-title">{{ __('invoices::pdf.payment_order_title') }}</div>

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">{{ __('invoices::pdf.payment_order_payer') }}</div>
                <div class="meta-value">{{ $payer['label'] ?? '' }}</div>
                <div>
                    @if(!empty($payer['account_number'])){{ $payer['account_number'] }}@endif
                    @if(!empty($payer['iban'])) | {{ $payer['iban'] }}@endif
                    @if(!empty($payer['bic'])) | {{ $payer['bic'] }}@endif
                </div>
            </td>
            <td>
                <div class="meta-label">{{ __('invoices::pdf.due_at') }}</div>
                <div class="meta-value">{{ $order->due_date->format('d.m.Y') }}</div>
            </td>
            <td>
                <div class="meta-label">{{ __('invoices::pdf.payment_order_constant_symbol') }}</div>
                <div class="meta-value">{{ $order->constant_symbol ?? '—' }}</div>
            </td>
            <td>
                <div class="meta-label">{{ __('invoices::pdf.payment_order_created_at') }}</div>
                <div class="meta-value">{{ $order->created_at?->format('d.m.Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            <th style="width:4%">#</th>
            <th style="width:24%">{{ __('invoices::pdf.payment_order_recipient') }}</th>
            <th style="width:14%">{{ __('invoices::pdf.payment_order_document') }}</th>
            <th style="width:22%">{{ __('invoices::pdf.account_number') }}</th>
            <th style="width:11%">{{ __('invoices::pdf.variable_symbol') }}</th>
            <th style="width:13%">{{ __('invoices::pdf.payment_order_verification') }}</th>
            <th class="right" style="width:12%">{{ __('invoices::pdf.payment_order_amount') }} ({{ $order->currency->value }})</th>
        </tr>
        </thead>
        <tbody>
        @foreach($order->items as $item)
            @php $verification = $item->supplierInvoice?->account_verification_result; @endphp
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->vendor_name }}</td>
                <td>{{ $item->supplier_invoice_number }}</td>
                <td>
                    @if($item->hasDomesticAccount()){{ $item->account_number }}/{{ $item->bank_code }}@endif
                    @if($item->iban)@if($item->hasDomesticAccount())<br>@endif{{ $item->iban }}@if($item->bic) ({{ $item->bic }})@endif @endif
                </td>
                <td>{{ $item->variable_symbol ?? '—' }}</td>
                <td>{{ $verification !== null ? __('invoices::pdf.payment_order_verification_'.$verification) : '—' }}</td>
                <td class="right">{{ $fmt($item->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td colspan="6">{{ __('invoices::pdf.payment_order_total') }} ({{ $order->items_count }})</td>
            <td class="right">{{ $fmt($order->total_amount) }} {{ $order->currency->value }}</td>
        </tr>
        </tfoot>
    </table>

    @if($order->note)
        <div class="note">
            <div class="note-label">{{ __('invoices::pdf.note') }}</div>
            {{ $order->note }}
        </div>
    @endif

    <div class="footer">
        {{ __('invoices::pdf.generated') }} {{ now()->format('d.m.Y H:i') }}
    </div>

</div>
</body>
</html>
