<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Une inscription existe déjà à une date ANTÉRIEURE à la prise de fonction du titulaire : elle est celle d'un prédécesseur (ou la sienne,
 * rétro-datée), et elle ne se remplace pas (409, fil registre-stocks, brief 10). Aucun « Remplacer » n'existe pour elle, et le refus ne
 * nomme PERSONNE : on ne remplace rien, la politique n'autorise donc pas à révéler le pseudo du rédacteur.
 */
class InventoryBeforeMandateLocked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Une inscription antérieure à la prise de fonction ne se remplace pas.');
    }
}
