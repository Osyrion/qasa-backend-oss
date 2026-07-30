<?php

declare(strict_types=1);

return [
    'unsupported_country' => 'Aplikace momentálně obsluhuje pouze firmy a živnostníky se sídlem na Slovensku nebo v Česku.',
    'invalid_ico_format' => 'Neplatný formát IČO.',
    'invalid_dic_format' => 'Neplatný formát DIČ.',
    'invalid_vat_id_format' => 'Neplatný formát DIČ pro DPH.',
    'residency_locked' => 'Daňovou rezidenci již nelze změnit.',
    'ico_locked' => 'IČO již nelze změnit — na účtu existují vystavené doklady.',
    'residency_required' => 'Před pokračováním dokončete nastavení daňové rezidence.',
    'residency_already_set' => 'Daňová rezidence již byla pro tento účet nastavena.',

    'unsupported_tax_year' => 'Podklad pro daňové přiznání za rok :year momentálně není podporován.',
    'invalid_draft_payload' => 'Uložený rozpracovaný podklad má neplatný nebo poškozený formát.',
    'invalid_flat_rate_category' => 'Neplatná kategorie paušálních výdajů pro tuto zemi.',
    'foreign_income_note' => 'Tento podklad nepočítá zamezení dvojího zdanění u zahraničních příjmů — konzultujte daňového poradce.',
    'unconverted_amounts_note' => 'Některé platby se nepodařilo přepočítat na měnu přiznání (chybí kurzový záznam) a nejsou zahrnuty ve výše uvedených součtech.',
    'draft_not_found' => 'Pro tento rok nebyl nalezen žádný uložený rozpracovaný podklad.',
    'worksheet_disclaimer' => 'Toto je podklad/odhad pro přípravu, nikoli daňové přiznání. Údaje nenahrazují odborné poradenství — před podáním ověřte proti aktuální legislativě.',

    'pdf_title' => 'Podklad pro daňové přiznání — :year',
    'pdf_subtitle' => 'Tento dokument je pomůcka pro přípravu, nikoli podání.',
    'pdf_section_income' => 'Příjem z podnikání',
    'pdf_partial_tax_base_business' => 'Dílčí základ daně (podnikání)',
    'pdf_expenses_used' => 'Použité výdaje',
    'pdf_flat_rate' => 'paušální',
    'pdf_actual' => 'skutečné',
    'pdf_total_tax_base' => 'Celkový základ daně',
    'pdf_section_tax' => 'Daň',
    'pdf_tax_before_credits' => 'Daň před slevami',
    'pdf_tax_credits' => 'Daňové slevy',
    'pdf_child_tax_bonus' => 'Daňové zvýhodnění na dítě',
    'pdf_final_tax' => 'Výsledná daň',
    'pdf_advances_paid' => 'Již zaplacené zálohy',
    'pdf_tax_balance' => 'Rozdíl (kladné = nedoplatek, záporné = přeplatek)',
    'pdf_section_contributions' => 'Odhadované odvody',
    'pdf_contribution_social' => 'Sociální pojištění',
    'pdf_contribution_health' => 'Zdravotní pojištění',
    'pdf_generated_at' => 'Vygenerováno :date',
];
