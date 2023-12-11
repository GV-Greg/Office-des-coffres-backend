<?php

namespace Database\Seeders;

use App\Models\Character;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'email' => env('SEEDER_SUPER_ADMIN_EMAIL'),
            'password' => env('SEEDER_SUPER_ADMIN_PASSWORD')
        ]);

        $owner = Role::create([
            'name' => 'Owner',
            'display_name' => 'Project creator',
            'description' => 'User is the owner of a given project'
        ]);

        $team = Team::create([
            'name' => 'CreaCube',
            'display_name' => 'Admins',
            'description' => 'Developers team',
        ]);

        $user->addRole($owner, $team);

        Character::factory()
            ->for($user)
            ->create([
                'pseudo' => 'Artifice',
                'is_validated' => 1,
                'city_id' => 147
            ]);

        Character::factory()
            ->for($user)
            ->create([
                'pseudo' => 'Buldo',
                'is_validated' => 1,
                'city_id' => 71
            ]);

        User::factory(10)->create();

        User::factory(15)
            ->has(
                Character::factory(),
            )
            ->create();
    }
}
