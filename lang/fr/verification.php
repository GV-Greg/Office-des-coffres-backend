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
        'salutation' => "Ludiquement,\nOffice des coffres",
    ],
    'email_change' => [
        'subject'    => 'Confirmez votre nouvelle adresse email',
        'greeting'   => 'Bonjour,',
        'intro'      => "Vous avez demandé à modifier l'adresse email associée à votre compte sur l'Office des coffres. Confirmez votre nouvelle adresse en cliquant sur le lien ci-dessous.",
        'expires'    => 'Ce lien expire dans :minutes minutes.',
        'not_you'    => "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email. Votre adresse actuelle reste inchangée.",
        'action'     => 'Confirmer ma nouvelle adresse',
        'salutation' => "Ludiquement,\nOffice des coffres",
    ],
    'email_changed_notice' => [
        'subject'    => "Votre adresse email a été modifiée",
        'greeting'   => 'Bonjour,',
        'intro'      => "L'adresse email de votre compte sur l'Office des coffres vient d'être changée pour : :new_email",
        'warning'    => "Si vous n'êtes pas à l'origine de ce changement, contactez immédiatement l'administrateur.",
        'salutation' => "Ludiquement,\nOffice des coffres",
    ],
];
