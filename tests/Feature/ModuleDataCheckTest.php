<?php

use App\Models\Province;
use App\Support\ModuleDataEncrypter;
use Illuminate\Support\Facades\DB;

/*
| php artisan module-data:check et la validation des clés de ModuleDataEncrypter (incident du
| 09/10/2026 : MODULE_DATA_KEY collée sans son préfixe `base64:` → « Server Error » muet à la première
| inscription au Registre des mines). Une clé n'est JAMAIS affichée, ni par la commande ni par
| l'exception.
*/

function moduleKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function setModuleKeys(string $key, array $previous = []): void
{
    config(['module_data.key' => $key, 'module_data.previous_keys' => $previous]);
}

function encryptedReport(string $key): void
{
    $payload = ModuleDataEncrypter::fromConfig(['key' => $key])->encrypt('{"raw":"x"}', false);
    DB::table('mine_reports')->insert([
        'province_id' => Province::factory()->create()->id, 'reported_at' => '2026-10-09',
        'office_key' => 'bailli', 'payload' => $payload, 'active' => true,
    ]);
}

test('une clé bien formée : forme, aller-retour et relecture des données — succès', function () {
    setModuleKeys($key = moduleKey());
    encryptedReport($key);

    $this->artisan('module-data:check')
        ->expectsOutputToContain('MODULE_DATA_KEY : bien formée')
        ->expectsOutputToContain('Aller-retour de chiffrement : réussi.')
        ->expectsOutputToContain('Relevés du Registre des mines : 1, tous lisibles')
        ->assertSuccessful();
});

test('l\'incident du 09/10 : une clé sans son préfixe base64: est refusée, nommée, jamais affichée', function () {
    $bare = substr(moduleKey(), strlen('base64:'));
    setModuleKeys($bare);

    $this->artisan('module-data:check')
        ->expectsOutputToContain('MODULE_DATA_KEY est mal formée')
        ->doesntExpectOutputToContain($bare)
        ->assertFailed();

    expect(fn () => ModuleDataEncrypter::fromConfig(['key' => $bare]))
        ->toThrow(RuntimeException::class, 'préfixe « base64: » compris');
});

test('une clé tronquée, ou une ancienne clé mal formée, est refusée et désignée', function () {
    setModuleKeys('base64:'.base64_encode(random_bytes(16)));
    $this->artisan('module-data:check')->expectsOutputToContain('MODULE_DATA_KEY est mal formée')->assertFailed();

    setModuleKeys(moduleKey(), ['"base64:abc"']);
    $this->artisan('module-data:check')->expectsOutputToContain('MODULE_DATA_PREVIOUS_KEYS #1 est mal formée')->assertFailed();
});

test('une donnée chiffrée avec une clé absente est signalée ; la remettre en clé antérieure la rend lisible', function () {
    encryptedReport($old = moduleKey());

    setModuleKeys(moduleKey());
    $this->artisan('module-data:check')->expectsOutputToContain('1 relevé(s) sur 1 illisible(s)')->assertFailed();

    setModuleKeys(moduleKey(), [$old]);
    $this->artisan('module-data:check')
        ->expectsOutputToContain('Clés antérieures (MODULE_DATA_PREVIOUS_KEYS) : 1')
        ->expectsOutputToContain('tous lisibles')
        ->assertSuccessful();
});

test('une clé absente garde son message d\'origine', function () {
    setModuleKeys('');
    $this->artisan('module-data:check')->expectsOutputToContain('MODULE_DATA_KEY est absente')->assertFailed();
});
