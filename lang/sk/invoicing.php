<?php

declare(strict_types=1);

return [
    'payment_requires_open_invoice' => "Platbu je možné evidovať iba k vystavenej faktúre — faktúra je v stave ':status'.",
    'payment_not_for_credit_note' => 'K dobropisu nie je možné evidovať platbu.',
    'cannot_email_cancelled_invoice' => 'Stornovanú faktúru nie je možné odoslať emailom.',
    'client_email_missing_for_send' => 'Klient nemá e-mailovú adresu. Doplňte ju alebo zadajte príjemcu.',
    'only_draft_editable' => 'Upravovať je možné len koncept faktúry.',
    'template_not_paused' => 'Šablónu je možné obnoviť len ak je pozastavená.',
    'template_not_active' => 'Šablónu je možné pozastaviť len ak je aktívna.',
    'template_missing_owner' => 'Opakovaná šablóna :id nemá vlastníka.',
    'template_paused_missing_client' => 'Opakovaná šablóna :id bola pozastavená: klient chýba alebo bol zmazaný.',
    'corrective_must_be_credit_or_storno' => 'Opravný doklad musí byť dobropis alebo storno.',
    'corrective_only_for_invoice' => 'Opravný doklad je možné vystaviť len k faktúre.',
    'storno_only_for_sent' => 'Stornovať je možné len odoslanú faktúru.',
    'credit_note_only_for_sent_or_paid' => 'Dobropis je možné vystaviť len k odoslanej alebo zaplatenej faktúre.',
    'cannot_invoice_personal_order' => 'Osobnú zákazku nie je možné fakturovať.',
    'order_no_billable_items' => 'Zákazka neobsahuje žiadne fakturovateľné položky ani nevyfakturovaný čas.',
    'items_only_for_draft' => 'Položky je možné pridávať len ku konceptu faktúry.',
    'work_report_only_editable_for_draft' => 'Výkaz je možné upravovať len pre koncept faktúry.',
    'work_report_only_generatable_for_draft' => 'Výkaz je možné generovať len pre koncept faktúry.',
    'reminder_only_for_sent' => "Upomienku je možné poslať iba k odoslanej faktúre — faktúra je v stave ':status'.",
    'reminder_cooldown' => 'Ďalšiu upomienku bude možné poslať :next_allowed.',
    'client_email_missing_for_reminder' => 'Klient nemá vyplnenú e-mailovú adresu.',
    'status_transition_not_allowed' => "Faktúru nie je možné zmeniť zo stavu ':from' na ':to'.",
    'invalid_number_mask' => 'Maska musí obsahovať práve jeden sekvenčný token ({N}, {NN}, ...) a len povolené polia {YYYY}, {YY}, {MM}, {DD}.',

    'supplier_invoice' => [
        'client_must_be_vendor' => 'Vybraný klient nie je označený ako dodávateľ.',
        'only_draft_editable' => 'Upravovať alebo zmazať je možné len koncept prijatej faktúry.',
        'status_transition_not_allowed' => "Prijatú faktúru nie je možné zmeniť zo stavu ':from' na ':to'.",
    ],

    'inbox' => [
        'already_processed' => 'Táto položka bola už spracovaná.',
        'unsupported_file' => 'Nepodporovaný typ súboru.',
        'extraction_failed' => 'Nepodarilo sa extrahovať text z dokladu.',
        'status' => [
            'pending' => 'Čaká',
            'imported' => 'Importovaná',
            'ignored' => 'Ignorovaná',
            'failed' => 'Chyba spracovania',
        ],
    ],

    'export' => [
        'csv_headers' => [
            'invoice_number' => 'Číslo faktúry',
            'type' => 'Typ',
            'status' => 'Stav',
            'issued_at' => 'Dátum vystavenia',
            'taxable_supply_at' => 'Dátum zdaniteľného plnenia',
            'due_at' => 'Dátum splatnosti',
            'client_name' => 'Klient',
            'client_ico' => 'IČO klienta',
            'client_dic' => 'DIČ klienta',
            'client_vat_id' => 'IČ DPH klienta',
            'currency' => 'Mena',
            'subtotal' => 'Základ dane',
            'discount_amount' => 'Zľava',
            'vat_amount' => 'DPH',
            'total' => 'Celkom',
            'paid_amount' => 'Uhradené',
            'balance' => 'Zostatok',
            'variable_symbol' => 'Variabilný symbol',
            'exchange_rate' => 'Kurz',
        ],
    ],
];
