<?php

declare(strict_types=1);

return [
    'name_surname_required' => 'Meno a priezvisko sú povinné pre typ klienta :client_type.',
    'company_name_required' => 'Názov firmy je povinný pre firemného klienta.',
    'has_active_invoices' => 'Klienta nie je možné zmazať, pretože má aktívne faktúry. Najprv zrušte alebo archivujte faktúry.',
    'contact_persons_only_for_company' => 'Kontaktné osoby je možné pridať len firemným klientom.',
    'max_contact_persons_reached' => 'Klient môže mať maximálne :max kontaktné osoby.',
    'registry_unsupported_country' => 'Vyhľadanie firmy nie je podporované pre krajinu :country.',
    'company_not_found' => 'Pre IČO :ico sa nenašla žiadna firma.',
    'vat_check_unavailable' => 'IČ DPH sa nepodarilo overiť, pretože služba VIES je momentálne nedostupná.',
    'role_required' => 'Klient musí byť odberateľ, dodávateľ alebo oboje.',
    'reverse_charge_requires_vat_id' => 'Tuzemské prenesenie daňovej povinnosti vyžaduje, aby mal klient vyplnené IČ DPH.',
];
