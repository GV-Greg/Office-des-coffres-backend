<?php

// Birth Register: refusals a player may receive (French and English, same keys).
return [
    'api' => [
        'not_reader' => "The province's Birth Register is reserved for the Sheriff and the leader.",
        'not_recorder' => 'Only the Sheriff in office records or withdraws a birth.',
        'not_configurer' => "Only the Sheriff and the leader set the province's species.",
        'not_configured' => 'No species is set for the province: set them in My office first.',
        'species_not_raised' => 'This species is not raised in the province.',
        'bad_feed' => 'This food does not suit this species.',
        'birth_outside_term' => 'A birth is recorded for a day of your term, up to today.',
        'withdraw_closed' => 'A birth is withdrawn on the game day you recorded it, and only your own.',
        'price_not_authorized' => 'Only the Sheriff sets the price of livestock food.',
        'price_locked' => 'This price can no longer be edited: your term has ended, or it dates from before your office.',
        'price_future' => 'A price cannot take effect in the future.',
        'price_outside_office' => 'This date is outside your term.',
        'price_exists' => 'A price set already exists on :date.',
    ],
];
