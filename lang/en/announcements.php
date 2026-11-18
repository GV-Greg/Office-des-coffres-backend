<?php

// Seule la phrase de l'échéance part en anglais (le message du bandeau) : l'administration est en français.
return [
    'when' => [
        'date_format' => 'D MMMM',
        'time_format' => 'HH:mm',
        'exact' => 'on :date at :time',
        'exact_range' => 'on :date from :from to :to',
        'exact_days' => 'from :from at :from_time to :to at :to_time',
        'day' => 'on :date',
        'period' => 'from :from to :to',
        'this_week' => 'this week',
        'next_week' => 'next week',
    ],
];
