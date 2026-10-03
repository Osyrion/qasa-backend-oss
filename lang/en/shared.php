<?php

declare(strict_types=1);

return [
    'idempotency_key_conflict' => 'This Idempotency-Key was already used with a different request body.',
    'idempotency_key_in_flight' => 'A request with this Idempotency-Key is still being processed. Retry shortly.',
    'unverified_sender' => 'Confirm your e-mail address before sending mail from this account. We have sent you a verification link.',
    'disposable_email_blocked' => 'Please register with a permanent email address — disposable inboxes are not accepted.',
    'waitlist' => [
        'subscribed' => "Thanks — we'll email you when the beta opens.",
        'captcha_failed' => 'We could not verify you are not a robot. Please try again.',
        'invitation_subject' => 'Your Zoad beta invitation',
        'invitation_greeting' => 'There is room for you.',
        'invitation_intro' => 'You asked to be told when the Zoad beta opened. It has, and your place is ready.',
        'invitation_action' => 'Create your account',
        'invitation_expiry' => 'The link works for :days day(s). Ask us for a new one if it runs out.',
        'invitation_ignore' => 'If you no longer want an account, ignore this message and nothing further will happen.',
        'invitations_sent' => ':count invitation(s) sent.',
    ],
];
