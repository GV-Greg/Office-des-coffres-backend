<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Un inventaire du jour existe déjà au registre (ville ou province) et le nouveau texte diffère : l'enregistrer le REMPLACE
 * (rien ne s'efface). L'écriture attend `confirm_replace` (409, fil registre-stocks Q6 : même contrat que les mines). Porte
 * l'exemplaire en vigueur pour que le contrôleur nomme son auteur dans la confirmation (Q5).
 */
class InventoryReplacementNeedsConfirmation extends RuntimeException
{
    public function __construct(public readonly Model $existing, public readonly string $placeName)
    {
        parent::__construct('Remplacement de l\'inventaire du jour à confirmer.');
    }
}
