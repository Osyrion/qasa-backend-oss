<?php

declare(strict_types=1);

return [
    'idempotency_key_conflict' => 'Tento Idempotency-Key už bol použitý s iným telom požiadavky.',
    'idempotency_key_in_flight' => 'Požiadavka s týmto Idempotency-Key sa práve spracúva. Skúste o chvíľu znova.',
    'unverified_sender' => 'Pred odoslaním e-mailu z tohto účtu potvrďte svoju e-mailovú adresu. Overovací odkaz sme vám poslali.',
    'disposable_email_blocked' => 'Zaregistrujte sa prosím s trvalou e-mailovou adresou — jednorazové schránky neprijímame.',
    'waitlist' => [
        'subscribed' => 'Ďakujeme — ozveme sa vám, keď spustíme betu.',
        'captcha_failed' => 'Nepodarilo sa nám overiť, že nie ste robot. Skúste to prosím znova.',
        'invitation_subject' => 'Vaša pozvánka do bety Zoad',
        'invitation_greeting' => 'Máme pre vás miesto.',
        'invitation_intro' => 'Prihlásili ste sa, aby sme vám dali vedieť, keď sa beta Zoad otvorí. Otvorila sa a vaše miesto je pripravené.',
        'invitation_action' => 'Vytvoriť účet',
        'invitation_expiry' => 'Odkaz platí :days dní. Ak vyprší, napíšte nám a pošleme nový.',
        'invitation_ignore' => 'Ak už o účet nemáte záujem, správu ignorujte — nič ďalšie sa nestane.',
        'invitations_sent' => 'Odoslaných pozvánok: :count.',
    ],
];
