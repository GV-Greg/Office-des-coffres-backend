<?php

// Animation module — The Sealed Chests. Only what the API sends in English (error messages and the
// type name); the BBCode export is French-only by design (French-speaking game forum).
return [
    'api' => [
        'not_owner' => 'Only the organizer of this activity can manage it.',
        'activity_not_found' => 'This activity does not exist.',
        'character_not_found' => 'This character does not exist on this account.',
        'type_inactive' => 'This type of activity is not open at the moment.',
        'place_exclusive' => 'Pick a province OR a city, not both.',
        'activity_already_active' => 'You already have an activity of this type in progress: close it before creating another one.',
        'reopen_blocked' => 'You have another activity of this type in progress: close it before reopening this one.',
        'activity_closed' => 'This activity is closed.',
        'activity_not_closed' => 'This activity is not closed.',
        'delete_requires_closed' => 'An activity can only be deleted once closed: close it first.',
        'secret_pending' => 'A chest is already sealed and waiting to be cracked.',
        'secret_not_open' => 'This chest can no longer be cracked, or does not belong to this activity.',
        'combination_invalid' => 'A combination is exactly five digits.',
    ],

    'status' => [
        'new' => 'New',
        'launched' => 'In progress',
        'relaunch' => 'Relaunched',
        'closed' => 'Closed',
    ],

    'types' => [
        'decode' => [
            'name' => 'The Sealed Chests',
            'public_link_label' => 'Chest #:number to crack',
            'rules' => 'A chest is sealed with a secret five-digit combination. Send your guesses to the organizer; each one is scored: 2 points per correctly placed digit, 1 point per right digit in the wrong place. The first to find the whole combination opens the chest, their name goes into the register, and a new chest is sealed at once.',
        ],
    ],
];
