<?php

// Sign-up confirmation email (App\Notifications\VerifyApiEmail), always French then English.
return [
    'email' => [
        'subject' => 'Confirm your email address',
        'greeting' => 'Hello,',
        'thanks' => 'Thank you for signing up to the Office des coffres. Confirm your address to activate your account.',
        'expires' => 'This link expires in :minutes minutes.',
        'not_you' => 'If you did not sign up, you can ignore this email.',
        'action' => 'Confirm my email',
        'salutation' => "Playfully,\nOffice des coffres",
    ],
    'email_change' => [
        'subject'    => 'Confirm your new email address',
        'greeting'   => 'Hello,',
        'intro'      => 'You have requested to change the email address associated with your account on the Office des coffres. Confirm your new address by clicking the link below.',
        'expires'    => 'This link expires in :minutes minutes.',
        'not_you'    => 'If you did not make this request, ignore this email. Your current address remains unchanged.',
        'action'     => 'Confirm my new address',
        'salutation' => "Playfully,\nOffice des coffres",
    ],
    'email_changed_notice' => [
        'subject'    => 'Your email address has been changed',
        'greeting'   => 'Hello,',
        'intro'      => 'The email address on your Office des coffres account has just been changed to: :new_email',
        'warning'    => 'If you did not make this change, please contact the administrator immediately.',
        'salutation' => "Playfully,\nOffice des coffres",
    ],
];
