<?php

declare(strict_types=1);

return [
    'unsupported_country' => 'Aplikácia momentálne obsluhuje iba firmy a živnostníkov so sídlom v SR alebo ČR.',
    'invalid_ico_format' => 'Neplatný formát IČO.',
    'invalid_dic_format' => 'Neplatný formát DIČ.',
    'invalid_vat_id_format' => 'Neplatný formát IČ DPH.',
    'residency_locked' => 'Daňovú rezidenciu už nie je možné zmeniť.',
    'ico_locked' => 'IČO už nie je možné zmeniť, na účte existujú vystavené doklady.',
    'residency_required' => 'Pred pokračovaním dokonči nastavenie daňovej rezidencie.',
    'residency_already_set' => 'Daňová rezidencia už bola pre tento účet nastavená.',

    'unsupported_tax_year' => 'Podklad pre daňové priznanie za rok :year nie je momentálne podporovaný.',
    'invalid_draft_payload' => 'Rozpracovaný podklad má neplatný alebo poškodený formát.',
    'invalid_flat_rate_category' => 'Neplatná kategória paušálnych výdavkov pre túto krajinu.',
    'foreign_income_note' => 'Tento podklad nepočíta zamedzenie dvojitého zdanenia pri zahraničných príjmoch — konzultujte daňového poradcu.',
    'unconverted_amounts_note' => 'Niektoré platby sa nepodarilo prepočítať do meny priznania (chýba kurzový záznam) a nie sú zahrnuté vo vyššie uvedených súčtoch.',
    'draft_not_found' => 'Pre tento rok nebol nájdený žiadny rozpracovaný podklad.',
    'worksheet_disclaimer' => 'Toto je podklad/odhad na prípravu, nie daňové priznanie. Údaje nenahrádzajú odborné poradenstvo — pred podaním overte voči aktuálnej legislatíve.',

    'pdf_title' => 'Podklad pre daňové priznanie — :year',
    'pdf_subtitle' => 'Tento dokument je pomôcka na prípravu, nie podanie.',
    'pdf_section_income' => 'Príjem z podnikania',
    'pdf_partial_tax_base_business' => 'Čiastkový základ dane (podnikanie)',
    'pdf_expenses_used' => 'Použité výdavky',
    'pdf_flat_rate' => 'paušálne',
    'pdf_actual' => 'skutočné',
    'pdf_total_tax_base' => 'Celkový základ dane',
    'pdf_section_tax' => 'Daň',
    'pdf_tax_before_credits' => 'Daň pred úľavami',
    'pdf_tax_credits' => 'Daňové úľavy',
    'pdf_child_tax_bonus' => 'Daňový bonus na dieťa',
    'pdf_final_tax' => 'Výsledná daň',
    'pdf_advances_paid' => 'Už zaplatené preddavky',
    'pdf_tax_balance' => 'Rozdiel (kladné = doplatok, záporné = preplatok)',
    'pdf_section_contributions' => 'Odhadované odvody',
    'pdf_contribution_social' => 'Sociálne poistenie',
    'pdf_contribution_health' => 'Zdravotné poistenie',
    'pdf_generated_at' => 'Vygenerované :date',
];
