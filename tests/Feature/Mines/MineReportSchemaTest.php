<?php

use App\Models\Character;
use App\Models\MineReport;
use App\Models\Province;
use App\Models\User;
use App\Services\AccountDeletion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Registre des mines — schéma (PR 1a, brief admin/content/brief-registre-mines.md §2, §3, §5).
| La table ne reçoit encore aucune écriture applicative : ces tests tiennent les trois promesses du
| schéma avant que l'API (PR 1b) ne s'appuie dessus.
*/

function mineReport(array $overrides = []): MineReport
{
    return MineReport::create($overrides + [
        'province_id' => Province::factory()->create()->id,
        'reported_at' => '2026-10-06',
        'character_id' => null,
        'office_key' => 'commissaire_mines',
        'payload' => ['raw' => "Mine 1 : Mine d'or - Noeud 236", 'prices' => ['PIERRE' => 20], 'rate' => 0.7],
    ]);
}

test('le payload est chiffré en base et relu en clair ; les axes restent lisibles en SQL', function () {
    $report = mineReport();

    $row = DB::table('mine_reports')->find($report->id);
    expect($row->payload)->not->toContain('Mine d')
        ->and($row->province_id)->toBe($report->province_id)
        ->and(substr((string) $row->reported_at, 0, 10))->toBe('2026-10-06')
        ->and($report->fresh()->payload['rate'])->toBe(0.7);
});

test('un seul relevé ACTIF par province et par date : la base refuse le second', function () {
    $first = mineReport();

    expect(fn () => mineReport(['province_id' => $first->province_id]))
        ->toThrow(QueryException::class);
});

test('les relevés remplacés (active NULL) s\'accumulent sans heurter le relevé en vigueur', function () {
    $old = mineReport();
    $older = mineReport(['province_id' => $old->province_id, 'active' => null]);
    $old->update(['active' => null]);
    $current = mineReport(['province_id' => $old->province_id]);
    $old->update(['replaced_by_id' => $current->id, 'replaced_at' => now()]);

    expect(MineReport::where('province_id', $old->province_id)->whereDate('reported_at', '2026-10-06')->count())->toBe(3)
        ->and(MineReport::where('province_id', $old->province_id)->where('active', true)->sole()->id)->toBe($current->id)
        ->and($old->fresh()->replacedBy->id)->toBe($current->id)
        ->and($older->fresh()->active)->toBeNull();
});

test('deux provinces, ou deux dates, ont chacune leur relevé actif', function () {
    $a = mineReport();
    mineReport(['province_id' => $a->province_id, 'reported_at' => '2026-10-07']);
    mineReport(['province_id' => Province::factory()->create()->id]);

    expect(MineReport::where('active', true)->count())->toBe(3);
});

test('supprimer le compte de l\'auteur coupe le lien, le relevé RESTE avec son poste et sa date (catégorie C)', function () {
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create(['city_id' => null]);
    $report = mineReport(['character_id' => $character->id, 'office_key' => 'bailli']);

    app(AccountDeletion::class)->deleteUser($user);

    $fresh = $report->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->character_id)->toBeNull()
        ->and($fresh->office_key)->toBe('bailli')
        ->and($fresh->reported_at->toDateString())->toBe('2026-10-06');
});

test('les postes du registre sont ceux de l\'autorité, pas une seconde liste', function () {
    expect(MineReport::OFFICES)->toBe(['commissaire_mines', 'bailli']);
});
