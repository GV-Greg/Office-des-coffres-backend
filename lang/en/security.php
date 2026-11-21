<?php

// Security module — customs officer API messages, sent in FR and EN (see lang/fr/security.php).
return [
    'api' => [
        'character_not_validated' => 'Only a character validated by the Office can apply to become a customs officer.',
        'already_active' => 'This character already has a pending request or an active customs officer access.',
        'not_pending' => 'This request is no longer pending.',
        'not_officer' => 'This character is not a customs officer.',
        'officer_not_found' => 'Customs officer request not found.',
        'not_authorized' => "Only the province's Constable and Sergeant validate customs officers. When neither is known, the Captain, the leader or a mayor of the province do, and the Office does not know such an office for this character.",
        'self_decision' => 'You cannot validate your own request.',
        'no_decider' => 'This request cannot be handled from here: no office known to the Office authorizes you to.',
        'inactivity_range' => 'The inactivity period is set between :min and :max days.',
    ],
    'email' => [
        'inactivity_subject' => 'Your customs officer access for :province will end',
        'greeting' => 'Hello,',
        'inactivity_detail' => 'You have not entered a defence report for a while: your customs officer access for the province of :province will end on :date unless you enter a new one. Entering a report resets the counter. You can apply again afterwards.',
        'action' => 'Open the Office des Coffres',
        'salutation' => 'Kind regards, the Office des Coffres',
    ],
];
