<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lot d'une grille des lots. Catégorie C : lié à la grille (`grid_id` CASCADE), pas au compte.
 * `place` est null avant le tirage, 1..width*height après. `player` est le nom libre du personnage
 * qui a choisi la case ; `is_taken` indique que le lot a été remis.
 */
class AnimRewardsList extends Model
{
    protected $guarded = ['id'];

    protected $table = 'anim_rewards_lists';

    protected function casts(): array
    {
        return [
            'place' => 'integer',
            'is_taken' => 'boolean',
        ];
    }

    /** @return BelongsTo<AnimRewardsGrid, AnimRewardsList> */
    public function grid(): BelongsTo
    {
        return $this->belongsTo(AnimRewardsGrid::class, 'grid_id');
    }
}
