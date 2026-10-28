<?php

// Animation module — Crack the Code. Only what the API and the emails send in English (error messages, the type name,
// the rules text); the BBCode export is French-only by design (French-speaking game forum).
// Vocabulary (D34): "character" for a pseudo or a recorded name, "player" for the person (email, connection).
return [
    'api' => [
        'not_owner' => 'Only the organizer of this activity can manage it.',
        'access_denied' => 'Access denied: the address or the password does not match any activity you can manage.',
        'activity_not_found' => 'This activity does not exist.',
        'character_not_found' => 'This character does not exist on this account.',
        'type_inactive' => 'This type of activity is not open at the moment.',
        'slug_invalid' => 'The address must be :min to :max lowercase letters, no digits, with optional hyphens in the middle.',
        'slug_taken' => 'This address is already taken: pick another one.',
        'place_required' => 'Pick the activity\'s place: a kingdom, a province OR a city.',
        'place_exclusive' => 'Pick a kingdom, a province OR a city, not several.',
        'organizer_required' => 'Enter your character\'s pseudo: it is used to reach you, and it is shown as the organizer.',
        'password_too_short' => 'The password must be at least :min characters long.',
        'password_too_guessable' => 'The password cannot be the activity address or your pseudo.',
        'ip_limit' => 'An activity without an account is already open from your connection: close it (with its password) or ask the site administrator before creating another one.',
        'activity_already_active' => 'You already have an activity of this type in progress: close it before creating another one.',
        'reopen_blocked' => 'You have another activity of this type in progress: close it before reopening this one.',
        'reopen_anonymous' => 'An activity without an account cannot be reopened: its closing is final.',
        'activity_closed' => 'This activity is closed.',
        'activity_not_closed' => 'This activity is not closed.',
        'delete_requires_closed' => 'An activity can only be deleted once closed: close it first.',
        'delete_anonymous' => 'An activity without an account cannot be deleted: its summary stays online for five years. The site administrator can remove it.',
        'secret_pending' => 'A chest is already sealed and waiting to be cracked.',
        'secret_not_open' => 'This chest can no longer be cracked, or does not belong to this activity.',
        'combination_invalid' => 'A combination is exactly five digits.',
        'reset_invalid' => 'This link is no longer valid: ask for a new one.',
        'reset_unavailable' => 'No link can be issued for this activity (private or closed).',
        'email_confirm_invalid' => 'This address cannot be confirmed: the link has expired or the activity no longer exists.',
    ],

    'status' => [
        'new' => 'New',
        'launched' => 'In progress',
        'relaunch' => 'Relaunched',
        'closed' => 'Closed',
    ],

    'types' => [
        'decode' => [
            'name' => 'Crack the Code',
            'public_link_label' => 'Chest #:number to crack',
            'rules' => 'A chest is sealed with a secret five-digit combination. Send your guesses to the organizer; each one is scored: 2 points per correctly placed digit, 1 point per right digit in the wrong place. The first character to find the whole combination opens the chest, their name goes into the register, and a new chest is sealed at once.',
        ],
    ],

    // Emails (always bilingual, French then English). None contains a password.
    'email' => [
        'greeting' => 'Hello,',
        'confirm_subject' => 'Confirm your address — activity ":name"',
        'confirm_intro' => 'You gave this address for the activity ":name" of Crack the Code (address :slug).',
        'confirm_use' => 'Once confirmed, it will be used to send you a link if you forget your password, to warn you if that password is changed, and to send you the report when the activity is closed. It will be erased when the activity is closed.',
        'confirm_action' => 'Confirm my address',
        'confirm_ignore' => 'If this was not you, ignore this message: the address will not be used.',
        'reset_subject' => 'New password — activity ":name"',
        'reset_intro' => 'A password change was requested for the activity ":name".',
        'reset_valid' => 'The link below is valid for 24 hours and can only be used once. You will choose your new password yourself.',
        'reset_action' => 'Choose a new password',
        'reset_ignore' => 'If this was not you, ignore this message: your current password stays valid.',
        'changed_subject' => 'Password changed — activity ":name"',
        'changed_notice' => 'The password of the activity ":name" has just been changed.',
        'changed_alert' => 'If this was not you, warn the site administrator right away.',
        'report_subject' => 'Report — activity ":name"',
        'report_intro' => 'The activity ":name" is closed. Here is its full report: the site only keeps the summary, the guesses are erased.',
        'report_chest_opened' => 'Chest #:number — opened: :code (found by :character)',
        'report_chest_sealed' => 'Chest #:number — not cracked: :code',
        'report_attempt' => '— :combination — :character: :points pt(s)',
        'report_no_attempt' => '— no attempt',
        'report_ranking' => 'Final ranking',
        'report_ranking_line' => ':rank. :character — :points pt(s)',
        'report_page' => 'The summary stays online for :years years:',
        'report_action' => 'See the summary page',
    ],
];
