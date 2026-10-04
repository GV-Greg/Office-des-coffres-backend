<?php

// Sign-up confirmation email (App\Notifications\VerifyApiEmail), always French then English.
return [
    'email' => [
        'subject' => 'Office des coffres — confirm your email address',
        'greeting' => 'Hello,',
        'thanks' => 'Thank you for signing up to the Office des coffres. Confirm your address to activate your account.',
        'expires' => 'This link expires in :minutes minutes.',
        'not_you' => 'If you did not sign up, you can ignore this email.',
        'action' => 'Confirm my email',
        'salutation' => "Playfully,\nOffice des coffres",
    ],
];
