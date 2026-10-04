<?php

// Only what reaches the player (the emails). Command and dashboard output: French only.
return [
    'email' => [
        'greeting' => 'Hello,',
        'salutation' => "Playfully,\nOffice des coffres",

        'inactive_subject' => 'Office des coffres — your account will be deleted for inactivity',
        'inactive_notice' => 'Your Office des coffres account has not been used since :last_seen. As stated in the privacy policy, an inactive account is deleted after a notice: unless you log in, yours will be deleted from :date, together with its characters.',
        'inactive_keep' => 'To keep it, simply log in before :date.',
        'history_kept' => 'The public history of the posts held by your characters is kept, as stated in the privacy policy.',
        'inactive_action' => 'Log in',

        'unverified_subject' => 'Office des coffres — confirm your address, or the account will be deleted',
        'unverified_notice' => 'An Office des coffres account was created with this address on :created, but the address was never confirmed. Without confirmation, the account will be deleted from :date.',
        'unverified_not_you' => 'If you did not sign up, ignore this email: the account will disappear on its own.',
        'unverified_action' => 'Confirm my email',
    ],
];
