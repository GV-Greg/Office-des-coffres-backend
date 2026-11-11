<?php

// Inventories — API messages (thread admin/echanges/inventaire-scopes; Greg, 11/11/2026). Sent in FR and EN by
// MandateApiErrors: the frontend shows `messages[its language]`, it has no code → text table.
return [
    'api' => [
        'city_not_authorized' => 'Only the serving mayor manages their city\'s inventory settings, and the Office knows of no current mayor mandate for this character.',
        'province_not_authorized' => 'Only the serving Trade Minister manages their province\'s settings and inventory, and the Office does not know of this office for this character.',
        'province_not_reader' => 'Only the Trade Minister and the leader of the province read its inventory, and the Office does not know of this office for this character.',
        'level_unknown' => 'This inventory level does not exist.',
        'version_conflict' => 'These settings were changed elsewhere since you last read them: reload them before changing them.',
        'document_unknown_key' => 'These settings contain an unknown key: ":key".',
        'document_malformed' => 'These settings are malformed at ":path".',
        'document_too_many' => 'Too many items at ":path" (at most :max).',
        'document_too_large' => 'These settings are too large.',
        'inventory_empty' => 'The inventory text is empty.',
        'inventory_too_large' => 'This inventory text is too large.',
        'copy_source_not_found' => 'The character to copy does not exist on your account.',
        'copy_same_character' => 'Pick a character other than this one: a character cannot be copied onto itself.',
        'copy_source_empty' => 'The character :source has no inventory settings to copy.',
        'copy_needs_confirmation' => ':target already has inventory settings: they will be replaced by those of :source. Confirm to continue.',
    ],
];
