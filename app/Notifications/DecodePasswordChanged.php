<?php

namespace App\Notifications;

/**
 * Avis envoyé après CHAQUE changement de mot de passe (D37), pour qu'une prise de contrôle ne passe pas inaperçue.
 */
class DecodePasswordChanged extends DecodeMessage
{
    public function __construct(private readonly string $name) {}

    protected function params(): array
    {
        return ['name' => $this->name];
    }

    protected function subjectKey(): string
    {
        return 'anim.email.changed_subject';
    }

    protected function lines(string $locale): array
    {
        return [
            __('anim.email.greeting', [], $locale),
            __('anim.email.changed_notice', $this->params(), $locale),
            __('anim.email.changed_alert', [], $locale),
        ];
    }
}
