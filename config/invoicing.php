<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reminder cooldown
    |--------------------------------------------------------------------------
    |
    | Minimum number of days between two payment reminders for the same
    | invoice — prevents accidentally spamming a client.
    |
    */

    'reminder_cooldown_days' => env('INVOICING_REMINDER_COOLDOWN_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Supplier invoice number mask fallback
    |--------------------------------------------------------------------------
    |
    | Used when a user has not configured their own supplier_invoice_number_mask.
    | Kept distinct from the outgoing Proforma prefix (PF) purely for visual
    | clarity — the two live in separate tables with independent sequences.
    |
    */

    'supplier_invoice_number_mask' => env('INVOICING_SUPPLIER_INVOICE_NUMBER_MASK', 'DF-{YYYY}-{NNNN}'),

    /*
    |--------------------------------------------------------------------------
    | Quote number mask fallback
    |--------------------------------------------------------------------------
    |
    | Used when a user has not configured their own quote_number_mask.
    |
    */

    'quote_number_mask' => env('INVOICING_QUOTE_NUMBER_MASK', 'CP-{YYYY}-{NNN}'),

    /*
    |--------------------------------------------------------------------------
    | Public link in outbound emails
    |--------------------------------------------------------------------------
    |
    | When enabled, SendInvoiceEmailAction and RemindInvoiceAction create (or
    | reuse) a public link and include a "view online" button in the email
    | body. Tenants who don't want a shareable link can disable this.
    |
    */

    'public_link_in_emails' => env('INVOICING_PUBLIC_LINK_IN_EMAILS', true),

    /*
    |--------------------------------------------------------------------------
    | Invoice inbox scanner
    |--------------------------------------------------------------------------
    |
    | Watched folder for the qasa:invoices:scan-inbox command. Each account's
    | documents live in a subfolder keyed by its user id: {path}/{account_id}.
    | Stored inbox items themselves always live on the "local" disk,
    | independent of where the watched folder is.
    |
    */

    'inbox' => [
        'disk' => env('INVOICING_INBOX_DISK', 'local'),
        'path' => env('INVOICING_INBOX_PATH', 'inbox'),
        'ocr_languages' => env('INVOICING_INBOX_OCR_LANGS', 'slk+ces+eng'),
        'max_bytes' => env('INVOICING_INBOX_MAX_BYTES', 20 * 1024 * 1024),
        // OCR fallback for scanned (image-only) PDFs: rasterize via
        // poppler-utils' pdftoppm, then run the same Tesseract OCR used
        // for photos on each page image.
        'pdftoppm_path' => env('INVOICING_INBOX_PDFTOPPM_PATH', 'pdftoppm'),
        'ocr_max_pages' => env('INVOICING_INBOX_OCR_MAX_PAGES', 5),
        'ocr_dpi' => env('INVOICING_INBOX_OCR_DPI', 200),
    ],

];
