<?php

namespace App\Exceptions;

use RuntimeException;

/** Les réglages ont été écrits ailleurs depuis la lecture du client (409) : il reçoit l'état courant pour se recaler. */
class InventoryVersionConflict extends RuntimeException
{
    /** @param  array<string, mixed>  $current  le résultat de InventorySettings::show() */
    public function __construct(public readonly array $current)
    {
        parent::__construct('inventory version conflict');
    }
}
