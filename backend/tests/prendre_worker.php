<?php
// Sous-processus du test de concurrence : tente de prendre en charge une panne.
// Usage : php prendre_worker.php <tache_id> <user_id>   →   affiche « OK » ou « <code> » (échec).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../lib/taches.php';
$st = $pdo->prepare("SELECT id, nom, login, role FROM t_utilisateurs WHERE id = ?");
$st->execute([(int) $argv[2]]);
$u = $st->fetch();
$u['permissions'] = charger_permissions($pdo, $u['role']);
usleep(random_int(0, 20000));
$r = tache_transition($pdo, (int) $argv[1], $u, 'prendre');
echo $r['ok'] ? 'OK' : $r['code'];
