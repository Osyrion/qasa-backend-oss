<?php

declare(strict_types=1);

return [

    // Invoicing
    'overdue_digest_title' => 'Faktury po splatnosti',
    'overdue_digest_body' => 'Po splatnosti je nových :count faktur v celkové výši :amount.',
    'reminders_exhausted_title' => 'Upomínky vyčerpány',
    'reminders_exhausted_body' => 'Na fakturu :number bylo odesláno všech :count automatických upomínek a stále není uhrazena.',
    'quote_accepted_title' => 'Nabídka přijata',
    'quote_accepted_body' => 'Klient přijal nabídku :number.',
    'quote_rejected_title' => 'Nabídka odmítnuta',
    'quote_rejected_body' => 'Klient odmítl nabídku :number.',

    // Taxation
    'filing_type_control_statement' => 'kontrolní hlášení DPH',
    'tax_filing_reminder_title' => 'Blíží se termín podání',
    'tax_filing_reminder_body' => 'Podání :type za období :period má termín :due_date.',

    // Billing (subscriptions)
    'renewal_order_created_title' => 'Vystavena objednávka obnovy',
    'renewal_order_created_body' => 'Obnova předplatného :plan je připravena k úhradě se splatností :due_date.',
    'renewal_order_reminder_title' => 'Blíží se splatnost předplatného',
    'renewal_order_reminder_body' => 'Obnova předplatného :plan je splatná :due_date.',
    'subscription_expired_title' => 'Předplatné vypršelo',
    'subscription_expired_body' => 'Předplatné :plan vypršelo — účet je nyní omezen na bezplatnou úroveň.',

    'trial_ending_title' => 'Zkušební období se blíží ke konci',
    'trial_ending_body' => 'Bezplatné zkušební období končí za :days dní. Vyberte si plán a vše nastavené vám zůstane.',
    'trial_expired_title' => 'Zkušební období skončilo',
    'trial_expired_body' => 'Účet je zpět na bezplatné úrovni. Nic se nesmazalo — klienti nad bezplatný limit jsou jen ke čtení, dokud si předplatné nezaložíte.',

    // Integrations — outbound e-mail delivery
    'email_bounced_title' => 'E-mail se nedoručil',
    'email_bounced_body' => 'Zprávu na adresu :recipient se nepodařilo doručit (:reason).',
    'email_spam_complaint_title' => 'E-mail označen jako spam',
    'email_spam_complaint_body' => 'Adresát :recipient označil vaši zprávu jako spam; další zprávy na tuto adresu se nedoručí.',

    // Integrations — e-fakturace přes Peppol
    'peppol_dispatch_failed_title' => 'E-faktura se neodeslala',
    'peppol_dispatch_failed_body' => 'Faktura :number se do sítě Peppol nedostala: :reason',
    'peppol_dispatch_abandoned_body' => 'Faktura :number se do sítě Peppol nedostala ani po :attempts pokusech a dál to už nezkoušíme: :reason',
    'peppol_invoice_rejected_title' => 'Odběratel odmítl e-fakturu',
    'peppol_invoice_rejected_body' => 'Odběratel odmítl zpracovat fakturu :number a vrátil přes Peppol odmítnutí.',
    'peppol_registration_incomplete_title' => 'Nedokončená registrace Peppol',
    'peppol_registration_incomplete_body' => 'Dokud nedokončíte registraci u Finanční správy, dodavatelé vám nemohou poslat e-fakturu. Do :deadline zbývá :days dní.',
    'peppol_credential_invalid_title' => 'Připojení na Peppol přestalo fungovat',
    'peppol_credential_invalid_body' => 'Pošťák :provider odmítl přístupové údaje tohoto účtu (:reason). Dokud je nezadáte znovu, nelze odeslat žádnou e-fakturu.',

    'subscription_payment_failed_title' => 'Platba selhala',
    'subscription_payment_failed_body' => 'Z vaší karty se nepodařilo strhnout :amount :currency. Aktualizujte platební metodu, aby vám předplatné zůstalo.',
    'subscription_suspended_title' => 'Předplatné pozastaveno',
    'subscription_suspended_body' => 'Předplatné :plan bylo pozastaveno, protože se nepodařilo inkasovat platbu — účet je na bezplatné úrovni.',
];
