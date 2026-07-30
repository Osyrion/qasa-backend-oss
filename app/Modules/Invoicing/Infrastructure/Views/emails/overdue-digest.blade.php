<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('invoices::emails.overdue_digest_subject', ['count' => $invoices->count()]) }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #27272a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; padding: 32px;">
                    <tr>
                        <td style="font-size: 15px; line-height: 1.6;">
                            <p style="margin: 0 0 16px;">{{ __('invoices::emails.greeting') }}</p>

                            <p style="margin: 0 0 16px;">{{ __('invoices::emails.overdue_digest_intro', ['count' => $invoices->count()]) }}</p>

                            <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
                                <tr style="text-align: left; border-bottom: 1px solid #e4e4e7;">
                                    <th>{{ __('invoices::emails.overdue_digest_column_number') }}</th>
                                    <th>{{ __('invoices::emails.overdue_digest_column_client') }}</th>
                                    <th style="text-align: right;">{{ __('invoices::emails.overdue_digest_column_amount') }}</th>
                                    <th style="text-align: right;">{{ __('invoices::emails.overdue_digest_column_days_overdue') }}</th>
                                </tr>
                                @foreach($invoices as $invoice)
                                    <tr style="border-bottom: 1px solid #f4f4f5;">
                                        <td>{{ $invoice->invoice_number }}</td>
                                        <td>{{ $invoice->client_snapshot['name'] ?? $invoice->client?->display_name }}</td>
                                        <td style="text-align: right;">{{ number_format((float) $invoice->balance(), 2) }} {{ $invoice->currency->value }}</td>
                                        <td style="text-align: right;">{{ $invoice->due_at->diffInDays(now()) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
