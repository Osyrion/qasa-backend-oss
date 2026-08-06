<?php
/** @var \App\Modules\Taxation\Domain\Models\TaxFiling $filing */
/** @var list<array{path: string, value: string}> $rows */
/** @var list<string> $assumptions */
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
        td, th { vertical-align: top; padding: 4px 8px; }
        .doc-title { font-size: 18px; font-weight: bold; color: #111; margin-bottom: 4px; }
        .doc-subtitle { font-size: 10px; color: #6b7280; margin-bottom: 20px; }
        .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; margin: 18px 0 6px; }
        .rows tr { border-bottom: 1px solid #f3f4f6; }
        .rows td.label { color: #444; }
        .rows td.value { font-weight: bold; text-align: right; white-space: nowrap; }
        .meta td.label { color: #6b7280; width: 32%; }
        .disclaimer { margin-top: 24px; padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; font-size: 9px; color: #78350f; }
        .disclaimer li { margin-left: 14px; margin-top: 4px; }
        .footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 8px; color: #9ca3af; word-break: break-all; }
    </style>
</head>
<body>
<div class="page">
    <div class="doc-title">{{ __('taxation.recap_title') }}</div>
    <div class="doc-subtitle">{{ __('taxation.recap_subtitle') }}</div>

    <table class="meta">
        <tr><td class="label">{{ __('taxation.recap_type') }}</td><td>{{ $filing->type->value }}</td></tr>
        <tr><td class="label">{{ __('taxation.recap_country') }}</td><td>{{ $filing->country }}</td></tr>
        <tr><td class="label">{{ __('taxation.recap_period') }}</td><td>{{ $period }}</td></tr>
        <tr><td class="label">{{ __('taxation.recap_status') }}</td><td>{{ $filing->status->value }}</td></tr>
        <tr><td class="label">{{ __('taxation.recap_generated_at') }}</td><td>{{ $filing->created_at?->format('d.m.Y H:i') }}</td></tr>
    </table>

    <div class="section-title">{{ __('taxation.recap_values') }}</div>
    <table class="rows">
        @foreach ($rows as $row)
            <tr>
                <td class="label">{{ $row['path'] }}</td>
                <td class="value">{{ $row['value'] }}</td>
            </tr>
        @endforeach
    </table>

    @if ($assumptions !== [])
        <div class="disclaimer">
            <strong>{{ __('taxation.recap_assumptions_title') }}</strong>
            <ul>
                @foreach ($assumptions as $assumption)
                    <li>{{ $assumption }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="footer">
        {{ __('taxation.recap_checksum') }}: {{ $filing->sha256 }}
    </div>
</div>
</body>
</html>
