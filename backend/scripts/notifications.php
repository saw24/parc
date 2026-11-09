<?php
// Traite les évènements en attente et livre les e-mails / SMS / push en file (avec reprises).
// À planifier chaque minute en production ; filet de sécurité : l'envoi est déjà tenté à la fin de
// chaque requête, ce script rattrape les canaux momentanément indisponibles.
//   * * * * * php /chemin/backend/scripts/notifications.php >> /chemin/backend/scripts/notifications.log 2>&1
// Options : --etat (affiche l'état de la file sans rien envoyer).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../lib/notif_pannes.php';

if (in_array('--etat', $argv, true)) {
    foreach ($pdo->query("SELECT canal, statut, COUNT(*) n FROM t_notifications_envois GROUP BY 1, 2 ORDER BY 1, 2") as $r) {
        echo "{$r['canal']} {$r['statut']} : {$r['n']}\n";
    }
    exit(0);
}
$params = get_parametres($pdo);
$crees = notif_traiter_evenements($pdo);
[$envoyes, $echecs] = notif_envoyer_file($pdo, $params, 200);
echo date('c') . " notifications créées : $crees, envois réussis : $envoyes, abandons : $echecs\n";
