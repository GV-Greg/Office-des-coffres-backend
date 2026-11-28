<?php

namespace App\Models;

use App\Support\Livestock;
use Illuminate\Database\Eloquent\Model;

/** Prix de la nourriture du cheptel d'une province, en vigueur à partir de `effective_from`. Écriture : App\Services\FeedPrices. */
class ProvinceFeedPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'price_maize' => 'float',
            'price_wheat' => 'float',
            'price_marjoram' => 'float',
            'price_elderberry' => 'float',
        ];
    }

    /** @return array<string, float|null> nourriture → prix */
    public function prices(): array
    {
        return collect(Livestock::FEEDS)->mapWithKeys(fn (string $feed) => [$feed => $this->{'price_'.$feed}])->all();
    }
}
