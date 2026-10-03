<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Historique des postes d'un personnage supprimé — voir la migration. Écrit par AccountDeletion seul. */
class OfficeHistoryArchive extends Model
{
    protected $table = 'office_history_archive';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
