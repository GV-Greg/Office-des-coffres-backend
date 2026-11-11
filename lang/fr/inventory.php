<?php

// Inventaires — messages de l'API (fil admin/echanges/inventaire-scopes ; Greg, 11/11/2026). Envoyés en FR et EN par
// MandateApiErrors : le frontend affiche `messages[sa langue]`, il n'a aucune table code → texte.
return [
    'api' => [
        'city_not_authorized' => "Seul le maire en fonction gère les réglages d'inventaire de sa ville, et l'Office ne connaît pas de mandat de maire en cours pour ce personnage.",
        'province_not_authorized' => "Seul le commissaire au commerce en fonction gère les réglages et l'inventaire de sa province, et l'Office ne connaît pas ce poste pour ce personnage.",
        'province_not_reader' => "Seuls le commissaire au commerce et le dirigeant de la province consultent son inventaire, et l'Office ne connaît pas ce poste pour ce personnage.",
        'level_unknown' => "Ce niveau d'inventaire n'existe pas.",
        'version_conflict' => 'Ces réglages ont été modifiés ailleurs depuis votre dernière lecture : rechargez-les avant de les modifier.',
        'document_unknown_key' => 'Ces réglages contiennent une clé inconnue : « :key ».',
        'document_malformed' => 'Ces réglages sont mal formés à l\'endroit « :path ».',
        'document_too_many' => 'Trop d\'éléments à l\'endroit « :path » (au plus :max).',
        'document_too_large' => 'Ces réglages sont trop volumineux.',
        'inventory_empty' => "Le texte de l'inventaire est vide.",
        'inventory_too_large' => "Ce texte d'inventaire est trop volumineux.",
        'copy_source_not_found' => "Le personnage à copier n'existe pas sur votre compte.",
        'copy_same_character' => 'Choisissez un autre personnage que celui-ci : on ne copie pas un personnage sur lui-même.',
        'copy_source_empty' => 'Le personnage :source n\'a aucun réglage d\'inventaire à copier.',
        'copy_needs_confirmation' => ':target a déjà des réglages d\'inventaire : ils seront remplacés par ceux de :source. Confirmez pour continuer.',
    ],
];
