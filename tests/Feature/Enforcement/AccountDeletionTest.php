<?php

use App\Models\OfficeHistoryArchive;
use App\Models\User;
use App\Services\OfficeHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/*
| Porte unique de suppression (fil admin/echanges/mandats-historique, 11-12).
|
| L'historique des postes SURVIT à la suppression d'un compte (décision de Greg, 03/10/2026) : il
| est archivé par App\Services\AccountDeletion juste avant la cascade. La cascade SQL ne prévient
| aucun personnage : une suppression écrite ailleurs effacerait l'historique EN SILENCE. D'où :
|   1. un test STRUCTUREL — aucune suppression de User ni de Character hors d'AccountDeletion ;
|   2. des tests de bout en bout — chaque chemin réel laisse l'historique de la province intact.
*/

const DELETION_DOOR = 'app/Services/AccountDeletion.php';

/** Lignes qui suppriment un compte ou un personnage, dans un code source donné. */
function accountDeletionCalls(string $source): array
{
    $patterns = [
        '/\$(user|character|account)\w*\s*->\s*(force)?[dD]elete\s*\(/',
        '/\b(User|Character)::[^;]*->\s*(force)?[dD]elete\s*\(/',
        '/\b(User|Character)::destroy\s*\(/',
        '/->\s*characters\s*\(\s*\)\s*->\s*(force)?[dD]elete\s*\(/',
        '/DB::table\(\s*[\'"](users|characters)[\'"]\s*\)[^;]*->\s*delete\s*\(/',
    ];
    $hits = [];
    foreach (preg_split('/\R/', $source) as $number => $line) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                $hits[] = ($number + 1).': '.trim($line);
            }
        }
    }

    return $hits;
}

test('aucune suppression de compte ou de personnage hors de la porte unique', function () {
    $offenders = [];
    foreach (File::allFiles(base_path('app')) as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        if ($file->getExtension() !== 'php' || $relative === DELETION_DOOR) {
            continue;
        }
        foreach (accountDeletionCalls($file->getContents()) as $hit) {
            $offenders[] = "{$relative}:{$hit}";
        }
    }

    expect($offenders)->toBe([], 'Passer par App\Services\AccountDeletion, qui archive l\'historique des postes avant la cascade.');
});

test('contrôle positif : le détecteur voit les formes de suppression connues', function () {
    $source = <<<'PHP'
        $user->delete();
        $character->forceDelete();
        User::where('id', 1)->delete();
        Character::destroy([1]);
        $this->user->characters()->delete();
        DB::table('users')->where('id', 1)->delete();
        $mandate->delete();
        PHP;

    expect(accountDeletionCalls($source))->toHaveCount(6);
});

test('l\'archive ne porte aucune clé vers un compte ou un personnage (catégorie D)', function () {
    expect(Schema::getColumnListing('office_history_archive'))
        ->not->toContain('user_id')->not->toContain('character_id')->not->toContain('email');
});

// ─── De bout en bout : chaque chemin réel laisse l'historique intact ───────────────────────

beforeEach(function () {
    Notification::fake();
    atGameTime('2026-05-10 12:00');
    $this->map = mandateMap();
});

afterEach(fn () => Carbon::setTestNow());

/** Historique de la province, sans l'indicateur vivant/archivé (seule différence attendue). */
function provinceHistory(int $provinceId): array
{
    $history = app(OfficeHistory::class)->forProvince($provinceId);
    $strip = fn ($entries) => $entries->map(function (array $e) {
        unset($e['archived']);
        $e['started_at'] = $e['started_at']->toIso8601String();
        $e['ended_at'] = $e['ended_at']?->toIso8601String();

        return $e;
    })->all();

    return ['council' => $strip($history['council']), 'mayors' => $strip($history['mayors'])];
}

/** Un joueur avec un conseiller titré (puis réattribué) et un maire révoqué dans la province A. */
function playerWithHistory(array $map): User
{
    $character = mandatePlayer($map['cityA']);
    $council = approvedCouncil($character, $map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $rival = approvedCouncil(mandatePlayer($map['cityA2']), $map['provinceA'], '2026-05-01', '2026-05-03');
    mandateWorkflow()->setOffice($rival, 'juge', null, null, null);  // réattribue : Juge passe au rival
    mandateWorkflow()->setOffice($council, 'capitaine', null, null, null);
    $mayor = approvedMayor($character, $map['cityA'], '2026-05-01');
    mandateWorkflow()->revoke($mayor, 'retranchement', null, null, 'fr');

    return $character->user;
}

test('chaque chemin de suppression laisse l\'historique de la province identique', function (string $path) {
    $user = playerWithHistory($this->map);
    $pseudo = $user->characters()->first()->pseudo;
    $before = provinceHistory($this->map['provinceA']->id);
    expect(collect($before['council'])->pluck('pseudo'))->toContain($pseudo)
        ->and(collect($before['mayors'])->pluck('end_reason'))->toContain('retranchement');

    match ($path) {
        'api' => (function () use ($user) {
            Passport::actingAs($user);
            $this->deleteJson('/api/v1/auth/account', ['password' => 'password'])->assertNoContent();
        })->call($this),
        'admin' => $this->actingAs(mandateAdmin())->delete("/users/{$user->id}")->assertRedirect(),
        'profile' => $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/'),
    };

    expect(User::find($user->id))->toBeNull()
        ->and(provinceHistory($this->map['provinceA']->id))->toBe($before)
        ->and(OfficeHistoryArchive::where('character_pseudo', $pseudo)->count())->toBeGreaterThan(0);
})->with(['api', 'admin', 'profile']);

test('contrôle positif : une suppression qui contourne la porte perd l\'historique', function () {
    $user = playerWithHistory($this->map);
    $before = provinceHistory($this->map['provinceA']->id);

    $user->delete(); // le défaut que la porte unique empêche

    expect(provinceHistory($this->map['provinceA']->id))->not->toBe($before);
});

test('un personnage archivé n\'apparaît qu\'une fois : vivant OU archivé, jamais les deux', function () {
    $user = playerWithHistory($this->map);
    $countBefore = count(provinceHistory($this->map['provinceA']->id)['council']);

    Passport::actingAs($user);
    $this->deleteJson('/api/v1/auth/account', ['password' => 'password'])->assertNoContent();

    expect(count(provinceHistory($this->map['provinceA']->id)['council']))->toBe($countBefore);
});
