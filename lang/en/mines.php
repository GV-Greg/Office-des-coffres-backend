<?php

// Mine Registry — API messages, sent in FR and EN (see lang/fr/mines.php).
return [
    'api' => [
        'mine_not_authorized' => "Only the province's Mines Superintendent and Sheriff can save a report, and the Office does not know this office for this character. An office is declared from the Profile page, then validated.",
        'mine_report_identical' => 'This report is already recorded for that date in the province of :province: nothing to replace.',
        'mine_report_less' => 'The report recorded for that date in the province of :province says more than this one: it is not replaced, so that nothing is lost.',
        'mine_not_reader' => "Only the province's Mines Superintendent, Sheriff and leader read its registry, and the Office does not know such an office for this character.",
        'mine_report_future' => 'This report carries a future date: it cannot be recorded.',
        'price_not_authorized' => "Only the province's Trade Minister, Mines Superintendent and Sheriff manage its prices, and the Office does not know this office for this character.",
        'price_not_reader' => "Only the province's Trade Minister, Mines Superintendent, Sheriff and leader read its prices, and the Office does not know such an office for this character.",
        'price_locked' => 'This price set can no longer be edited: it was set before your office, or your office ended more than :days days ago.',
        'price_not_found' => 'This price set does not exist in this province.',
        'price_exists' => 'A price set already exists for :date in this province: edit it instead of creating a second one.',
        'price_future' => 'A price set cannot carry a future date.',
        'price_outside_office' => 'This date does not fall within a period when you held one of the offices that manage this province\'s prices.',
        'mine_report_needs_confirmation' => 'A report already exists for :date in the province of :province. It will be replaced, not deleted: the old one stays visible in the history.',
    ],
    'prices_digest' => [
        'subject' => 'Mine prices of :province: :count change(s)',
        'greeting' => 'Hello,',
        'intro' => 'The mine prices of :province have changed since the last summary:',
        'line_created' => '- Prices effective :date, added by :author.',
        'line_updated' => '- Prices effective :date, updated by :author.',
        'unknown_author' => 'a character whose account was deleted',
        'action' => 'See the prices',
        'outro' => 'You receive this summary because you hold an office that manages these prices. Changes you made yourself are not listed.',
    ],
];
