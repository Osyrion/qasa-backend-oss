<?php

declare(strict_types=1);

return [
    'name_surname_required' => 'Name and surname are required for the :client_type client type.',
    'company_name_required' => 'Company name is required for a company client.',
    'has_active_invoices' => 'The client cannot be deleted because it has active invoices. Cancel or archive those invoices first.',
    'anonymized' => 'The client\'s personal data has been erased. Issued documents keep the details they were issued with.',
    'contact_persons_only_for_company' => 'Contact persons can only be added to company clients.',
    'max_contact_persons_reached' => 'A client can have at most :max contact persons.',
    'registry_unsupported_country' => 'Company lookup is not supported for country :country.',
    'company_not_found' => 'No company was found for IČO :ico.',
    'vat_check_unavailable' => 'The VAT number could not be verified because the VIES service is currently unavailable.',
    'role_required' => 'A client must be a customer, a vendor, or both.',
    'limit_reached' => 'You have reached your plan\'s client limit. Upgrade your plan to add more clients.',
    'customer_limit_reached' => 'You have reached your plan\'s customer limit. Upgrade your plan to add more customers.',
    'vendor_limit_reached' => 'You have reached your plan\'s vendor limit. Upgrade your plan to add more vendors.',
    'locked_readonly' => 'This client is read-only because it exceeds your plan\'s limits. Upgrade your plan to work with it again.',
    'reverse_charge_requires_vat_id' => 'Domestic reverse charge requires the client to have a VAT ID.',
    'archived' => 'This client is archived. Restore it before creating new documents for it.',
];
