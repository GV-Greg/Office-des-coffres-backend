<?php

use App\Casts\EncryptedModuleData;
use App\Support\ModuleDataEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Chiffrement des données de module
|--------------------------------------------------------------------------
|
| Règle : admin/strategies/donnees-utilisateur.md §5.
|
| Aucune table de module n'existe encore — la décision de chiffrer se prend AVANT la première
| migration, parce que chiffrer après coup imposerait une migration de données sur des lignes de
| prod. Ces tests montent donc leur propre table éphémère : ils valident le mécanisme, pas un
| module en particulier.
|
*/

beforeEach(function () {
    Schema::create('fake_module_reports', function (Blueprint $table) {
        $table->id();
        // Axes en clair : filtrables et triables en SQL (§5).
        $table->unsignedBigInteger('city_id');
        $table->timestamp('reported_at')->nullable();
        // Contenu chiffré.
        $table->text('payload')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('fake_module_reports');
});

function fakeReport(): Model
{
    return new class extends Model
    {
        protected $table = 'fake_module_reports';

        public $timestamps = false;

        protected $guarded = [];

        protected function casts(): array
        {
            return [
                'payload' => EncryptedModuleData::class.':array',
                'reported_at' => 'datetime',
            ];
        }
    };
}

it('rend un payload identique à ce qui a été écrit', function () {
    $payload = ['fer' => 120, 'or' => 3, 'note' => 'Mine de Dole — relevé hebdomadaire'];

    $model = fakeReport();
    $model->fill(['city_id' => 7, 'payload' => $payload])->save();

    $relu = $model->newQuery()->find($model->getKey());

    expect($relu->payload)->toBe($payload);
});

it('ne stocke pas le clair en base', function () {
    $model = fakeReport();
    $model->fill(['city_id' => 7, 'payload' => ['secret' => 'Mine de Dole']])->save();

    $brut = DB::table('fake_module_reports')->where('id', $model->getKey())->value('payload');

    // C'est tout l'objet de §5 : un SELECT * en phpMyAdmin ne doit rien rendre d'exploitable.
    expect($brut)->not->toContain('Mine de Dole')
        ->and($brut)->not->toContain('secret')
        ->and(base64_decode($brut, true))->toBeString();
});

it('laisse les axes en clair, pour rester filtrables en SQL', function () {
    $model = fakeReport();
    $model->fill(['city_id' => 42, 'payload' => ['fer' => 1]])->save();

    // Si ces colonnes étaient chiffrées (IV aléatoire), ce where ne trouverait jamais rien.
    expect(DB::table('fake_module_reports')->where('city_id', 42)->count())->toBe(1);
});

it('déchiffre encore une donnée écrite avec une clé antérieure', function () {
    $ancienne = 'base64:'.base64_encode('odc-ancienne-cle-de-module-32byt');

    // Écrit avec l'ancienne clé, hors du cast.
    $chiffreAvecAncienne = ModuleDataEncrypter::fromConfig(['key' => $ancienne])
        ->encrypt(json_encode(['fer' => 99]), false);

    $id = DB::table('fake_module_reports')->insertGetId([
        'city_id' => 7,
        'payload' => $chiffreAvecAncienne,
    ]);

    // La clé courante a changé, l'ancienne est déclarée comme antérieure : c'est le filet qui
    // transforme une rotation accidentelle en incident plutôt qu'en perte définitive.
    config()->set('module_data.previous_keys', [$ancienne]);
    app()->forgetInstance(ModuleDataEncrypter::class);

    expect(fakeReport()->newQuery()->find($id)->payload)->toBe(['fer' => 99]);
});

it('chiffre toujours avec la clé courante, jamais avec une clé antérieure', function () {
    $ancienne = 'base64:'.base64_encode('odc-ancienne-cle-de-module-32byt');
    config()->set('module_data.previous_keys', [$ancienne]);
    app()->forgetInstance(ModuleDataEncrypter::class);

    $model = fakeReport();
    $model->fill(['city_id' => 7, 'payload' => ['fer' => 5]])->save();

    $brut = DB::table('fake_module_reports')->where('id', $model->getKey())->value('payload');

    // Relu avec la seule clé antérieure, le déchiffrement doit échouer : la donnée a bien été
    // écrite avec la clé courante.
    expect(fn () => ModuleDataEncrypter::fromConfig(['key' => $ancienne])->decrypt($brut, false))
        ->toThrow(DecryptException::class);
});

it('refuse de démarrer sans MODULE_DATA_KEY, plutôt que de retomber sur APP_KEY', function () {
    expect(fn () => ModuleDataEncrypter::fromConfig(['key' => null]))
        ->toThrow(RuntimeException::class, 'MODULE_DATA_KEY');
});

it('accepte une clé brute comme une clé préfixée base64:, à égalité avec APP_KEY', function () {
    $brute = 'odc-cle-de-module-de-32-octets!!';

    $viaBrute = ModuleDataEncrypter::fromConfig(['key' => $brute]);
    $viaBase64 = ModuleDataEncrypter::fromConfig(['key' => 'base64:'.base64_encode($brute)]);

    expect($viaBase64->decrypt($viaBrute->encrypt('coffre', false), false))->toBe('coffre');
});
