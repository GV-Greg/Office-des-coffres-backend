<?php

/*
| Mandates — English. LIMITED to what is actually rendered in English: the email to the player,
| always sent in French first, then English (arbitration Q5 and Greg's decision, 03/10/2026). The
| admin and the API stay in French (lang/fr/mandates.php). MandateLanguageTest fails when a key
| used by the email is missing here.
|
| Office titles were read IN GAME by Greg (02/10/2026), never translated: Connétable is
| « Sergeant », Prévôt des maréchaux is « Constable ».
*/

return [
    'offices' => [
        'leader' => 'Leader (Count, Duke…)',
        'commissaire_commerce' => 'Trade Minister',
        'commissaire_mines' => 'Mines Superintendent',
        'juge' => 'Judge',
        'prevot_des_marechaux' => 'Constable',
        'procureur' => 'Public Prosecutor',
        'connetable' => 'Sergeant',
        'capitaine' => 'Captain',
        'porte_parole' => 'Spokesperson',
        'bailli' => 'Sheriff',
    ],
    'no_office' => 'without an office',

    'post' => [
        'mayor' => 'Mayor of :place',
        'council' => 'County council of :place — :office',
    ],

    'reasons' => [
        'annonce_non_concluante' => 'The link provided does not confirm your election.',
        'poste_deja_occupe' => 'This office is already held for this location.',
        'personnage_non_valide' => 'This character has not yet been validated on the Office.',
        'date_incoherente' => 'The start date you entered does not match the announcement.',
        'demission' => 'Resignation.',
        // Terme du jeu, relevé en jeu par Greg le 03/10/2026 (fil mandats-lot1, tour 19).
        'retranchement' => 'Retrenchment.',
        'revolte' => 'Revolt.',
        'remplace' => 'Replaced by a new holder.',
        'retire_par_le_dirigeant' => 'Office withdrawn by the leader of the province.',
        'declaration_erronee' => 'The office recorded on the Office was declared in error.',
        'reattribue' => 'Office reassigned by the leader of the province.',
        'declare_par_le_joueur' => 'Change of office declared by the player.',
        'fin_du_mandat' => 'End of term.',
        'autre' => 'See the comment below.',
    ],

    'email' => [
        'subject' => 'Office des coffres — the office of :character',
        'greeting' => 'Hello,',
        'post_line' => ':character — :post',
        'approved' => 'Your request has been approved. The term of your character :character runs until :date (:date_jeu).',
        'approved_elected' => 'Your character will take office when the leader of the province (Count, Duke…) is elected. At that election, every office in the province is emptied until the leader makes appointments: that is the rule of the game, not an error of the Office.',
        'approved_title_dropped' => "Your character's office is to be declared from your profile, once the leader of the province has made the appointment.",
        'rejected' => 'Your request has not been approved. Reason: :reason',
        'revoked' => 'The Office has revoked the term of your character :character. Effective :date (:date_jeu). Reason: :reason',
        'handover_out' => 'The leader of the province has been elected: the term of your character :character ended on :date (:date_jeu).',
        'handover_in' => 'The leader of the province has been elected: your character :character has been sitting since :date (:date_jeu). Their office is to be declared once appointed.',
        'office_granted' => 'Office approved: :office.',
        'office_removed' => 'Your character :character now sits without an office. Reason: :reason',
        'office_rejected' => 'The office declaration of your character :character has not been approved. Reason: :reason They sit without an office.',
        'reason_corrected' => 'The reason for our decision of :date has been corrected: « :old » becomes « :new ».',
        'reason_corrected_reapply' => 'You may submit a new request from your profile.',
        'reminder_subject' => 'Office des coffres — term of :character to renew',
        'reminder' => 'The term of your character :character, :post, ended on :date (:date_jeu). Re-elected? Renew it from your profile.',
        'comment_fr' => "Administrator's comment (written in French):",
        'comment_en' => "Administrator's comment (written in English):",
        'action' => 'View my profile',
        'english_below' => 'English version below.',
        'salutation' => "Regards,\nOffice des coffres",
    ],

    // Refusals the API may send to a player, in FR AND EN (thread mandats-lot2, Q9). Same keys as
    // the French 'api' section; the parity test checks it.
    'api' => [
        'not_found' => 'Term not found.',
        'character_not_found' => 'Character not found.',
        'character_not_validated' => 'Your character must be validated before requesting an office.',
        'started_in_future' => 'The start date cannot be in the future.',
        'dead_born' => 'This term has already ended on the date given.',
        'already_holding' => 'This character already has a term or a pending request at this level.',
        'not_pending' => 'Only a pending request can be cancelled.',
        'not_renewable' => 'This term can no longer be renewed: it must be active, extended, or ended :days days ago at most.',
        'office_needs_running_mandate' => 'An office can only be declared on a council term currently in office.',
        'same_office' => 'You already hold this office.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
    ],
];
