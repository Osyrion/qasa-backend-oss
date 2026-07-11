<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('invoices::emails.reminder_subject', ['number' => $invoice->invoice_number]) }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #27272a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; padding: 32px;">
                    <tr>
                        <td style="font-size: 15px; line-height: 1.6;">
                            <p style="margin: 0 0 16px;">{{ __('invoices::emails.greeting') }}</p>

                            <p style="margin: 0 0 16px;">{{ __('invoices::emails.reminder_intro', ['number' => $invoice->invoice_number]) }}</p>

                            <p style="margin: 0 0 8px; font-weight: bold;">{{ __('invoices::emails.payment_details') }}</p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 0 16px; font-size: 15px; line-height: 1.8;">
                                <tr>
                                    <td style="padding-right: 16px; color: #71717a;">{{ __('invoices::emails.total_due') }}:</td>
                                    <td style="font-weight: bold;">{{ number_format($invoice->balance(), 2, ',', ' ') }} {{ $invoice->currency?->value }}</td>
                                </tr>
                                <tr>
                                    <td style="padding-right: 16px; color: #71717a;">{{ __('invoices::emails.due_date') }}:</td>
                                    <td>{{ $invoice->due_at->format('d.m.Y') }}</td>
                                </tr>
                                @if ($invoice->variable_symbol !== null)
                                    <tr>
                                        <td style="padding-right: 16px; color: #71717a;">{{ __('invoices::emails.variable_symbol') }}:</td>
                                        <td>{{ $invoice->variable_symbol }}</td>
                                    </tr>
                                @endif
                                @if (($iban ?? null) !== null)
                                    <tr>
                                        <td style="padding-right: 16px; color: #71717a;">{{ __('invoices::emails.iban') }}:</td>
                                        <td>{{ $iban }}</td>
                                    </tr>
                                @endif
                                @if (($bic ?? null) !== null)
                                    <tr>
                                        <td style="padding-right: 16px; color: #71717a;">{{ __('invoices::emails.bic') }}:</td>
                                        <td>{{ $bic }}</td>
                                    </tr>
                                @endif
                            </table>

                            {{-- $message (the Symfony message for CID embeds) is only injected
                                 when actually sending — absent in render()/previews. --}}
                            @if (($qrPng ?? null) !== null && isset($message))
                                <p style="margin: 0 0 8px; color: #71717a; font-size: 13px;">{{ __('invoices::emails.qr_hint') }}</p>
                                <img src="{{ $message->embedData($qrPng, 'payment-qr.png', 'image/png') }}"
                                     alt="{{ __('invoices::emails.qr_alt') }}"
                                     width="180" height="180"
                                     style="display: block; margin: 0 0 24px; width: 180px; height: 180px;">
                            @endif

                            <p style="margin: 0 0 24px; color: #71717a; font-size: 13px;">{{ __('invoices::emails.attachment_note') }}</p>

                            <p style="margin: 0;">{{ __('invoices::emails.regards') }}@if ($supplierName !== null),<br>{{ $supplierName }}@endif</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
