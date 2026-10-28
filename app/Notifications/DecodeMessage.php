<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Socle des quatre emails de « Décode le code » (D23, D29, D31, D35, D37) : TOUJOURS bilingues, français puis anglais,
 * signés comme les autres emails de l'Office, sans lien vers le domaine d'administration. Aucun ne contient de mot de
 * passe. Synchrones (aucune file d'attente : `failed_jobs` reste hors périmètre).
 */
abstract class DecodeMessage extends Notification
{
    /** @return array<string, mixed> paramètres de traduction communs à l'objet et aux lignes */
    abstract protected function params(): array;

    abstract protected function subjectKey(): string;

    /** @return list<string> lignes du message dans la langue demandée */
    abstract protected function lines(string $locale): array;

    /** @return array{0: string, 1: string}|null [clé de traduction du bouton, adresse] */
    protected function action(): ?array
    {
        return null;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = $this->params();
        $mail = (new MailMessage)->subject(__($this->subjectKey(), $params, 'fr').' / '.__($this->subjectKey(), $params, 'en'));

        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            foreach ($this->lines($locale) as $line) {
                $mail->line($line);
            }
        }

        if ($action = $this->action()) {
            $mail->action(__($action[0], [], 'fr').' / '.__($action[0], [], 'en'), $action[1]);
        }

        return $mail->salutation(__('accounts.email.salutation', [], 'fr')."\n\n".__('accounts.email.salutation', [], 'en'));
    }
}
