<?php
// Génère les tâches de maintenance préventive dont l'échéance approche. À planifier une fois par jour :
//   0 6 * * * php /chemin/backend/scripts/maintenance.php >> /chemin/backend/scripts/maintenance.log 2>&1
// Rejouable sans risque de doublon (une tâche par plan, matériel et échéance).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/taches.php';
require_once __DIR__ . '/../lib/materiel.php';
require_once __DIR__ . '/../lib/maintenance.php';

$r = maint_generer($pdo);
echo date('c') . " plans traités : {$r['plans']}, tâches créées : {$r['taches']}\n";
