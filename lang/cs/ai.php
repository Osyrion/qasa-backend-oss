<?php

declare(strict_types=1);

/**
 * Transparentnost podle nařízení (EU) 2024/1689 (akt o umělé inteligenci),
 * čl. 50 — servíruje GET /api/v1/ai/transparency, jednotlivé výstupy
 * označuje AiOutputMarker. Slovní protějšek včetně klasifikace systému je
 * docs/legal/AI_ACT.md.
 */
return [
    'output_notice' => 'Vygenerováno modelem umělé inteligence z vašeho dokumentu nebo údajů. Před použitím si to zkontrolujte — může to být neúplné nebo chybné.',

    'transparency' => [
        'notice' => 'Některé části aplikace používají model umělé inteligence (velký jazykový model) ke čtení dokumentů a přípravě textů. AI je tu vždy jen pomocník: každý výsledek je návrh, který si sami zkontrolujete, opravíte a potvrdíte.',
        'human_oversight' => 'Žádný návrh AI se nepoužije sám od sebe. Přijatá faktura vznikne až potvrzením údajů a upomínka odejde teprve tehdy, když ji odešlete vy.',
        'training' => 'Vaše dokumenty a údaje se posílají poskytovateli modelu pouze k zodpovězení konkrétního požadavku a my je nepoužíváme k trénování modelů. Závazky samotného poskytovatele najdete v seznamu zpracovatelů.',
        'complaints' => 'Pokud je návrh chybný, prostě ho před potvrzením opravte — bez vás se nic nerozhodne. AI si můžete v nastavení úplně vypnout a stížnost můžete podat nám nebo národnímu orgánu dozoru nad trhem.',
    ],

    'features' => [
        'invoice_extraction' => [
            'name' => 'Čtení přijatých faktur (inbox)',
            'purpose' => 'Vyčte z nahrané faktury údaje — číslo, data, částky, rozpis DPH, bankovní spojení — abyste je do formuláře nemuseli přepisovat ručně.',
            'data_sent' => 'Nahraný dokument: jeho text a u skenů i obrázky stran. Může obsahovat osobní údaje třetí strany (dodavatele).',
            'opt_out' => 'V nastavení vypněte „AI vytěžování“; aplikace se vrátí ke čtení podle pravidel (regex) bez jakéhokoli externího volání.',
        ],
        'category_suggestion' => [
            'name' => 'Návrh kategorie výdaje',
            'purpose' => 'Navrhne kategorii výdaje. Modelu se ptáme teprve tehdy, když ve vaší vlastní historii výdajů není srovnatelný záznam.',
            'data_sent' => 'Popis výdaje, který jste napsali — před odesláním se z něj odstraní IBANy a rodná čísla.',
            'opt_out' => 'Kategorii si zvolte sami; návrh se nikdy nepoužije automaticky. Vypíná ho i celoúčtový přepínač AI.',
        ],
        'reminder_draft' => [
            'name' => 'Návrh textu upomínky',
            'purpose' => 'Připraví znění upomínky k faktuře po splatnosti, které si upravíte a odešlete.',
            'data_sent' => 'Jméno klienta, číslo faktury, částka, počet dní po splatnosti a počet již odeslaných upomínek.',
            'opt_out' => 'Návrh prostě nepoužijte — odesílá se vždy text, který jste schválili. Vypíná ho i celoúčtový přepínač AI.',
        ],
        'dashboard_summary' => [
            'name' => 'Shrnutí přehledu běžnou řečí',
            'purpose' => 'V několika větách vysvětlí čísla za aktuální měsíc.',
            'data_sent' => 'Pouze souhrnná čísla (počty a částky) — žádná jména klientů a žádný text, který jste napsali.',
            'opt_out' => 'Shrnutí si prostě nezobrazte. Vypíná ho i celoúčtový přepínač AI.',
        ],
    ],
];
