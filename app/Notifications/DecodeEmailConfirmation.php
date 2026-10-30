<?php

namespace App\Notifications;

/**
 * Confirmation de l'adresse indiquée à la création (D29) : le message ne porte que le lien signé — jamais un mot de passe.
 * Une adresse non confirmée n'est jamais utilisée.
 */
class DecodeEmailConfirmation extends DecodeMessage
{
    public function __construct(
        private readonly string $name,
        private readonly string $slug,
        private readonly string $url,
    ) {}

    protected function params(): array
    {
        return ['name' => $this->name, 'slug' => $this->slug];
    }

    protected function subjectKey(): string
    {
        return 'anim.email.confirm_subject';
    }

    protected function lines(string $locale): array
    {
        return [
            __('anim.email.greeting', [], $locale),
            __('anim.email.confirm_intro', $this->params(), $locale),
            __('anim.email.confirm_use', [], $locale),
            __('anim.email.confirm_ignore', [], $locale),
        ];
    }

    protected function action(): ?array
    {
        return ['anim.email.confirm_action', $this->url];
    }
}
