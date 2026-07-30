<?php

declare(strict_types=1);

return [
    'unsupported_country' => 'The application currently only serves companies and freelancers based in Slovakia or Czechia.',
    'invalid_ico_format' => 'Invalid IČO format.',
    'invalid_dic_format' => 'Invalid DIČ format.',
    'invalid_vat_id_format' => 'Invalid VAT ID format.',
    'residency_locked' => 'Tax residency can no longer be changed.',
    'ico_locked' => 'IČO can no longer be changed — documents already exist on this account.',
    'residency_required' => 'Complete your tax residency setup before continuing.',
    'residency_already_set' => 'Tax residency has already been set for this account.',

    'unsupported_tax_year' => 'The tax return worksheet for :year is not currently supported.',
    'invalid_draft_payload' => 'The saved draft has an invalid or corrupted format.',
    'invalid_flat_rate_category' => 'Invalid flat-rate expense category for this country.',
    'foreign_income_note' => 'This worksheet does not compute double-taxation relief for foreign income — consult a tax advisor.',
    'unconverted_amounts_note' => 'Some payments could not be converted to the filing currency (no exchange rate on record) and are excluded from the totals above.',
    'draft_not_found' => 'No saved draft was found for this year.',
    'worksheet_disclaimer' => 'This is a worksheet/estimate to help you prepare, not a tax filing. Figures are not a substitute for professional advice — verify against current legislation before filing.',

    'pdf_title' => 'Tax return worksheet — :year',
    'pdf_subtitle' => 'This document is a preparation aid, not a filing.',
    'pdf_section_income' => 'Business income',
    'pdf_partial_tax_base_business' => 'Partial tax base (business)',
    'pdf_expenses_used' => 'Expenses used',
    'pdf_flat_rate' => 'flat-rate',
    'pdf_actual' => 'actual',
    'pdf_total_tax_base' => 'Total tax base',
    'pdf_section_tax' => 'Tax',
    'pdf_tax_before_credits' => 'Tax before credits',
    'pdf_tax_credits' => 'Tax credits',
    'pdf_child_tax_bonus' => 'Child tax bonus',
    'pdf_final_tax' => 'Final tax',
    'pdf_advances_paid' => 'Advances already paid',
    'pdf_tax_balance' => 'Balance (owed if positive, overpaid if negative)',
    'pdf_section_contributions' => 'Estimated contributions',
    'pdf_contribution_social' => 'Social insurance',
    'pdf_contribution_health' => 'Health insurance',
    'pdf_generated_at' => 'Generated :date',
];
