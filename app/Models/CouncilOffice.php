<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Poste titré d'un conseil comtal. Aucun libellé en base : `mandates.offices.<key>` dans
 * lang/{fr,en}/mandates.php, relevés en jeu. Semé par CouncilOfficeSeeder (idempotent).
 */
class CouncilOffice extends Model
{
    protected $fillable = ['key', 'position'];
}
