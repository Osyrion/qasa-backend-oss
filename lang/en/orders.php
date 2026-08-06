<?php

declare(strict_types=1);

return [
    'cannot_delete_invoiced' => 'This order cannot be deleted because it contains invoiced items.',
    'notes_delete_own_only' => 'You can only delete your own notes.',
    'item_not_deletable_invoiced' => 'This item cannot be deleted — the time entry has already been invoiced.',
    'attachment_file_type_not_allowed' => 'This file type is not allowed.',
    'attachment_too_large' => 'The file is too large. Maximum size is 20MB.',
    'attachment_save_failed' => 'Failed to save the file.',
    'limit_reached' => 'You have reached your plan\'s order limit. Upgrade your plan to add more orders.',
    'status_not_editable' => 'Order with status :status cannot be edited.',
    'personal_order_cannot_have_rate' => 'A personal order (without a client) cannot have a rate set.',
    'billable_type_requires_rate' => 'A billable order of type :type must have a rate set.',
];
