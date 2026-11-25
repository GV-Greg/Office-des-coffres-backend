<?php

// Emails d'authentification (VerifyApiEmail, VerifyEmailChange, EmailChanged, PasswordChanged).
// Toujours français puis anglais : chaque clé existe aussi dans lang/en/verification.php.
return [
    'email' => [
        'subject' => 'Confirmez votre adresse email',
        'greeting' => 'Bonjour,',
        'thanks' => "Merci de vous être inscrit sur l'Office des coffres. Confirmez votre adresse pour activer votre compte.",
        'expires' => 'Ce lien expire dans :minutes minutes.',
        'not_you' => "Si vous n'êtes pas à l'origine de cette inscription, vous pouvez ignorer cet email.",
        'action' => 'Confirmer mon email',
        'salutation' => "Ludiquement,\nGregory pour l'Office des coffres",
    ],
    'email_change' => [
        'subject'    => 'Confirmez votre nouvelle adresse email',
        'greeting'   => 'Bonjour,',
        'intro'      => "Vous avez demandé à modifier l'adresse email associée à votre compte sur l'Office des coffres. Confirmez votre nouvelle adresse en cliquant sur le lien ci-dessous.",
        'expires'    => 'Ce lien expire dans :minutes minutes.',
        'not_you'    => "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email. Votre adresse actuelle reste inchangée.",
        'action'     => 'Confirmer ma nouvelle adresse',
        'salutation' => "Ludiquement,\nGregory pour l'Office des coffres",
    ],
    'email_changed_notice' => [
        'subject'    => "Votre adresse email a été modifiée",
        'greeting'   => 'Bonjour,',
        'intro'      => "L'adresse email de votre compte sur l'Office des coffres vient d'être changée pour : :new_email",
        'warning'    => "Si vous n'êtes pas à l'origine de ce changement, contactez immédiatement l'administrateur.",
        'salutation' => "Ludiquement,\nGregory pour l'Office des coffres",
    ],
    'password_reset' => [
        'subject'    => 'Réinitialisation de votre mot de passe',
        'greeting'   => 'Bonjour,',
        'intro'      => "Vous avez demandé à choisir un nouveau mot de passe pour votre compte sur l'Office des coffres. Cliquez sur le bouton ci-dessous pour le choisir.",
        'expires'    => 'Ce lien expire dans :minutes minutes et ne sert qu\'une fois.',
        'not_you'    => "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email : votre mot de passe reste inchangé.",
        'action'     => 'Choisir un nouveau mot de passe',
        'salutation' => "Ludiquement,\nGregory pour l'Office des coffres",
    ],
    'password_changed_notice' => [
        'subject'    => 'Votre mot de passe a été modifié',
        'greeting'   => 'Bonjour,',
        'intro'      => "Le mot de passe de votre compte sur l'Office des coffres vient d'être modifié.",
        'warning'    => "Si vous n'êtes pas à l'origine de ce changement, contactez immédiatement l'administrateur.",
        'salutation' => "Ludiquement,\nGregory pour l'Office des coffres",
    ],
];
