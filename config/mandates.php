<?php

/*
| Mandats — durées et règles chiffrées (admin/content/brief-mandats.md, §5quinquies de
| admin/strategies/donnees-utilisateur.md). Un seul fichier : un test vérifie que les calculs
| lisent ces valeurs.
|
| Ce sont des JOURS FIXES, pas des mois du calendrier : addDays, jamais addMonth. Ils se comptent
| sur la date civile du fuseau du jeu, puis se stockent en UTC.
*/

return [
    // Fuseau du jeu : un mandat commencé le 1er mars court jusqu'au 31 mars à 00:00 de CE fuseau.
    'timezone' => 'Europe/Paris',

    'mayor_days' => 30,
    'council_days' => 60,

    // Le nouveau conseil n'entre en fonction qu'à l'élection du dirigeant ; les 12 élus ont deux
    // jours pour l'élire (wiki officiel, vérifié le 03/10/2026). Le sortant reste en poste jusque-là.
    'council_grace_days' => 2,

    // Tranche de chaque prolongation manuelle par l'administrateur (égalité au vote du dirigeant).
    'council_extension_days' => 2,

    // 12 sièges, 10 postes titrés (dirigeant compris) : deux conseillers sans portefeuille.
    'council_unassigned_seats' => 2,

    // Un mandat se renouvelle tant qu'il est actif, ou terminé depuis ce nombre de jours au plus.
    'renewal_window_days' => 15,

    // File de vérification des mandats terminés : au-delà de ce nombre de jours sans vérification,
    // la ligne est signalée (et, au lot 3, déclenche l'email). Le maire a la moitié du conseiller,
    // comme son mandat (30 j contre 60 j).
    'verification_days' => ['mayor' => 4, 'council' => 7],

    // ─── Lot 3 : tâches planifiées (routes/console.php) ───────────────────────────────────

    // Heure de passage des trois tâches quotidiennes, fuseau du jeu.
    'schedule_at' => '09:00',

    // Rappel au joueur ce nombre de jours APRÈS la fin EFFECTIVE de son mandat (holds_until), tant
    // qu'il est encore renouvelable (renewal_window_days). Pas avant : pendant les derniers jours,
    // l'élection est en cours et le joueur ne sait pas s'il est réélu (décision de Greg, 03/10/2026,
    // fil mandats-lot3, tour 07).
    'reminder_after_days' => 2,

    // Purge des refus : un `rejected` vit ce nombre de mois après sa décision (processed_at).
    // Une demande en attente n'est JAMAIS purgée : c'est une tâche de l'administrateur.
    'rejected_retention_months' => 3,

    // Au-delà de ce nombre d'heures sans passage RÉUSSI d'une tâche, le tableau de bord alerte.
    // Granularité quotidienne : 24 h + marge (fil mandats-lot3, Q6).
    'heartbeat_alert_hours' => 36,

    // Codes des motifs envoyés au joueur (refus d'une demande, révocation d'un mandat). Stockés EN
    // TEXTE dans decision_reason, jamais en FK : ajouter, renommer ou retirer un code ne touche pas
    // aux données. Libellés dans lang/{fr,en}/mandates.php ; un code dont le libellé a disparu
    // s'affiche tel quel, jamais en vide. `autre` exige un commentaire.
    'reject_reasons' => ['annonce_non_concluante', 'poste_deja_occupe', 'personnage_non_valide', 'date_incoherente', 'autre'],
    'revoke_reasons' => ['demission', 'retranchement', 'revolte', 'remplace', 'autre'],

    // Motifs d'EMAIL d'un retrait de poste sans successeur (Q16) : seulement les causes où
    // personne ne gagne le poste. Pas de `reattribue` ici — une perte causée par le gain d'un
    // autre n'envoie pas d'email au perdant.
    'office_remove_reasons' => ['retire_par_le_dirigeant', 'declaration_erronee', 'autre'],

    // Causes de fin d'une période de poste (Q21), pour l'historique et l'export. ⚠️ Liste DISTINCTE
    // de office_remove_reasons, à ne pas fusionner : celle-ci contient TOUTES les causes,
    // `reattribue` et `fin_du_mandat` compris ; l'autre ne sert qu'à l'email.
    'period_end_reasons' => [
        'fin_du_mandat', 'reattribue', 'retire_par_le_dirigeant', 'declaration_erronee', 'autre',
        'declare_par_le_joueur',
    ],
];
