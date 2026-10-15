<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\Province;
use App\Models\ProvinceMinePrice;
use App\Notifications\MinePricesDigest;
use App\Services\MandateAuthority;
use App\Services\MinePrices;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Résumé quotidien des changements de prix des mines (brief admin/content/brief-prix-registre.md ;
 * Greg, 15/10/2026) : une province dont les prix n'ont pas bougé n'envoie rien. Fenêtre = depuis le
 * dernier passage RÉUSSI (cache), à défaut les dernières 24 h ; un passage manqué est donc rattrapé.
 * Destinataires : les occupants ACTUELS des trois postes, jamais l'auteur d'un changement pour ce
 * changement-là.
 */
class SendMinePricesDigest extends Command
{
    protected $signature = 'mines:prices-digest';

    protected $description = 'Envoie aux occupants des postes qui gèrent les prix le résumé des changements de prix.';

    /** Un cache vidé ne doit pas rejouer des semaines d'historique. */
    private const MAX_WINDOW_DAYS = 14;

    public function handle(MinePrices $prices, MandateAuthority $authority): int
    {
        $until = Carbon::now();
        $since = MandateHeartbeat::lastSuccess('prices') ?? $until->copy()->subDay();
        $since = $since->max($until->copy()->subDays(self::MAX_WINDOW_DAYS));

        $sent = 0;
        $provinceIds = ProvinceMinePrice::query()->where('updated_at', '>', $since)->where('updated_at', '<=', $until)
            ->distinct()->pluck('province_id');

        foreach (Province::query()->whereIn('id', $provinceIds)->get() as $province) {
            $changes = $prices->changedSince($province, $since)->where('updated_at', '<=', $until)
                ->map(function (ProvinceMinePrice $price) use ($since) {
                    $writer = $price->updatedBy ?? $price->createdBy;

                    return [
                        'price' => $price,
                        'created' => $price->created_at > $since,
                        'author' => $writer?->pseudo,
                        'author_user_id' => $writer?->user_id,
                    ];
                });

            $holders = $authority->currentHolders($province, MandateAuthority::PRICE_OFFICES)
                ->filter(fn (Character $holder) => $holder->user !== null)
                ->unique('user_id');

            foreach ($holders as $holder) {
                $own = $changes->reject(fn (array $change) => $change['author_user_id'] === $holder->user_id)->values();
                if ($own->isNotEmpty()) {
                    $holder->user->notify(new MinePricesDigest($province, $own));
                    $sent++;
                }
            }
        }

        MandateHeartbeat::record('prices');
        $this->info($sent.' résumé(s) envoyé(s).');

        return self::SUCCESS;
    }
}
