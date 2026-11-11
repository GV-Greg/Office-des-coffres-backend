<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimDecodeActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Preuve d'auteur d'une activité SANS COMPTE : le slug (public) et le mot de passe. Aucune session, aucun jeton :
 * la preuve accompagne chaque appel de gestion et ne vit que le temps de la requête.
 *
 * `$model` est la class-string du modèle d'activité concerné (AnimDecodeActivity, AnimRewardsGrid…) : injectable
 * pour chaque type, sans dupliquer la logique de vérification.
 *
 * Le refus ne distingue JAMAIS « slug inconnu », « activité privée », « activité close » de « mot de passe faux »,
 * ni par le message, ni par la durée : un faux hachage est comparé quand l'activité n'existe pas.
 * La limitation des essais (par slug et par IP) est celle de la route (`anim-access`, AppServiceProvider).
 */
class AnimAccess
{
    /** Hachage bcrypt valide, sans mot de passe correspondant : égalise le temps de réponse d'un slug inconnu. */
    private const DUMMY_HASH = '$2y$12$wBbXr1xP5sR3qT7uV9yZ2e0iO6nL4mK8jH1gF3dS5aQ7wE9rT1yU2';

    /**
     * @param  class-string<Model>  $model  Modèle d'activité à interroger (défaut : AnimDecodeActivity).
     */
    public function __construct(private readonly string $model = AnimDecodeActivity::class) {}

    /** @throws MandateRefusal 403 */
    public function prove(string $slug, string $password): AnimActor
    {
        $activity = ($this->model)::query()->where('slug', $slug)->where('is_anonymous', true)->first();
        $hash = $activity?->password_hash ?: self::DUMMY_HASH;

        $matches = Hash::check($password, $hash);
        if (! $activity || ! $activity->password_hash || ! $matches) {
            throw MandateRefusal::make('password', 'anim.api.access_denied')->status(403);
        }

        return AnimActor::forAnonymous($activity);
    }

    public function activityOf(AnimActor $actor): Model
    {
        return $actor->proven ?? throw MandateRefusal::make('password', 'anim.api.access_denied')->status(403);
    }
}
