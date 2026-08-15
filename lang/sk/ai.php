<?php

declare(strict_types=1);

/**
 * Transparentnosť podľa nariadenia (EÚ) 2024/1689 (akt o umelej
 * inteligencii), čl. 50 — servíruje GET /api/v1/ai/transparency, jednotlivé
 * výstupy označuje AiOutputMarker. Slovný náprotivok vrátane klasifikácie
 * systému je docs/legal/AI_ACT.md.
 */
return [
    'output_notice' => 'Vygenerované modelom umelej inteligencie z vášho dokumentu alebo údajov. Pred použitím si to prekontrolujte — môže byť neúplné alebo nesprávne.',

    'transparency' => [
        'notice' => 'Niektoré časti aplikácie používajú model umelej inteligencie (veľký jazykový model) na čítanie dokumentov a prípravu textov. AI je tu vždy len pomocník: každý výsledok je návrh, ktorý si sami skontrolujete, opravíte a potvrdíte.',
        'human_oversight' => 'Žiadny návrh AI sa nepoužije sám od seba. Prijatá faktúra vznikne až potvrdením údajov a upomienka odíde až vtedy, keď ju odošlete vy.',
        'training' => 'Vaše dokumenty a údaje sa posielajú poskytovateľovi modelu iba na zodpovedanie konkrétnej požiadavky a my ich nepoužívame na trénovanie modelov. Záväzky samotného poskytovateľa nájdete v zozname sprostredkovateľov.',
        'complaints' => 'Ak je návrh nesprávny, jednoducho ho pred potvrdením opravte — bez vás sa nič nerozhodne. AI si viete v nastaveniach úplne vypnúť a sťažnosť môžete podať nám alebo národnému orgánu dohľadu nad trhom.',
    ],

    'features' => [
        'invoice_extraction' => [
            'name' => 'Čítanie prijatých faktúr (inbox)',
            'purpose' => 'Vyčíta z nahranej faktúry údaje — číslo, dátumy, sumy, rozpis DPH, bankové spojenie — aby ste ich do formulára nemuseli prepisovať ručne.',
            'data_sent' => 'Nahraný dokument: jeho text a pri skenoch aj obrázky strán. Môže obsahovať osobné údaje tretej strany (dodávateľa).',
            'opt_out' => 'V nastaveniach vypnite „AI vyťažovanie“; aplikácia sa vráti k čítaniu podľa pravidiel (regex) bez akéhokoľvek externého volania.',
        ],
        'category_suggestion' => [
            'name' => 'Návrh kategórie výdavku',
            'purpose' => 'Navrhne kategóriu výdavku. Model sa pýtame až vtedy, keď vo vašej vlastnej histórii výdavkov nie je porovnateľný záznam.',
            'data_sent' => 'Popis výdavku, ktorý ste napísali — pred odoslaním sa z neho odstránia IBAN-y a rodné čísla.',
            'opt_out' => 'Kategóriu si zvoľte sami; návrh sa nikdy nepoužije automaticky. Vypína ho aj celoúčtový prepínač AI.',
        ],
        'reminder_draft' => [
            'name' => 'Návrh textu upomienky',
            'purpose' => 'Pripraví znenie upomienky k faktúre po splatnosti, ktoré si upravíte a odošlete.',
            'data_sent' => 'Meno klienta, číslo faktúry, suma, počet dní po splatnosti a počet už odoslaných upomienok.',
            'opt_out' => 'Návrh jednoducho nepoužite — odosiela sa vždy text, ktorý ste schválili. Vypína ho aj celoúčtový prepínač AI.',
        ],
        'dashboard_summary' => [
            'name' => 'Zhrnutie prehľadu v bežnej reči',
            'purpose' => 'V niekoľkých vetách vysvetlí čísla za aktuálny mesiac.',
            'data_sent' => 'Iba súhrnné čísla (počty a sumy) — žiadne mená klientov a žiadny text, ktorý ste napísali.',
            'opt_out' => 'Zhrnutie si jednoducho nezobrazte. Vypína ho aj celoúčtový prepínač AI.',
        ],
    ],
];
