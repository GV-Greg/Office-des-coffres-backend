<?php

namespace App\Support;

/**
 * Les espèces du cheptel et la nourriture que chacune accepte (règles du jeu). Le serveur ne valide que la FORME : les coûts
 * (quantité prise, quantité perdue) sont calculés par le site des joueurs, seule source de la table des coûts.
 */
final class Livestock
{
    public const SPECIES = [
        'cow' => ['maize', 'wheat'],
        'goat' => ['maize', 'wheat'],
        'sheep' => ['maize', 'wheat'],
        'ewe' => ['maize', 'wheat'],
        'pig' => ['maize', 'wheat'],
        'bees' => ['marjoram', 'elderberry'],
    ];

    public const FEEDS = ['maize', 'wheat', 'marjoram', 'elderberry'];

    /** Nombre d'animaux par opération du jeu. */
    public const MAX_BATCH = 4;
}
