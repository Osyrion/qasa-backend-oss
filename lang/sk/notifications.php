<?php

declare(strict_types=1);

return [

    // Invoicing
    'overdue_digest_title' => 'Faktúry po splatnosti',
    'overdue_digest_body' => 'Po splatnosti je nových :count faktúr v celkovej sume :amount.',
    'reminders_exhausted_title' => 'Upomienky vyčerpané',
    'reminders_exhausted_body' => 'Na faktúru :number bolo odoslaných všetkých :count automatických upomienok a stále nie je uhradená.',
    'quote_accepted_title' => 'Ponuka prijatá',
    'quote_accepted_body' => 'Klient prijal ponuku :number.',
    'quote_rejected_title' => 'Ponuka odmietnutá',
    'quote_rejected_body' => 'Klient odmietol ponuku :number.',

    // Taxation
    'filing_type_control_statement' => 'kontrolný výkaz DPH',
    'tax_filing_reminder_title' => 'Blíži sa termín podania',
    'tax_filing_reminder_body' => 'Podanie :type za obdobie :period má termín :due_date.',

    // Billing (subscriptions)
    'renewal_order_created_title' => 'Vystavená objednávka obnovy',
    'renewal_order_created_body' => 'Obnova predplatného :plan je pripravená na úhradu so splatnosťou :due_date.',
    'renewal_order_reminder_title' => 'Blíži sa splatnosť predplatného',
    'renewal_order_reminder_body' => 'Obnova predplatného :plan je splatná :due_date.',
    'subscription_expired_title' => 'Predplatné vypršalo',
    'subscription_expired_body' => 'Predplatné :plan vypršalo — účet je teraz obmedzený na bezplatnú úroveň.',

    'trial_ending_title' => 'Skúšobné obdobie sa končí',
    'trial_ending_body' => 'Bezplatné skúšobné obdobie končí o :days dní. Vyberte si plán a všetko nastavené vám zostane.',
    'trial_expired_title' => 'Skúšobné obdobie skončilo',
    'trial_expired_body' => 'Účet je späť na bezplatnej úrovni. Nič sa nezmazalo — klienti nad bezplatný limit sú len na čítanie, kým si predplatné nezaložíte.',

    // Integrations — outbound e-mail delivery
    'email_bounced_title' => 'E-mail sa nedoručil',
    'email_bounced_body' => 'Správu na adresu :recipient sa nepodarilo doručiť (:reason).',
    'email_spam_complaint_title' => 'E-mail označený ako spam',
    'email_spam_complaint_body' => 'Adresát :recipient označil vašu správu ako spam; ďalšie správy na túto adresu sa nedoručia.',

    // Integrations — e-fakturácia cez Peppol
    'peppol_dispatch_failed_title' => 'E-faktúra sa neodoslala',
    'peppol_dispatch_failed_body' => 'Faktúra :number sa do siete Peppol nedostala: :reason',
    'peppol_dispatch_abandoned_body' => 'Faktúra :number sa do siete Peppol nedostala ani po :attempts pokusoch a ďalej to už neskúšame: :reason',
    'peppol_invoice_rejected_title' => 'Odberateľ odmietol e-faktúru',
    'peppol_invoice_rejected_body' => 'Odberateľ odmietol spracovať faktúru :number a vrátil cez Peppol odmietnutie.',
    'peppol_registration_incomplete_title' => 'Nedokončená registrácia Peppol',
    'peppol_registration_incomplete_body' => 'Kým nedokončíte registráciu na Finančnej správe, dodávatelia vám nevedia poslať e-faktúru. Do :deadline zostáva :days dní.',
    'peppol_credential_invalid_title' => 'Pripojenie na Peppol prestalo fungovať',
    'peppol_credential_invalid_body' => 'Poštár :provider odmietol prístupové údaje tohto účtu (:reason). Kým ich nezadáte znova, nedá sa odoslať žiadna e-faktúra.',

    'subscription_payment_failed_title' => 'Platba zlyhala',
    'subscription_payment_failed_body' => 'Z vašej karty sa nepodarilo strhnúť :amount :currency. Aktualizujte platobnú metódu, aby vám predplatné zostalo.',
    'subscription_suspended_title' => 'Predplatné pozastavené',
    'subscription_suspended_body' => 'Predplatné :plan bolo pozastavené, lebo sa nepodarilo zinkasovať platbu — účet je na bezplatnej úrovni.',
];
