<?php

declare(strict_types=1);

return [
    'name_surname_required' => 'Jméno a příjmení jsou povinné pro typ klienta :client_type.',
    'company_name_required' => 'Název firmy je povinný pro firemního klienta.',
    'has_active_invoices' => 'Klienta nelze smazat, protože má aktivní faktury. Nejprve tyto faktury zrušte nebo archivujte.',
    'contact_persons_only_for_company' => 'Kontaktní osoby lze přidat pouze firemním klientům.',
    'max_contact_persons_reached' => 'Klient může mít nejvýše :max kontaktních osob.',
    'registry_unsupported_country' => 'Vyhledání firmy není podporováno pro zemi :country.',
    'company_not_found' => 'Pro IČO :ico nebyla nalezena žádná firma.',
    'vat_check_unavailable' => 'DIČ se nepodařilo ověřit, protože služba VIES je momentálně nedostupná.',
    'role_required' => 'Klient musí být odběratel, dodavatel, nebo obojí.',
    'limit_reached' => 'Dosáhli jste limitu klientů pro váš plán. Pro přidání dalších klientů si vylepšete plán.',
    'customer_limit_reached' => 'Dosáhli jste limitu odběratelů pro váš plán. Pro přidání dalších odběratelů si vylepšete plán.',
    'vendor_limit_reached' => 'Dosáhli jste limitu dodavatelů pro váš plán. Pro přidání dalších dodavatelů si vylepšete plán.',
    'locked_readonly' => 'Tento klient je pouze pro čtení, protože přesahuje limity vašeho plánu. Pro další práci s ním si vylepšete plán.',
    'reverse_charge_requires_vat_id' => 'Tuzemské přenesení daňové povinnosti vyžaduje, aby měl klient vyplněné DIČ.',
    'archived' => 'Tento klient je archivovaný. Před vytvořením nových dokladů ho nejprve obnovte.',
];
