<?php

declare(strict_types=1);

return [
    'validation' => [
        'not_aligned' => 'Times must align to :minutes-minute slots.',
        'min_duration' => 'The event must last at least :minutes minutes.',
        'ends_before_starts' => 'The event must end after it starts.',
        'must_end_same_day' => 'The event must end no later than midnight of the day it starts.',
    ],

    'import' => [
        'unsupported_format' => 'Could not determine the file format.',
        'recurring_not_supported' => 'Recurring events are not supported and were skipped.',
        'multi_day_not_supported' => 'Multi-day events are not supported and were skipped.',
        'crosses_midnight' => 'The event crosses midnight and was skipped.',
    ],
];
