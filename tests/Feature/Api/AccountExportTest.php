<?php

use App\Models\Character;
use App\Models\User;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| GET auth/account/export — export art. 20 RGPD
|--------------------------------------------------------------------------
| Brief : admin/content/brief-export-donnees.md (§ Tests, Lot 1).
*/

describe('GET auth/account/export', function () {

    it('renvoie 401 sans authentification', function () {
        $this->getJson('/api/v1/auth/account/export')
            ->assertUnauthorized();
    });

    it('exporte un compte avec 2 personnages et leurs résidences', function () {
        $map  = mandateMap();
        $user = User::factory()->create();

        Character::factory()->create([
            'user_id'    => $user->id,
            'city_id'    => $map['cityA']->id,
            'pseudo'     => 'Aldric',
            'is_validated' => true,
            'pending_residence_change' => false,
        ]);
        Character::factory()->create([
            'user_id'    => $user->id,
            'city_id'    => $map['cityB']->id,
            'pseudo'     => 'Beatrix',
            'is_validated' => false,
            'pending_residence_change' => true,
        ]);

        Passport::actingAs($user);
        $data = $this->getJson('/api/v1/auth/account/export')
            ->assertOk()
            ->json();

        expect($data['account']['email'])->toBe($user->email);
        expect($data['characters'])->toHaveCount(2);

        $aldric = collect($data['characters'])->firstWhere('pseudo', 'Aldric');
        expect($aldric['residence']['city'])->toBe('Arras');
        expect($aldric['residence']['province'])->toBe('Artois');
        expect($aldric['residence']['kingdom'])->toBe('Royaume');
        expect($aldric['is_validated'])->toBeTrue();

        $beatrix = collect($data['characters'])->firstWhere('pseudo', 'Beatrix');
        expect($beatrix['residence']['city'])->toBe('Bourges');
        expect($beatrix['is_validated'])->toBeFalse();
        expect($beatrix['pending_residence_change'])->toBeTrue();
    });

    it('ne fuit aucun identifiant interne ni secret', function () {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id, 'city_id' => null]);

        Passport::actingAs($user);
        $json = $this->getJson('/api/v1/auth/account/export')
            ->assertOk()
            ->getContent();

        $allKeys = collectJsonKeys(json_decode($json, true));

        expect($allKeys)->not()->toContain('password');
        expect($allKeys)->not()->toContain('remember_token');
        expect($allKeys)->not()->toContain('id');
        expect($allKeys)->not()->toContain('user_id');
        expect($allKeys)->not()->toContain('character_id');
        expect($allKeys)->not()->toContain('city_id');
    });

    it('isole les données : aucun personnage d\'un autre compte ne fuite', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Character::factory()->create(['user_id' => $user1->id, 'city_id' => null, 'pseudo' => 'AliceA']);
        Character::factory()->create(['user_id' => $user2->id, 'city_id' => null, 'pseudo' => 'BobB']);

        Passport::actingAs($user1);
        $json = $this->getJson('/api/v1/auth/account/export')
            ->assertOk()
            ->getContent();

        expect($json)->toContain('AliceA');
        expect($json)->not()->toContain('BobB');
    });

    it('renvoie les bons en-têtes Content-Disposition et Cache-Control', function () {
        $user = User::factory()->create();

        Passport::actingAs($user);
        $response = $this->getJson('/api/v1/auth/account/export')
            ->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        expect($disposition)->toContain('attachment');
        expect($disposition)->toMatch('/office-des-coffres-export-\d{4}-\d{2}-\d{2}\.json/');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('exporte les rôles correctement (admin et joueur)', function () {
        $admin  = mandateAdmin();
        $player = User::factory()->create();

        Passport::actingAs($admin);
        $adminData = $this->getJson('/api/v1/auth/account/export')
            ->assertOk()
            ->json();
        expect($adminData['account']['roles'])->toContain('admin');

        Passport::actingAs($player);
        $playerData = $this->getJson('/api/v1/auth/account/export')
            ->assertOk()
            ->json();
        expect($playerData['account']['roles'])->toBe([]);
    });

    it('exporte les mandats avec leur statut effectif, sans la note interne', function () {
        $map       = mandateMap();
        $user      = User::factory()->create();
        $character = mandatePlayer($map['cityA'], ['user_id' => $user->id]);

        // Maire actif (in_office_from = started_at = passé → statut 'active')
        approvedMayor($character, $map['cityA'], '2026-10-01');

        // Conseiller élu (in_office_from = null → statut 'elected')
        approvedCouncil($character, $map['provinceA'], '2026-10-01', null, null);

        Passport::actingAs($user);
        $json = $this->getJson('/api/v1/auth/account/export')
            ->assertOk()
            ->getContent();

        $data = json_decode($json, true);
        expect($data['mandates'])->toHaveCount(2);

        $mayor = collect($data['mandates'])->firstWhere('level', 'mayor');
        expect($mayor['status'])->toBe('active');
        expect($mayor['place']['city'])->toBe('Arras');
        expect($mayor['place']['province'])->toBe('Artois');
        expect($mayor['place']['kingdom'])->toBe('Royaume');

        $council = collect($data['mandates'])->firstWhere('level', 'council');
        expect($council['status'])->toBe('elected');
        expect($council['place']['province'])->toBe('Artois');
        expect($council['place']['kingdom'])->toBe('Royaume');

        // La note interne ne doit jamais apparaître
        expect($json)->not()->toContain('"note"');
    });

    it('renvoie 429 au 7e appel dans la minute', function () {
        $user = User::factory()->create();

        Passport::actingAs($user);

        foreach (range(1, 6) as $_) {
            $this->getJson('/api/v1/auth/account/export')->assertOk();
        }

        $this->getJson('/api/v1/auth/account/export')->assertStatus(429);
    });
});

/**
 * Collecte récursivement tous les noms de clés (string) d'un tableau JSON décodé.
 * Permet de vérifier qu'aucun identifiant interne ni secret n'a fuité.
 */
function collectJsonKeys(array $data): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        if (is_array($value)) {
            $keys = array_merge($keys, collectJsonKeys($value));
        }
    }

    return $keys;
}
