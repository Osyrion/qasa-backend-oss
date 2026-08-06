<?php

declare(strict_types=1);

return [

    // Invoicing
    'overdue_digest_title' => 'Invoices past due',
    'overdue_digest_body' => ':count invoice(s) have just passed their due date, for a total of :amount.',
    'reminders_exhausted_title' => 'Reminders exhausted',
    'reminders_exhausted_body' => 'Invoice :number has had all :count automatic reminders sent and is still unpaid.',
    'quote_accepted_title' => 'Quote accepted',
    'quote_accepted_body' => 'The client accepted quote :number.',
    'quote_rejected_title' => 'Quote rejected',
    'quote_rejected_body' => 'The client rejected quote :number.',

    // Taxation
    'filing_type_control_statement' => 'VAT control statement',
    'tax_filing_reminder_title' => 'Filing deadline approaching',
    'tax_filing_reminder_body' => 'The :type filing for :period is due on :due_date.',

    // Billing (subscriptions)
    'renewal_order_created_title' => 'Renewal order issued',
    'renewal_order_created_body' => 'Your :plan subscription renewal is ready to pay, due :due_date.',
    'renewal_order_reminder_title' => 'Subscription payment due',
    'renewal_order_reminder_body' => 'Your :plan subscription renewal is due on :due_date.',
    'subscription_expired_title' => 'Subscription expired',
    'subscription_expired_body' => 'Your :plan subscription has expired — the account is now limited to the free tier.',

    'trial_ending_title' => 'Trial is running out',
    'trial_ending_body' => 'Your free trial ends in :days day(s). Pick a plan to keep everything you have set up.',
    'trial_expired_title' => 'Trial has ended',
    'trial_expired_body' => 'The account is back on the free tier. Nothing was deleted — clients above the free limit are read-only until you subscribe.',

    // Integrations — outbound e-mail delivery
    'email_bounced_title' => 'E-mail was not delivered',
    'email_bounced_body' => 'The message to :recipient could not be delivered (:reason).',
    'email_spam_complaint_title' => 'E-mail marked as spam',
    'email_spam_complaint_body' => ':recipient marked a message from you as spam; further messages to that address will not be delivered.',

];
