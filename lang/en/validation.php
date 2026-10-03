<?php

/*
| English validation: the framework already provides every message. This file only adds readable
| field names for the mandates API, whose errors are sent in FR AND EN (thread mandats-lot2, Q10) —
| otherwise « started_at » would read « The started at field is required. ».
*/

return [
    'attributes' => [
        'level' => 'level',
        'city_id' => 'city',
        'province_id' => 'province',
        'council_office_key' => 'office',
        'started_at' => 'start date',
        'announcement_url' => 'announcement link',
    ],
];
