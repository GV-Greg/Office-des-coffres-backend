<?php

namespace App\Exceptions;

use RuntimeException;

/** Le personnage cible a déjà des réglages : la copie les REMPLACE, elle attend `confirm_replace` (409). */
class InventoryCopyNeedsConfirmation extends RuntimeException
{
    public function __construct(public readonly string $targetPseudo, public readonly string $sourcePseudo)
    {
        parent::__construct('inventory copy needs confirmation');
    }
}
