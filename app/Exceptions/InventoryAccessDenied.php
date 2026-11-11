<?php

namespace App\Exceptions;

use RuntimeException;

/** Le personnage n'a pas, MAINTENANT, le poste qui donne accès à cet inventaire (403). `$key` = clé de traduction `inventory.api.*`. */
class InventoryAccessDenied extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}
