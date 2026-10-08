<?php

namespace App\Services;

use App\Models\MineReport;
use App\Models\Province;
use RuntimeException;

/**
 * Un relevé du jour existe déjà dans la province et le client n'a pas confirmé le remplacement (fil
 * registre-mines, R3 : « remplacé, pas effacé », l'écran nomme l'auteur remplacé). Rendue en 409 par
 * le contrôleur, avec de quoi construire la confirmation.
 */
class MineReplacementNeedsConfirmation extends RuntimeException
{
    public function __construct(public readonly MineReport $existing, public readonly Province $province)
    {
        parent::__construct('Remplacement du relevé du jour à confirmer.');
    }
}
