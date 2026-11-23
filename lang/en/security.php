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
        'no_security_role' => 'The Office knows no role for this character in a province\'s security.',
        'reports_not_authorized' => 'Only the Constable, the Sergeant, the Captain, the mayor (for their town) and validated customs officers enter a report, and the Office does not know this role for this character.',
        'reports_not_readable' => 'The Office knows no role for this character that reads this town\'s reports.',
        'province_not_readable' => 'The Office knows no role for this character that reads the reports of several towns of their province.',
        'city_not_in_scope' => 'This role does not enter reports for this town.',
        'report_exists' => 'A report already exists for this town on that day: only the Constable and the Sergeant correct it.',
        'report_date_not_allowed' => 'Only the Constable and the Sergeant enter a day other than the current game day.',
        'report_date_out_of_range' => 'This date is not allowed: from :days game days back up to today, never in the future.',
        'report_guet_required' => 'The watch paste is required for a first report.',
        'report_section_exists' => 'This section is already entered for that day: only the Constable and the Sergeant replace it.',
        'parser_outdated' => 'Your page is too old for this paste: reload the site.',
        'lists_not_readable' => 'The Office knows no role for this character that reads their province\'s red and green lists.',
        'lists_not_authorized' => 'The lists are kept by the Constable, the Sergeant and the Captain; when none of them is known to the Office, by mayors and validated customs officers. The Judge only adds to the red list.',
        'watchlist_entry_exists' => 'This nickname is already in the province\'s lists.',
        'watchlist_entry_not_found' => 'This entry does not exist in your province\'s lists.',
    ],
    'email' => [
        'inactivity_subject' => 'Your customs officer access for :province will end',
        'greeting' => 'Hello,',
        'inactivity_detail' => 'You have not entered a defence report for a while: your customs officer access for the province of :province will end on :date unless you enter a new one. Entering a report resets the counter. You can apply again afterwards.',
        'action' => 'Open the Office des Coffres',
        'salutation' => 'Kind regards, the Office des Coffres',
    ],
];
