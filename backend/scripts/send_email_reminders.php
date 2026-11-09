<?php
// Script d'administration : exécutable en ligne de commande uniquement, jamais via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
// Script de rappels par email (résumé quotidien + escalade), à exécuter périodiquement
// via une tâche planifiée (cron) — ce projet n'a pas de worker en tâche de fond, donc
// rien n'envoie ces emails tant que ce script n'est pas planifié.
//
// N'envoie rien si les notifications email sont désactivées (Paramètres > Notifications),
// et respecte les fréquences configurées (frequence_digest_heures / frequence_escalade_heures) :
// relancer ce script plus souvent que ces fréquences est sans risque, il se contente de
// ne rien renvoyer tant que le délai n'est pas écoulé.
//
// Exemple de ligne crontab (vérifie toutes les heures, envoie selon les fréquences
// configurées) — adapter le chemin du binaire PHP à l'environnement cible :
//   0 * * * * /Applications/MAMP/bin/php/php8.5.0/bin/php /Applications/MAMP/htdocs/parc/backend/scripts/send_email_reminders.php >> /Applications/MAMP/htdocs/parc/backend/scripts/reminders.log 2>&1

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../lib/notifications.php';

/** Nombre d'heures écoulées depuis une date ISO 8601 ; INF si jamais envoyé (toujours dû). */
function heures_depuis(?string $isoDate): float
{
    if (empty($isoDate)) {
        return INF;
    }
    $timestamp = strtotime($isoDate);
    if ($timestamp === false) {
        return INF;
    }
    return (time() - $timestamp) / 3600;
}

/** Écrit une clé de bookkeeping interne (last_digest_sent_at / last_escalade_sent_at). */
function set_parametre_interne(PDO $pdo, string $cle, string $valeur): void
{
    $stmt = $pdo->prepare("
        INSERT INTO t_parametres (cle, valeur, date_modification)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT (cle) DO UPDATE SET valeur = EXCLUDED.valeur, date_modification = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$cle, $valeur]);
}

$params = get_parametres($pdo);

if (($params['email_notifications_actives'] ?? '0') !== '1') {
    echo "Notifications email désactivées (Paramètres > Notifications). Rien à faire.\n";
    exit(0);
}

$maintenant = date('c');

if (($params['email_on_digest_quotidien'] ?? '0') === '1') {
    if (heures_depuis($params['last_digest_sent_at']) >= (float) $params['frequence_digest_heures']) {
        $envoyes = send_digest_emails($pdo, $params);
        set_parametre_interne($pdo, 'last_digest_sent_at', $maintenant);
        echo "Digest quotidien : $envoyes email(s) envoyé(s).\n";
    } else {
        echo "Digest quotidien : pas encore dû (fréquence : {$params['frequence_digest_heures']}h).\n";
    }
} else {
    echo "Digest quotidien désactivé.\n";
}

if (($params['email_on_escalade'] ?? '0') === '1') {
    if (heures_depuis($params['last_escalade_sent_at']) >= (float) $params['frequence_escalade_heures']) {
        $envoyes = send_escalade_emails($pdo, $params);
        set_parametre_interne($pdo, 'last_escalade_sent_at', $maintenant);
        echo "Escalade : $envoyes email(s) envoyé(s).\n";
    } else {
        echo "Escalade : pas encore due (fréquence : {$params['frequence_escalade_heures']}h).\n";
    }
} else {
    echo "Escalade par email désactivée.\n";
}
