<?php

// Email de confirmation d'inscription (App\Notifications\VerifyApiEmail). Part toujours en français
// puis en anglais : chaque clé existe aussi dans lang/en/verification.php.
return [
    'email' => [
        'subject' => 'Office des coffres — confirmez votre adresse email',
        'greeting' => 'Bonjour,',
        'thanks' => "Merci de vous être inscrit sur l'Office des coffres. Confirmez votre adresse pour activer votre compte.",
        'expires' => 'Ce lien expire dans :minutes minutes.',
        'not_you' => "Si vous n'êtes pas à l'origine de cette inscription, vous pouvez ignorer cet email.",
        'action' => 'Confirmer mon email',
        'salutation' => "Ludiquement,\nOffice des coffres",
    ],
];
