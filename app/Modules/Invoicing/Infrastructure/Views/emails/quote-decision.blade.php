<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #27272a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; padding: 32px;">
                    <tr>
                        <td style="font-size: 15px; line-height: 1.6;">
                            <p style="margin: 0;">
                                {{ __($accepted ? 'invoices::emails.quote_accepted_body' : 'invoices::emails.quote_rejected_body', ['number' => $quote->quote_number]) }}
                            </p>
                            @if ($quote->decision_note)
                                <p style="margin: 16px 0 0; color: #71717a;">{{ $quote->decision_note }}</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
