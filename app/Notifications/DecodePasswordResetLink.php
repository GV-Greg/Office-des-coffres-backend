<?php

namespace App\Notifications;

/**
 * Lien de réinitialisation du mot de passe (D35) : à usage unique, 24 h ; l'auteur CHOISIT lui-même son nouveau mot
 * de passe sur le site. Aucun mot de passe ne circule par email.
 */
class DecodePasswordResetLink extends DecodeMessage
{
    public function __construct(
        private readonly string $name,
        private readonly string $url,
    ) {}

    protected function params(): array
    {
        return ['name' => $this->name];
    }

    protected function subjectKey(): string
    {
        return 'anim.email.reset_subject';
    }

    protected function lines(string $locale): array
    {
        return [
            __('anim.email.greeting', [], $locale),
            __('anim.email.reset_intro', $this->params(), $locale),
            __('anim.email.reset_valid', [], $locale),
            __('anim.email.reset_ignore', [], $locale),
        ];
    }

    protected function action(): ?array
    {
        return ['anim.email.reset_action', $this->url];
    }
}
