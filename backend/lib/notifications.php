<?php
// Fonctions d'envoi des emails de notification, partagées entre les API (notification
// immédiate à l'assignation d'une tâche) et le script planifié de rappels (résumé
// quotidien, escalade). Toujours "best-effort" : une panne SMTP est journalisée
// (error_log) mais ne doit jamais interrompre l'appelant (création/mise à jour d'une
// tâche, exécution du script de rappels).
//
// À inclure après backend/config/db.php et backend/config/parametres.php.

require_once __DIR__ . '/Mailer.php';

/**
 * Retourne l'adresse email d'un utilisateur d'après son nom affiché (t_taches.assigne_a
 * stocke le nom — t_utilisateurs.nom — pas le login, voir loadUsersInSelects() côté
 * frontend). Utilise la colonne email ; à défaut, retombe sur le login s'il ressemble
 * lui-même à une adresse email (comptes plus anciens créés avant l'ajout de ce champ).
 */
function find_user_email_by_nom(PDO $pdo, string $nom): ?string
{
    if (trim($nom) === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT email, login FROM t_utilisateurs WHERE nom = ? LIMIT 1");
    $stmt->execute([$nom]);
    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$utilisateur) {
        return null;
    }
    if (!empty($utilisateur['email'])) {
        return $utilisateur['email'];
    }
    if (!empty($utilisateur['login']) && str_contains($utilisateur['login'], '@')) {
        return $utilisateur['login'];
    }
    return null;
}

/**
 * Envoie un email via la configuration SMTP courante, si les notifications email sont
 * activées. Ne lève jamais d'exception : retourne true/false et journalise l'erreur.
 */
function send_notification_email(array $params, string $to, string $subject, string $body): bool
{
    if (($params['email_notifications_actives'] ?? '0') !== '1') {
        return false;
    }
    try {
        $mailer = new SmtpMailer($params);
        return $mailer->send($to, $subject, $body);
    } catch (SmtpException $e) {
        error_log('Échec envoi email (' . $to . ') : ' . $e->getMessage());
        return false;
    }
}

/**
 * Notifie par email l'utilisateur assigné à une tâche nouvellement créée ou réassignée.
 * Best-effort : appeler dans un try/catch (ou s'assurer qu'aucune exception ne remonte)
 * pour ne jamais faire échouer la création/mise à jour de la tâche.
 */
function notify_task_assigned(PDO $pdo, array $params, array $tache): void
{
    if (($params['email_on_nouvelle_tache_assignee'] ?? '0') !== '1') {
        return;
    }
    $assigneA = trim($tache['assigne_a'] ?? '');
    if ($assigneA === '') {
        return;
    }
    $email = find_user_email_by_nom($pdo, $assigneA);
    if (!$email) {
        return;
    }

    $subject = 'Nouvelle tâche assignée : ' . $tache['titre'];
    $body = "Bonjour {$assigneA},\n\n"
        . "Une tâche vous a été assignée dans Parc Info :\n\n"
        . "Titre : {$tache['titre']}\n"
        . 'Priorité : ' . ($tache['priorite'] ?: '-') . "\n"
        . 'Service concerné : ' . ($tache['service_concerne'] ?: '-') . "\n"
        . 'Date limite : ' . ($tache['date_limite'] ?: 'non définie') . "\n"
        . 'Description : ' . ($tache['description'] ?: '-') . "\n\n"
        . "Connectez-vous à Parc Info pour la traiter.\n";

    send_notification_email($params, $email, $subject, $body);
}

/**
 * Envoie à chaque agent concerné un résumé de ses tâches en retard / à échéance
 * proche (seuil `seuil_echeance_proche_jours`). Retourne le nombre d'emails envoyés
 * avec succès. Utilisé par le script planifié backend/scripts/send_email_reminders.php.
 */
function send_digest_emails(PDO $pdo, array $params): int
{
    $seuilEcheance = (int) $params['seuil_echeance_proche_jours'];

    $stmt = $pdo->prepare("
        SELECT
            assigne_a,
            SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé')
                     AND date_limite IS NOT NULL AND date_limite < CURRENT_DATE
                THEN 1 ELSE 0 END) as late_tasks,
            SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé')
                     AND date_limite IS NOT NULL
                     AND date_limite >= CURRENT_DATE
                     AND date_limite <= CURRENT_DATE + (? || ' days')::interval
                THEN 1 ELSE 0 END) as echeance_proche_tasks,
            SUM(CASE WHEN statut = 'En cours' THEN 1 ELSE 0 END) as in_progress_tasks
        FROM t_taches
        WHERE assigne_a IS NOT NULL AND TRIM(assigne_a) <> ''
        GROUP BY assigne_a
        HAVING
            SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé')
                     AND date_limite IS NOT NULL AND date_limite < CURRENT_DATE
                THEN 1 ELSE 0 END) > 0
            OR SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé')
                     AND date_limite IS NOT NULL
                     AND date_limite >= CURRENT_DATE
                     AND date_limite <= CURRENT_DATE + (? || ' days')::interval
                THEN 1 ELSE 0 END) > 0
    ");
    $stmt->execute([$seuilEcheance, $seuilEcheance]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $envoyes = 0;
    foreach ($lignes as $ligne) {
        $email = find_user_email_by_nom($pdo, $ligne['assigne_a']);
        if (!$email) {
            continue;
        }

        $parts = [];
        if ($ligne['late_tasks'] > 0) {
            $parts[] = "{$ligne['late_tasks']} en retard";
        }
        if ($ligne['echeance_proche_tasks'] > 0) {
            $parts[] = "{$ligne['echeance_proche_tasks']} à échéance proche";
        }

        $subject = 'Parc Info — Résumé de vos tâches';
        $body = "Bonjour {$ligne['assigne_a']},\n\n"
            . 'Vous avez ' . implode(' et ', $parts) . ".\n"
            . ($ligne['in_progress_tasks'] > 0 ? "({$ligne['in_progress_tasks']} tâche(s) en cours)\n" : '')
            . "\nConnectez-vous à Parc Info pour les consulter.\n";

        if (send_notification_email($params, $email, $subject, $body)) {
            $envoyes++;
        }
    }
    return $envoyes;
}

/**
 * Envoie une alerte listant les tâches "À faire" jamais prises en charge depuis
 * `seuil_non_prise_en_charge_jours` jours, aux destinataires configurés
 * (`escalade_email_destinataires`, ou tous les comptes Administrateur si vide).
 * Retourne le nombre d'emails envoyés avec succès.
 */
function send_escalade_emails(PDO $pdo, array $params): int
{
    $seuilJours = (int) $params['seuil_non_prise_en_charge_jours'];

    $stmt = $pdo->prepare("
        SELECT titre, assigne_a, service_concerne, date_creation,
               (CURRENT_DATE - date_creation::date) as jours_en_attente
        FROM t_taches
        WHERE statut = 'À faire'
          AND date_creation::date <= CURRENT_DATE - (? || ' days')::interval
        ORDER BY date_creation ASC
        LIMIT 50
    ");
    $stmt->execute([$seuilJours]);
    $taches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($taches) === 0) {
        return 0;
    }

    $destinatairesConfig = trim($params['escalade_email_destinataires'] ?? '');
    if ($destinatairesConfig !== '') {
        $destinataires = array_values(array_filter(array_map('trim', explode(',', $destinatairesConfig))));
    } else {
        // Email si renseigné, sinon repli sur le login s'il ressemble à une adresse email
        // (même logique que find_user_email_by_nom, appliquée ici à tous les Administrateurs).
        $stmt = $pdo->prepare("SELECT email, login FROM t_utilisateurs WHERE role = 'Administrateur'");
        $stmt->execute();
        $destinataires = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $admin) {
            if (!empty($admin['email'])) {
                $destinataires[] = $admin['email'];
            } elseif (!empty($admin['login']) && str_contains($admin['login'], '@')) {
                $destinataires[] = $admin['login'];
            }
        }
    }

    $lignes = array_map(function ($t) {
        return '- ' . $t['titre'] . ' (assignée à : ' . ($t['assigne_a'] ?: 'personne')
            . ', service : ' . ($t['service_concerne'] ?: '-')
            . ", en attente depuis {$t['jours_en_attente']} jour(s))";
    }, $taches);

    $subject = 'Parc Info — Tâches non prises en charge';
    $body = "Bonjour,\n\n"
        . count($taches) . " tâche(s) \"À faire\" n'ont pas été prises en charge depuis plus de {$seuilJours} jours :\n\n"
        . implode("\n", $lignes) . "\n\n"
        . "Connectez-vous à Parc Info pour les consulter ou les réassigner.\n";

    $envoyes = 0;
    foreach ($destinataires as $login) {
        if (send_notification_email($params, $login, $subject, $body)) {
            $envoyes++;
        }
    }
    return $envoyes;
}
