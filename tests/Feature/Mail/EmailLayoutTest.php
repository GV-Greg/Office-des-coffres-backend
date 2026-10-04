<?php

use App\Models\User;
use App\Notifications\InactiveAccountNotice;
use App\Notifications\MandateDecision;
use App\Notifications\PolicyUpdated;
use App\Notifications\UnverifiedAccountReminder;
use App\Notifications\VerifyApiEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Gabarit des emails (resources/views/vendor/{mail,notifications}, 04/10/2026), habillé selon
// CHARTE-GRAPHIQUE.md : logo, pied « outil non officiel » bilingue, une seule salutation par
// langue, ligne d'aide bilingue, « Ludiquement, » / « Playfully, » (Greg, 04/10/2026).

function renderedEmails(): array
{
    $user = User::factory()->make(['id' => 42, 'created_at' => Carbon::parse('2026-09-11')]);
    $entry = ['date' => '2026-10-04', 'summary' => ['fr' => 'Résumé.', 'en' => 'Summary.'], 'substantial' => true, 'decided_by' => 'Greg'];

    return collect([
        'politique' => new PolicyUpdated($entry),
        'préavis' => new InactiveAccountNotice(Carbon::parse('2025-11-02'), Carbon::parse('2026-11-03')),
        'rappel' => new UnverifiedAccountReminder(Carbon::parse('2026-10-11')),
        'inscription' => new VerifyApiEmail,
    ])->map(fn ($notification) => $notification->toMail($user)->render()->toHtml())->all();
}

test('chaque email des joueurs porte le gabarit de l\'Office, bilingue, sans reliquat du gabarit Laravel', function (string $name) {
    $html = renderedEmails()[$name];

    expect($html)
        ->toContain(rtrim(config('app.url'), '/').'/images/email/logo-horizontal.png')
        ->toContain(e(__('mail.unofficial', [], 'fr')))
        ->toContain(e(__('mail.unofficial', [], 'en')))
        // Après le « ? » : Markdown et e() n'encodent pas l'apostrophe de « s'ouvre » de la même façon.
        ->toContain(Str::afterLast(__('mail.subcopy', [], 'fr'), '? '))
        ->toContain(Str::afterLast(__('mail.subcopy', [], 'en'), '? '))
        ->toContain('Ludiquement,')->toContain('Playfully,')
        ->toContain('lang-separator')
        ->not->toContain('Bonjour !')->not->toContain('Hello!')
        ->not->toContain('English version below')
        ->not->toContain('Cordialement')
        ->not->toContain('laravel.com')
        ->not->toMatch('/\b(mail|policy|accounts|verification|mandates)\.[a-z_]+\.[a-z_]+/');
    expect(strpos($html, 'Bonjour,'))->toBeLessThan(strpos($html, 'Hello,'));
})->with(['politique', 'préavis', 'rappel', 'inscription']);

test('les emails des mandats passent par le même gabarit', function () {
    Notification::fake();
    atGameTime('2026-05-10 12:00');
    $map = mandateMap();
    $mandate = approvedMayor(mandatePlayer($map['cityA']), $map['cityA'], '2026-05-01');

    $sent = null;
    Notification::assertSentTo($mandate->character->user, MandateDecision::class, function ($n) use (&$sent, $mandate) {
        $sent = $n->toMail($mandate->character->user)->render()->toHtml();

        return true;
    });

    expect($sent)->toContain('Ludiquement,')->toContain('lang-separator')->not->toContain('Bonjour !');
    Carbon::setTestNow();
});

test('un email Laravel sans signature propre en reçoit une (réinitialisation de mot de passe admin)', function () {
    $html = (new ResetPassword('jeton'))->toMail(User::factory()->make())->render()->toHtml();

    expect($html)->toContain('class="salutation"')->toContain(config('app.name'));
});

test('le logo PNG existe là où les emails le cherchent', function () {
    expect(public_path('images/email/logo-horizontal.png'))->toBeFile();
});

test('l\'email d\'inscription annonce la vraie durée du lien', function () {
    config(['auth.verification.expire' => 45]);
    $html = (new VerifyApiEmail)->toMail(User::factory()->make(['id' => 1]))->render()->toHtml();

    expect($html)->toContain('45 minutes')->not->toContain('60 minutes');
});

test('chaque clé des emails existe en français ET en anglais', function (string $file) {
    $keys = fn (string $locale) => array_keys(Arr::dot(require lang_path("{$locale}/{$file}.php")));

    expect($keys('en'))->toBe($keys('fr'));
})->with(['mail', 'verification']);
