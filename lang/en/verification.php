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
        'salutation' => "Playfully,\nGregory for the Office des Coffres",
    ],
    'email_change' => [
        'subject'    => 'Confirm your new email address',
        'greeting'   => 'Hello,',
        'intro'      => 'You have requested to change the email address associated with your account on the Office des coffres. Confirm your new address by clicking the link below.',
        'expires'    => 'This link expires in :minutes minutes.',
        'not_you'    => 'If you did not make this request, ignore this email. Your current address remains unchanged.',
        'action'     => 'Confirm my new address',
        'salutation' => "Playfully,\nGregory for the Office des Coffres",
    ],
    'email_changed_notice' => [
        'subject'    => 'Your email address has been changed',
        'greeting'   => 'Hello,',
        'intro'      => 'The email address on your Office des coffres account has just been changed to: :new_email',
        'warning'    => 'If you did not make this change, please contact the administrator immediately.',
        'salutation' => "Playfully,\nGregory for the Office des Coffres",
    ],
    'password_reset' => [
        'subject'    => 'Reset your password',
        'greeting'   => 'Hello,',
        'intro'      => 'You asked to choose a new password for your account on the Office des coffres. Click the button below to choose it.',
        'expires'    => 'This link expires in :minutes minutes and can only be used once.',
        'not_you'    => 'If you did not make this request, ignore this email: your password stays unchanged.',
        'action'     => 'Choose a new password',
        'salutation' => "Playfully,\nGregory for the Office des coffres",
    ],
    'password_changed_notice' => [
        'subject'    => 'Your password has been changed',
        'greeting'   => 'Hello,',
        'intro'      => 'The password of your account on the Office des coffres has just been changed.',
        'warning'    => 'If you did not make this change, contact the administrator immediately.',
        'salutation' => "Playfully,\nGregory for the Office des coffres",
    ],
];
