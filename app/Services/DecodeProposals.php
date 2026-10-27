<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;
use App\Models\Character;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saisie des propositions des Coffres Scellés. Réservée à l'ORGANISATEUR : c'est lui qui reçoit les
 * propositions (forum, gazette, en jeu) et les inscrit. Le plan du 18/10 ouvrait cette route à tout
 * visiteur ; c'était une erreur de rédaction, le brief fait foi (fil animation-coffres-scelles, tour 04).
 */
class DecodeProposals
{
    public function __construct(private readonly DecodeWorkflow $workflow) {}

    /**
     * Inscrit une proposition, la note, et si le coffre est percé : l'enregistre à 20 points, marque
     * le coffre percé, en scelle un nouveau et passe l'activité en `relaunch`.
     *
     * @return array{proposal: AnimDecodeProposal, score: int, found: bool, next: ?AnimDecodeSecret}
     *
     * @throws MandateRefusal
     */
    public function submit(Character $actor, AnimDecodeActivity $activity, AnimDecodeSecret $secret, string $combination, string $player): array
    {
        $this->workflow->authorize($actor, $activity);
        if ($activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_closed');
        }
        if ($secret->decode_activity_id !== $activity->id || $secret->status) {
            throw MandateRefusal::make('secret', 'anim.api.secret_not_open');
        }
        $guess = DecodeScoring::digits($combination);
        if ($guess === null) {
            throw MandateRefusal::make('combination', 'anim.api.combination_invalid');
        }

        return DB::transaction(function () use ($activity, $secret, $combination, $guess, $player) {
            $score = DecodeScoring::score(DecodeScoring::digits($secret->code), $guess);
            $found = DecodeScoring::isFound($score);

            $proposal = $secret->proposals()->create([
                'combination' => $combination,
                'player' => trim($player),
                'points' => DecodeScoring::recorded($score),
                'created_at' => Carbon::now(),
            ]);

            $next = null;
            if ($found) {
                $secret->forceFill(['status' => true])->save();
                $next = $this->workflow->seal($activity, AnimDecodeActivity::RELAUNCH);
            }

            return ['proposal' => $proposal, 'score' => $score, 'found' => $found, 'next' => $next];
        });
    }
}
