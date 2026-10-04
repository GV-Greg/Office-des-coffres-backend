<?php

use App\Models\User;
use App\Notifications\InactiveAccountNotice;
use App\Notifications\MandateDecision;
use App\Notifications\PolicyUpdated;
use App\Notifications\UnverifiedAccountReminder;
use App\Notifications\VerifyApiEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Gabarit des emails (resources/views/vendor/{mail,notifications}, 04/10/2026), habillé selon
// CHARTE-GRAPHIQUE.md : logo, pied « outil non officiel » bilingue, une seule salutation par
// langue, ligne d'aide bilingue, « Ludiquement, » / « Playfully, » (Greg, 04/10/2026).

const ADMIN_URL = 'https://odc-admin.example';
const PLAYER_URL = 'https://joueurs.example';

beforeEach(fn () => config(['app.url' => ADMIN_URL, 'app.frontend_url' => PLAYER_URL]));

/** @return array<string, MailMessage> */
function emailMessages(): array
{
    $user = User::factory()->make(['id' => 42, 'created_at' => Carbon::parse('2026-09-11')]);
    $entry = ['date' => '2026-10-04', 'summary' => ['fr' => 'Résumé.', 'en' => 'Summary.'], 'substantial' => true, 'decided_by' => 'Greg'];

    return collect([
        'politique' => new PolicyUpdated($entry),
        'préavis' => new InactiveAccountNotice(Carbon::parse('2025-11-02'), Carbon::parse('2026-11-03')),
        'rappel' => new UnverifiedAccountReminder(Carbon::parse('2026-10-11')),
        'inscription' => new VerifyApiEmail,
    ])->map(fn ($notification) => $notification->toMail($user))->all();
}

function renderedEmails(): array
{
    return collect(emailMessages())->map(fn ($mail) => $mail->render()->toHtml())->all();
}

/** La version texte, telle que MailChannel la construit (multipart/alternative). */
function textEmails(): array
{
    return collect(emailMessages())
        ->map(fn ($mail) => (string) app(Markdown::class)->renderText($mail->markdown, $mail->data()))
        ->all();
}

test('chaque email des joueurs porte le gabarit de l\'Office, bilingue, sans reliquat du gabarit Laravel', function (string $name) {
    $html = renderedEmails()[$name];

    expect($html)
        ->toContain(PLAYER_URL.'/images/email/logo-horizontal.png')
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

test('🔴 aucun email ne mentionne le domaine de l\'administration (HTML et texte)', function (string $name) {
    // Un domaine « admin » dans un email de joueur ressemble à de l'hameçonnage (Greg, 05/10/2026).
    // Logo, site ET liens de confirmation (App\Support\EmailVerificationLink) viennent de
    // app.frontend_url ; la page du site rappelle l'API.
    expect(renderedEmails()[$name])->not->toContain(ADMIN_URL)
        ->and(textEmails()[$name])->not->toContain(ADMIN_URL);
})->with(['politique', 'préavis', 'rappel', 'inscription']);

test('la version texte reprend le contenu de la version HTML, sans reliquat Laravel ni Markdown brut', function (string $name) {
    $text = textEmails()[$name];

    expect($text)
        ->toStartWith(config('app.name').': '.PLAYER_URL)
        ->toContain(__('mail.unofficial', [], 'fr'))->toContain(__('mail.unofficial', [], 'en'))
        ->toContain('Ludiquement,')->toContain('Playfully,')
        ->not->toContain('Tous droits réservés')->not->toContain('All rights reserved')
        ->not->toContain('](')
        ->not->toMatch('/^[ \t]+\S/m'); // aucune ligne indentée par le gabarit
})->with(['politique', 'préavis', 'rappel', 'inscription']);

test('l\'email d\'inscription annonce la vraie durée du lien', function () {
    config(['auth.verification.expire' => 45]);
    $html = (new VerifyApiEmail)->toMail(User::factory()->make(['id' => 1]))->render()->toHtml();

    expect($html)->toContain('45 minutes')->not->toContain('60 minutes');
});

test('chaque clé des emails existe en français ET en anglais', function (string $file) {
    $keys = fn (string $locale) => array_keys(Arr::dot(require lang_path("{$locale}/{$file}.php")));

    expect($keys('en'))->toBe($keys('fr'));
})->with(['mail', 'verification']);
