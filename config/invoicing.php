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
        'tesseract_path' => env('INVOICING_INBOX_TESSERACT_PATH', 'tesseract'),
        // Cheap decompression-bomb guard: an image whose width or height
        // exceeds this is rejected before OCR runs at all, on both direct
        // uploads and pages rasterized from a PDF.
        'ocr_max_pixels_per_side' => env('INVOICING_INBOX_OCR_MAX_PIXELS_PER_SIDE', 10000),
        // Tesseract itself has no built-in timeout; enforced via Process.
        'ocr_timeout' => env('INVOICING_INBOX_OCR_TIMEOUT', 60),

        // Field-suggestion extraction driver. Default stays "regex" (no
        // billing/API-key requirement) until a deployment opts in — see
        // FieldExtractorFactory and
        // docs/plans/BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md.
        'extraction' => [
            'driver' => env('INVOICING_EXTRACTION_DRIVER', 'regex'),
            // Order ByokCredentialResolver tries providers in for an
            // account with more than one credential saved. A no-op with a
            // single provider; the one place this is decided once a second
            // ships.
            'provider_priority' => ['anthropic'],
            // Below this many OCR'd characters, the LLM driver sends the
            // rasterized page images instead of (or alongside) text.
            // Provider-agnostic — shared by every driver.
            'vision_min_chars' => env('INVOICING_EXTRACTION_VISION_MIN_CHARS', 500),
            'vision_max_pages' => env('INVOICING_EXTRACTION_VISION_MAX_PAGES', 3),
            'providers' => [
                'anthropic' => [
                    'model' => env('INVOICING_EXTRACTION_LLM_MODEL', 'claude-haiku-4-5'),
                    'timeout' => env('INVOICING_EXTRACTION_LLM_TIMEOUT', 30),
                    'max_tokens' => env('INVOICING_EXTRACTION_LLM_MAX_TOKENS', 4000),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UBL 2.1 / EN 16931 e-invoice
    |--------------------------------------------------------------------------
    |
    | The specification identifiers stamped on every exported e-invoice
    | (EN 16931 BT-24 and BT-23). Configurable rather than hard-coded so a
    | national CIUS is a deployment setting instead of a code change.
    |
    | These two are a matched pair, not independent knobs. The profile said
    | Peppol BIS Billing 3.0 from day one while the customization said plain
    | EN 16931 — a document contradicting itself, which the Peppol Schematron
    | rejects outright (and with it every access point, from 2027 the only way
    | to deliver an invoice in Slovakia at all). Change one, change the other.
    |
    | Peppol BIS 3.0 is the right default rather than bare EN 16931 because
    | Slovakia has declared no national CIUS: the mandate runs on Peppol
    | directly.
    |
    | Overriding the customization from .env requires quoting it: the value
    | contains "#", and phpdotenv reads an unquoted "#" as a comment, so
    | QASA_UBL_CUSTOMIZATION_ID=urn:…#compliant#urn:… arrives as bare
    | "urn:cen.eu:en16931:2017" — the contradiction above, restored in silence.
    | The default here is a PHP literal and has no such problem, which is why
    | .env.example ships these commented out rather than duplicating them.
    |
    */

    'ubl' => [
        'customization_id' => env(
            'QASA_UBL_CUSTOMIZATION_ID',
            'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        ),
        'profile_id' => env('QASA_UBL_PROFILE_ID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'),
    ],

];
