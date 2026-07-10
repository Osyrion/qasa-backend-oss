<?php

declare(strict_types=1);

return [
    'name_surname_required' => 'Name and surname are required for the :client_type client type.',
    'company_name_required' => 'Company name is required for a company client.',
    'has_active_invoices' => 'The client cannot be deleted because it has active invoices. Cancel or archive those invoices first.',
    'contact_persons_only_for_company' => 'Contact persons can only be added to company clients.',
    'max_contact_persons_reached' => 'A client can have at most :max contact persons.',
    'registry_unsupported_country' => 'Company lookup is not supported for country :country.',
    'company_not_found' => 'No company was found for IČO :ico.',
    'vat_check_unavailable' => 'The VAT number could not be verified because the VIES service is currently unavailable.',
    'role_required' => 'A client must be a customer, a vendor, or both.',
];
