<?php

declare(strict_types=1);

return [
    'payment_requires_open_invoice' => "A payment can only be recorded against an issued invoice — the invoice is in ':status' status.",
    'payment_not_for_credit_note' => 'A payment cannot be recorded against a credit note.',
    'cannot_email_cancelled_invoice' => 'A cancelled invoice cannot be emailed.',
    'client_email_missing_for_send' => 'The client has no email address. Add one or specify a recipient.',
    'only_draft_editable' => 'Only a draft invoice can be edited.',
    'template_not_paused' => 'Only paused templates can be resumed.',
    'template_not_active' => 'Only active templates can be paused.',
    'template_missing_owner' => 'Recurring template :id has no owner.',
    'template_paused_missing_client' => 'Recurring template :id was paused: its client is missing or deleted.',
    'corrective_must_be_credit_or_storno' => 'The corrective document must be a credit note or a storno.',
    'corrective_only_for_invoice' => 'A corrective document can only be issued for an invoice.',
    'storno_only_for_sent' => 'Only a sent invoice can be cancelled (storno).',
    'credit_note_only_for_sent_or_paid' => 'A credit note can only be issued for a sent or paid invoice.',
    'cannot_invoice_personal_order' => 'A personal order cannot be invoiced.',
    'order_no_billable_items' => 'The order has no billable items or uninvoiced time.',
    'items_only_for_draft' => 'Items can only be added to a draft invoice.',
    'work_report_only_editable_for_draft' => 'The work report can only be edited for a draft invoice.',
    'work_report_only_generatable_for_draft' => 'The work report can only be generated for a draft invoice.',
    'reminder_only_for_sent' => "A reminder can only be sent for a sent invoice — the invoice is in ':status' status.",
    'reminder_cooldown' => 'The next reminder can be sent on :next_allowed.',
    'client_email_missing_for_reminder' => 'The client has no email address on file.',
    'status_transition_not_allowed' => "The invoice cannot be changed from ':from' to ':to'.",
];
