<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type d'activité du module Animation (`decode`, plus tard `grid`, `chicken_race`). Référentiel : la
 * clé est la clé de traduction (lang/{fr,en}/anim.php, `types.<key>`), `is_active` masque un type.
 */
class AnimActivityType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<AnimDecodeActivity, AnimActivityType> */
    public function decodeActivities(): HasMany
    {
        return $this->hasMany(AnimDecodeActivity::class, 'activity_type_id');
    }
}
