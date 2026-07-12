<?php

declare(strict_types=1);

return [
    'currencies_must_differ' => 'The base and target currencies must be different.',
    'system_rates_not_deletable' => 'System exchange rates cannot be deleted.',
    'clockify_api_key_missing' => 'No Clockify API key is set in the profile.',
    'clockify_api_key_invalid' => 'The Clockify API key is invalid or the service is unavailable.',
    'clockify_workspace_missing' => 'No Clockify workspace is set.',
    'entry_already_invoiced' => 'This time entry has already been invoiced and can no longer be changed.',
    'timer_already_running' => 'A timer is already running. Stop it before starting a new one.',
    'timer_not_running' => 'This time entry has no running timer.',
    'item_not_in_order' => 'The selected order item does not belong to this order.',
    'csv_format_unknown' => 'Could not determine the CSV format. Supported formats: Toggl, Clockify.',
    'attachment_file_type_not_allowed' => 'This file type is not allowed.',
    'attachment_too_large' => 'The file exceeds the maximum allowed size of 20 MB.',
    'attachment_save_failed' => 'Failed to save the uploaded file.',
    'attachment_missing' => 'This expense has no attachment.',
];
