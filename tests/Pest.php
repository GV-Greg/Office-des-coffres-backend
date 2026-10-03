<?php

use App\Models\Character;
use App\Models\City;
use App\Models\CouncilMandate;
use App\Models\Kingdom;
use App\Models\MayorMandate;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Services\MandateWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Mandats — aides partagées (tests/Feature/Mandates)
|--------------------------------------------------------------------------
*/

/** Une carte minimale : deux provinces, deux villes chacune. */
function mandateMap(): array
{
    $kingdom = Kingdom::factory()->create(['kingdom_name' => 'Royaume']);
    $provinceA = Province::factory()->create(['kingdom_id' => $kingdom->id, 'province_name' => 'Artois']);
    $provinceB = Province::factory()->create(['kingdom_id' => $kingdom->id, 'province_name' => 'Berry']);

    return [
        'provinceA' => $provinceA,
        'provinceB' => $provinceB,
        'cityA' => City::factory()->create(['province_id' => $provinceA->id, 'city_name' => 'Arras']),
        'cityA2' => City::factory()->create(['province_id' => $provinceA->id, 'city_name' => 'Béthune']),
        'cityB' => City::factory()->create(['province_id' => $provinceB->id, 'city_name' => 'Bourges']),
    ];
}

/** Un personnage d'un compte joueur, validé par défaut. */
function mandatePlayer(City $city, array $attributes = []): Character
{
    return Character::factory()->create($attributes + [
        'city_id' => $city->id,
        'is_validated' => true,
        'pending_residence_change' => false,
    ]);
}

function mandateAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function mandateWorkflow(): MandateWorkflow
{
    return app(MandateWorkflow::class);
}

/** Demande + validation d'un maire. */
function approvedMayor(Character $character, City $city, string $startedAt, array $options = []): MayorMandate
{
    $mandate = mandateWorkflow()->request($character, 'mayor', [
        'city_id' => $city->id, 'started_at' => $startedAt, 'announcement_url' => 'https://forum.example/maire',
    ]);

    return mandateWorkflow()->approve($mandate, $options);
}

/** Demande + validation d'un conseiller ; $inOfficeFrom nul = élu, pas encore en fonction. */
function approvedCouncil(Character $character, Province $province, string $startedAt, ?string $inOfficeFrom = null, ?string $officeKey = null): CouncilMandate
{
    $mandate = mandateWorkflow()->request($character, 'council', [
        'province_id' => $province->id, 'started_at' => $startedAt, 'council_office_key' => $officeKey,
        'announcement_url' => 'https://forum.example/conseil',
    ]);

    return mandateWorkflow()->approve($mandate, ['in_office_from' => $inOfficeFrom]);
}

/** Fige l'horloge à une heure du jeu (Europe/Paris). */
function atGameTime(string $datetime): void
{
    Carbon::setTestNow(Carbon::parse($datetime, 'Europe/Paris'));
}
