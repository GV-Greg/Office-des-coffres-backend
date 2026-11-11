<?php

namespace App\Notifications;

/**
 * Confirmation de l'adresse indiquée à la création (D29) : le message ne porte que le lien signé — jamais un mot de passe.
 * Une adresse non confirmée n'est jamais utilisée. Commun à tous les types d'activité : le message nomme le type, et ne promet
 * un compte rendu de fin que pour ceux qui en envoient un (`$report`, voir `AnimKinds`).
 */
class AnimEmailConfirmation extends AnimMessage
{
    public function __construct(
        private readonly string $name,
        private readonly string $slug,
        private readonly string $url,
        private readonly string $kind = 'decode',
        private readonly bool $report = true,
    ) {}

    protected function params(): array
    {
        return ['name' => $this->name, 'slug' => $this->slug];
    }

    /** Le nom du type est rendu dans la langue de chaque moitié du message. */
    private function typeName(string $locale): string
    {
        return __("anim.types.{$this->kind}.name", [], $locale);
    }

    protected function subjectKey(): string
    {
        return 'anim.email.confirm_subject';
    }

    protected function lines(string $locale): array
    {
        return [
            __('anim.email.greeting', [], $locale),
            __('anim.email.confirm_intro', $this->params() + ['type' => $this->typeName($locale)], $locale),
            __($this->report ? 'anim.email.confirm_use' : 'anim.email.confirm_use_no_report', [], $locale),
            __('anim.email.confirm_ignore', [], $locale),
        ];
    }

    protected function action(): ?array
    {
        return ['anim.email.confirm_action', $this->url];
    }
}
