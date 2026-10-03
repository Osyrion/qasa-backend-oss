<?php

declare(strict_types=1);

return [
    'idempotency_key_conflict' => 'Tento Idempotency-Key již byl použit s jiným tělem požadavku.',
    'idempotency_key_in_flight' => 'Požadavek s tímto Idempotency-Key se právě zpracovává. Zkuste to za chvíli znovu.',
    'unverified_sender' => 'Před odesláním e-mailu z tohoto účtu potvrďte svou e-mailovou adresu. Ověřovací odkaz jsme vám poslali.',
    'disposable_email_blocked' => 'Zaregistrujte se prosím s trvalou e-mailovou adresou — jednorázové schránky nepřijímáme.',
    'waitlist' => [
        'subscribed' => 'Děkujeme — ozveme se vám, jakmile spustíme betu.',
        'captcha_failed' => 'Nepodařilo se nám ověřit, že nejste robot. Zkuste to prosím znovu.',
        'invitation_subject' => 'Vaše pozvánka do bety Zoad',
        'invitation_greeting' => 'Máme pro vás místo.',
        'invitation_intro' => 'Přihlásili jste se, abychom vám dali vědět, až se beta Zoad otevře. Otevřela se a vaše místo je připravené.',
        'invitation_action' => 'Vytvořit účet',
        'invitation_expiry' => 'Odkaz platí :days dní. Pokud vyprší, napište nám a pošleme nový.',
        'invitation_ignore' => 'Pokud už o účet nemáte zájem, zprávu ignorujte — nic dalšího se nestane.',
        'invitations_sent' => 'Odesláno pozvánek: :count.',
    ],
];
