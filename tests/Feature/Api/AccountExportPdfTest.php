<?php

use App\Models\User;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| GET auth/account/export/pdf — export art. 15 RGPD (lisible par un humain)
|--------------------------------------------------------------------------
| Brief : admin/content/brief-export-donnees.md (§ Tests, Lot 2 PDF).
| Même règle que l'export JSON : porteur du jeton, throttle 6/min.
*/

describe('GET auth/account/export/pdf', function () {

    it('renvoie 401 sans authentification', function () {
        $this->getJson('/api/v1/auth/account/export/pdf')
            ->assertUnauthorized();
    });

    it('renvoie 200 avec Content-Type application/pdf', function () {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->get('/api/v1/auth/account/export/pdf')
            ->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
        expect($response->getContent())->not()->toBeEmpty();
    });

    it('renvoie les bons en-têtes Content-Disposition et Cache-Control', function () {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->get('/api/v1/auth/account/export/pdf')
            ->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        expect($disposition)->toContain('attachment');
        expect($disposition)->toMatch('/office-des-coffres-export-\d{4}-\d{2}-\d{2}\.pdf/');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('renvoie 429 au 7e appel dans la minute', function () {
        $user = User::factory()->create();
        Passport::actingAs($user);

        foreach (range(1, 6) as $_) {
            $this->get('/api/v1/auth/account/export/pdf')->assertOk();
        }

        $this->get('/api/v1/auth/account/export/pdf')->assertStatus(429);
    });
});
