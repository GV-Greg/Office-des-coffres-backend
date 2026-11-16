<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\Mandate;
use App\Models\User;
use App\Notifications\AdminEventNotice;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

/**
 * Notifications d'administration (brief admin/content/brief-notifications-admin.md, arbitrages de
 * Greg du 17/11/2026, fil admin/echanges/notifications-admin) : un email aux comptes `admin` quand
 * un compte est confirmé, un personnage créé, un poste demandé ou renouvelé.
 *
 * Pas de couche d'événements : le projet n'en a aucune, et trois points d'appel ne la justifient pas.
 * Un nouvel événement = une méthode ici (suppression de compte, etc.), sans toucher le reste.
 *
 * 🔴 L'envoi ne bloque JAMAIS le geste du joueur : toute panne est attrapée et journalisée sans
 * l'adresse (précédent du 09/10/2026 : un « Server Error » muet à l'inscription). 🔴 Aucune adresse
 * email de joueur dans le corps : une donnée de plus dans une boîte mail, sans besoin.
 */
class AdminNotifier
{
    /** Un compte vient de confirmer son adresse (et non à l'inscription : les faux comptes ne comptent pas). */
    public function accountConfirmed(User $user): void
    {
        $this->send(
            'account',
            __('admin_notices.account.subject'),
            [
                __('admin_notices.account.intro'),
                __('admin_notices.account.line', [
                    'id' => $user->id,
                    'total' => User::whereNotNull('email_verified_at')->count(),
                ]),
                $this->when(),
            ],
            __('admin_notices.account.action'),
            url('/users'),
        );
    }

    public function characterCreated(Character $character): void
    {
        $character->loadMissing('city.province.kingdom');
        $city = $character->city;

        $this->send(
            'character',
            __('admin_notices.character.subject'),
            [
                __('admin_notices.character.intro', ['pseudo' => $character->pseudo]),
                __('admin_notices.character.residence', [
                    'city' => $city?->city_name,
                    'province' => $city?->province?->province_name,
                    'kingdom' => $city?->province?->kingdom?->kingdom_name,
                ]),
                __('admin_notices.character.account', ['id' => $character->user_id]),
                $this->when(),
            ],
            __('admin_notices.character.action'),
            url('/characters'),
        );
    }

    /** Demande de poste, ou renouvellement : les deux entrent dans la file de vérification. */
    public function mandateRequested(Mandate $mandate, bool $renewal = false): void
    {
        $mandate->loadMissing('character');
        $kind = $renewal ? 'renewal' : 'mandate';

        $lines = [
            __("admin_notices.{$kind}.intro", [
                'pseudo' => $mandate->character?->pseudo,
                'post' => MandateLabels::post($mandate, 'fr'),
            ]),
        ];
        if ($mandate instanceof CouncilMandate && $mandate->pendingOffice) {
            $lines[] = __('admin_notices.mandate.office', [
                'office' => MandateLabels::office($mandate->pendingOffice->key, 'fr'),
            ]);
        }
        $lines[] = __('admin_notices.mandate.started', ['date' => MandateLabels::date($mandate->started_at)]);
        $lines[] = $this->when();

        $this->send(
            $kind,
            __("admin_notices.{$kind}.subject"),
            $lines,
            __('admin_notices.mandate.action'),
            url('/mandates'),
        );
    }

    /** @param  array<int, string>  $lines */
    private function send(string $kind, string $subject, array $lines, string $actionLabel, string $actionUrl): void
    {
        try {
            // 🔴 Le garde `web` en clair : le rôle est créé sous `web` (UserSeeder), mais une requête `auth:api`
            // fait de `api` le garde par défaut, où `role('admin')` lève RoleDoesNotExist — et personne ne serait jamais prévenu.
            $admins = User::role('admin', 'web')->get();
            if ($admins->isEmpty()) {
                return;
            }
            Notification::send($admins, new AdminEventNotice($subject, $lines, $actionLabel, $actionUrl));
        } catch (RoleDoesNotExist) {
            // Aucun rôle `admin` : personne à prévenir (base neuve, tests).
        } catch (Throwable $e) {
            // Ni l'adresse, ni le message de l'exception (il peut la contenir) : le genre d'événement et la classe.
            Log::warning('admin.notification_failed', ['kind' => $kind, 'exception' => $e::class]);
        }
    }

    /** « Le 17/11/2026 à 09:30 (heure de Paris). » */
    private function when(): string
    {
        $now = Carbon::now(MandateCalendar::timezone());

        return __('admin_notices.when', ['date' => $now->format('d/m/Y'), 'time' => $now->format('H:i')]);
    }
}
