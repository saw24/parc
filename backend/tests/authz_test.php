<?php
// Tests d'autorisation de bout en bout (HTTP réel). Usage :
//   php backend/tests/authz_test.php [URL_API]     (défaut : http://localhost/parc/backend/api)
//
// Crée des comptes et données de test préfixés « zz_test_ » dans la base configurée, les
// supprime à la fin (même en cas d'échec), et ne touche à aucune donnée existante.
// NE PAS lancer sur la base de production.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI uniquement.'); }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../lib/taches.php';

$base = rtrim($argv[1] ?? 'http://localhost/parc/backend/api', '/');
$ok = 0; $ko = 0;
function check(string $nom, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ✓ $nom\n"; } else { $ko++; echo "  ✗ $nom $detail\n"; }
}
function call(string $method, string $url, array $data = [], ?array $auth = null): array {
    $ch = curl_init($url);
    $headers = [];
    if ($auth) { $headers[] = 'X-Session-Token: ' . $auth['token']; $headers[] = 'X-User-Id: ' . $auth['id']; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string) $body, true), (string) $body];
}

$pwd = 'Zz-' . bin2hex(random_bytes(6));
$comptes = [
    'admin' => ['zz_test_admin', 'Administrateur'],
    'tech'  => ['zz_test_tech', 'zz_role_tech'],
    'tech2' => ['zz_test_tech2', 'zz_role_tech'],
    'autre' => ['zz_test_autre', 'zz_role_sansdroit'],
    'agent' => ['zz_test_agent', 'zz_role_agent'],
];
// Rôles de test autonomes : « tech » reproduit le préréglage Technicien de la migration 002.
$rolesTest = [
    'zz_role_tech' => ['tache.voir_toutes', 'tache.creer', 'tache.modifier_siennes', 'tache.prendre', 'tache.affecter',
        'materiel.voir_tous', 'materiel.creer', 'materiel.modifier', 'referentiel.voir', 'referentiel.gerer',
        'utilisateur.lister', 'dashboard.voir'],
    'zz_role_sansdroit' => [],
    'zz_role_agent' => ['tache.declarer', 'tache.voir_siennes', 'materiel.voir_siens'],
];
$ids = []; $auth = [];
$tacheIds = [];

function nettoyer(PDO $pdo, array $comptes): void {
    global $rolesTest;
    $logins = array_map(fn($c) => $c[0], array_values($comptes));
    $in = implode(',', array_fill(0, count($logins), '?'));
    $pdo->prepare("DELETE FROM t_taches WHERE titre LIKE 'ZZ_TEST_%'")->execute();
    $pdo->prepare("DELETE FROM t_services WHERE nom LIKE 'ZZ_TEST_%'")->execute();
    $pdo->prepare("DELETE FROM t_materiel WHERE num_serie LIKE 'ZZ_TEST_%'")->execute();
    $pdo->prepare("DELETE FROM t_login_attempts WHERE login LIKE 'zz_test_%'")->execute();
    $pdo->prepare("DELETE FROM t_utilisateurs WHERE login IN ($in)")->execute($logins);
    $rin = implode(',', array_fill(0, count($rolesTest), '?'));
    $pdo->prepare("DELETE FROM t_roles WHERE nom IN ($rin)")->execute(array_keys($rolesTest));
}

nettoyer($pdo, $comptes);
try {
    foreach ($rolesTest as $nomRole => $perms) {
        $rid = $pdo->prepare("INSERT INTO t_roles (nom, description) VALUES (?, 'test') RETURNING id");
        $rid->execute([$nomRole]);
        $rid = (int) $rid->fetchColumn();
        foreach ($perms as $code) {
            $pdo->prepare("INSERT INTO t_roles_permissions (role_id, permission_code) VALUES (?, ?)")->execute([$rid, $code]);
        }
    }
    foreach ($comptes as $cle => [$login, $role]) {
        $st = $pdo->prepare("INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe) VALUES (?, ?, ?, ?) RETURNING id");
        $st->execute(['ZZ ' . $login, $login, $role, password_hash($pwd, PASSWORD_DEFAULT)]);
        $ids[$cle] = (int) $st->fetchColumn();
    }

    echo "Authentification\n";
    [$c] = call('GET', "$base/taches.php?action=read");
    check('sans jeton → 401', $c === 401, "(reçu $c)");
    [$c] = call('GET', "$base/taches.php?action=read", [], ['token' => 'x', 'id' => $ids['admin']]);
    check('jeton invalide → 401', $c === 401, "(reçu $c)");

    foreach ($comptes as $cle => [$login]) {
        [$c, $j] = call('POST', "$base/auth.php?action=login", ['login' => $login, 'password' => $pwd]);
        check("connexion $cle", $c === 200 && !empty($j['session_token']), "(reçu $c)");
        $auth[$cle] = ['token' => $j['session_token'] ?? '', 'id' => $ids[$cle]];
    }

    echo "Jetons hachés en base\n";
    $st = $pdo->prepare("SELECT session_token FROM t_sessions WHERE user_id = ?");
    $st->execute([$ids['admin']]);
    $stocke = (string) $st->fetchColumn();
    check('le jeton clair n\'est pas stocké', $stocke !== $auth['admin']['token']);
    check('le hash SHA-256 est stocké', $stocke === hash('sha256', $auth['admin']['token']));

    echo "Rôle inconnu : aucun accès\n";
    foreach (['taches.php?action=read', 'materiel.php?action=read', 'services.php?action=read',
              'type_materiel.php?action=read', 'dashboard.php?action=stats', 'utilisateurs.php?action=read'] as $ep) {
        [$c] = call('GET', "$base/$ep", [], $auth['autre']);
        check("GET $ep → 403", $c === 403, "(reçu $c)");
    }
    [$c] = call('GET', "$base/utilisateurs.php?action=read&id={$ids['admin']}", [], $auth['autre']);
    check('lire la fiche d\'un autre utilisateur → 403', $c === 403, "(reçu $c)");
    [$c] = call('GET', "$base/utilisateurs.php?action=read&id={$ids['autre']}", [], $auth['autre']);
    check('lire sa propre fiche → 200', $c === 200, "(reçu $c)");

    echo "Technicien : pas de suppression ni de gestion des comptes\n";
    $svc = ['nom' => 'ZZ_TEST_SVC', 'description' => 't'];
    [$c, $j] = call('POST', "$base/services.php?action=create", $svc, $auth['admin']);
    check('admin crée un service', $c === 200 || $c === 201, "(reçu $c)");
    $svcId = (int) $pdo->query("SELECT id FROM t_services WHERE nom = 'ZZ_TEST_SVC'")->fetchColumn();
    [$c] = call('POST', "$base/services.php?action=delete", ['id' => $svcId], $auth['tech']);
    check('technicien : suppression service → 403', $c === 403, "(reçu $c)");
    [$c] = call('POST', "$base/utilisateurs.php?action=create", ['nom' => 'x', 'login' => 'zz_test_x', 'role' => 'Administrateur', 'mot_de_passe' => 'abcdefgh'], $auth['tech']);
    check('technicien : création d\'utilisateur → 403', $c === 403, "(reçu $c)");

    echo "Tâches : propriété et prise en charge\n";
    $t = ['priorite' => 'Moyenne', 'service_concerne' => 'ZZ_TEST_SVC', 'date_limite' => date('Y-m-d'), 'description' => '', 'motif_cause' => ''];
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_A', 'statut' => 'En cours', 'assigne_a' => 'ZZ zz_test_tech',
        'created_by_login' => 'usurpateur', 'updated_by_login' => 'usurpateur'], $auth['admin']);
    check('admin crée une tâche assignée au technicien', $c === 200, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_B', 'statut' => 'En cours', 'assigne_a' => 'ZZ zz_test_tech2'], $auth['admin']);
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_C', 'statut' => 'À faire', 'assigne_a' => 'ZZ zz_test_tech2'], $auth['admin']);
    $rows = $pdo->query("SELECT id, titre, created_by_login, updated_by_login FROM t_taches WHERE titre LIKE 'ZZ_TEST_%'")->fetchAll();
    foreach ($rows as $r) { $tacheIds[$r['titre']] = (int) $r['id']; }
    check('le login d\'audit vient de la session, pas du client',
        ($rows[0]['created_by_login'] ?? '') === 'zz_test_admin' && ($rows[0]['updated_by_login'] ?? '') === 'zz_test_admin',
        '(reçu ' . json_encode($rows[0] ?? null) . ')');

    $maj = fn(string $titre, string $statut, string $assigne) => $t + ['id' => $tacheIds[$titre], 'titre' => $titre, 'statut' => $statut, 'assigne_a' => $assigne];
    [$c] = call('POST', "$base/taches.php?action=update", $maj('ZZ_TEST_A', 'Terminé', 'ZZ zz_test_tech'), $auth['tech']);
    check('technicien modifie sa propre tâche → 200', $c === 200, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=update", $maj('ZZ_TEST_B', 'Terminé', 'ZZ zz_test_tech2'), $auth['tech']);
    check('technicien modifie la tâche d\'un autre → 403', $c === 403, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=update", $maj('ZZ_TEST_C', 'En cours', 'ZZ zz_test_tech2'), $auth['tech']);
    check('technicien prend en charge une tâche « À faire » → 200', $c === 200, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=update", $maj('ZZ_TEST_B', 'Terminé', 'ZZ zz_test_tech2'), $auth['autre']);
    check('rôle inconnu : modification → 403', $c === 403, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=delete", ['id' => $tacheIds['ZZ_TEST_B']], $auth['tech']);
    check('technicien : suppression de tâche → 403', $c === 403, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=update", array_merge($maj('ZZ_TEST_B', 'Terminé', 'ZZ zz_test_tech2'), ['id' => 99999999]), $auth['admin']);
    check('tâche inexistante → 404', $c === 404, "(reçu $c)");

    echo "Comptes : garde-fous\n";
    [$c] = call('POST', "$base/utilisateurs.php?action=delete", ['id' => $ids['admin']], $auth['admin']);
    check('admin ne peut pas se supprimer → 400', $c === 400, "(reçu $c)");
    [$c] = call('POST', "$base/utilisateurs.php?action=create", ['nom' => 'ZZ x', 'login' => 'zz_test_x', 'role' => 'SuperRoot', 'mot_de_passe' => 'abcdefgh1'], $auth['admin']);
    check('rôle arbitraire refusé → 400', $c === 400, "(reçu $c)");

    echo "Limitation des tentatives de connexion\n";
    $dernier = 0;
    for ($i = 0; $i < 6; $i++) { [$dernier] = call('POST', "$base/auth.php?action=login", ['login' => 'zz_test_tech2', 'password' => 'mauvais']); }
    check('6e échec consécutif → 429', $dernier === 429, "(reçu $dernier)");
    [$c] = call('POST', "$base/auth.php?action=login", ['login' => 'zz_test_tech2', 'password' => $pwd]);
    check('même le bon mot de passe est refusé pendant le blocage', $c === 429, "(reçu $c)");
    [$c] = call('POST', "$base/auth.php?action=login", ['login' => 'zz_test_tech', 'password' => $pwd]);
    check('un autre compte n\'est pas bloqué', $c === 200, "(reçu $c)");

    echo "Administrateur et technicien : les écrans existants fonctionnent toujours\n";
    foreach (['dashboard.php?action=stats', 'dashboard.php?action=parc_lifecycle', 'utilisateurs.php?action=read', 'parametres.php?action=read',
              'services.php?action=read', 'type_materiel.php?action=read', 'materiel.php?action=read', 'taches.php?action=read'] as $ep) {
        [$c] = call('GET', "$base/$ep", [], $auth['admin']);
        check("admin GET $ep → 200", $c === 200, "(reçu $c)");
    }
    foreach (['dashboard.php?action=stats', 'utilisateurs.php?action=read', 'services.php?action=read', 'materiel.php?action=read', 'taches.php?action=read'] as $ep) {
        [$c] = call('GET', "$base/$ep", [], $auth['tech']);
        check("technicien GET $ep → 200", $c === 200, "(reçu $c)");
    }
    [$c] = call('GET', "$base/parametres.php?action=read", [], $auth['tech']);
    check('technicien : paramètres complets → 403', $c === 403, "(reçu $c)");
    [$c] = call('GET', "$base/parametres.php?action=public", [], $auth['agent']);
    check('agent : paramètres publics → 200', $c === 200, "(reçu $c)");

    echo "Agent (déclarant) : file Service IT et visibilité limitée\n";
    $file = tache_file_commune($pdo);
    check('le compte « Service IT » existe', $file !== null);
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_AGENT1', 'statut' => 'Terminé',
        'assigne_a' => 'ZZ zz_test_tech', 'priorite' => 'Haute'], $auth['agent']);
    check('agent déclare une panne → 200', $c === 200, "(reçu $c)");
    $ligne = $pdo->query("SELECT * FROM t_taches WHERE titre = 'ZZ_TEST_AGENT1'")->fetch();
    check('assignée d\'office à Service IT (assigné/statut du client ignorés)',
        ($ligne['assigne_a'] ?? '') === 'Service IT' && ($ligne['statut'] ?? '') === 'À faire' && (int) $ligne['assigne_id'] === $file['id']);
    check('déclarant = agent, source web, référence générée',
        (int) $ligne['declarant_id'] === $ids['agent'] && $ligne['source'] === 'web' && str_starts_with((string) $ligne['reference'], 'T-'));
    check('échéances SLA renseignées (priorité Haute)', $ligne['echeance_prise_en_charge'] !== null && $ligne['echeance_resolution'] !== null);
    $tacheIds['ZZ_TEST_AGENT1'] = (int) $ligne['id'];
    [$c, $j] = call('GET', "$base/taches.php?action=read", [], $auth['agent']);
    $titres = array_column(is_array($j) ? $j : [], 'titre');
    check('l\'agent ne voit que ses propres pannes', $c === 200 && $titres === ['ZZ_TEST_AGENT1'], '(reçu ' . json_encode($titres) . ')');
    [$c] = call('POST', "$base/taches.php?action=update", array_merge($t, ['id' => $tacheIds['ZZ_TEST_AGENT1'], 'titre' => 'ZZ_TEST_AGENT1', 'statut' => 'Terminé', 'assigne_a' => 'Service IT', 'priorite' => 'Haute']), $auth['agent']);
    check('agent : modifier même sa propre panne via l\'API web → 403', $c === 403, "(reçu $c)");
    [$c] = call('POST', "$base/taches.php?action=update", $maj('ZZ_TEST_A', 'Terminé', 'ZZ zz_test_tech'), $auth['agent']);
    check('agent : tâche d\'un autre → 404 (invisible)', $c === 404, "(reçu $c)");
    [$c] = call('GET', "$base/materiel.php?action=read", [], $auth['agent']);
    check('agent : liste complète du matériel → 403', $c === 403, "(reçu $c)");
    [$c, $j] = call('POST', "$base/auth.php?action=login", ['login' => 'zz_test_agent', 'password' => $pwd]);
    check('login renvoie les permissions', in_array('tache.declarer', $j['user']['permissions'] ?? [], true));

    echo "Transitions (tache_transition)\n";
    $usr = function (string $cle) use ($pdo, $ids) {
        $st = $pdo->prepare("SELECT id, nom, login, role FROM t_utilisateurs WHERE id = ?");
        $st->execute([$ids[$cle]]);
        $u = $st->fetch();
        $u['permissions'] = charger_permissions($pdo, $u['role']);
        return $u;
    };
    $tid = $tacheIds['ZZ_TEST_AGENT1'];
    $r = tache_transition($pdo, $tid, $usr('agent'), 'prendre');
    check('agent sans « prendre » : 403', !$r['ok'] && $r['code'] === 403);
    $r = tache_transition($pdo, $tid, $usr('autre'), 'prendre');
    check('utilisateur sans accès à la panne : 404 (pas de fuite d\'existence)', !$r['ok'] && $r['code'] === 404);
    $r = tache_transition($pdo, $tid, $usr('tech'), 'prendre', ['version' => 99]);
    check('conflit de version : 409', !$r['ok'] && $r['code'] === 409);
    $r = tache_transition($pdo, $tid, $usr('tech'), 'prendre');
    check('agent IT prend la panne', $r['ok'] && (int) $r['tache']['assigne_id'] === $ids['tech'] && $r['tache']['statut'] === 'En cours' && $r['tache']['date_prise_en_charge'] !== null);
    $r = tache_transition($pdo, $tid, $usr('tech2'), 'prendre');
    check('seconde prise refusée (déjà prise) : 409', !$r['ok'] && $r['code'] === 409 && str_contains($r['error'], 'ZZ zz_test_tech'));
    $r = tache_transition($pdo, $tid, $usr('tech'), 'affecter', ['assigne_id' => $ids['agent']]);
    check('affecter à un compte sans droit « prendre » : 422', !$r['ok'] && $r['code'] === 422);
    $r = tache_transition($pdo, $tid, $usr('tech'), 'affecter', ['assigne_id' => $ids['tech2']]);
    check('affecter à un collègue IT', $r['ok'] && (int) $r['tache']['assigne_id'] === $ids['tech2']);
    $r = tache_transition($pdo, $tid, $usr('tech2'), 'statut', ['statut' => 'En attente']);
    check('« En attente » sans motif ni relance : 422', !$r['ok'] && $r['code'] === 422);
    $r = tache_transition($pdo, $tid, $usr('tech2'), 'statut', ['statut' => 'En attente', 'motif_attente' => 'Pièce commandée', 'date_relance' => date('Y-m-d', strtotime('+3 days'))]);
    check('« En attente » avec motif et relance', $r['ok'] && $r['tache']['statut'] === 'En attente');
    $r = tache_transition($pdo, $tid, $usr('tech'), 'statut', ['statut' => 'Terminé']);
    check('statut modifié par un agent IT non assigné (sans modifier_toutes) : 403', !$r['ok'] && $r['code'] === 403);
    $r = tache_transition($pdo, $tid, $usr('tech2'), 'statut', ['statut' => 'Terminé']);
    check('terminer : date_resolution renseignée', $r['ok'] && $r['tache']['date_resolution'] !== null);
    $r = tache_transition($pdo, $tid, $usr('tech2'), 'statut', ['statut' => 'En cours']);
    check('réouverture : date_resolution effacée', $r['ok'] && $r['tache']['date_resolution'] === null);
    $r = tache_transition($pdo, $tid, $usr('agent'), 'commenter', ['commentaire' => 'Toujours en panne ce matin']);
    check('le déclarant commente sa panne', $r['ok']);
    $r = tache_transition($pdo, $tid, $usr('tech2'), 'affecter', ['assigne_id' => $file['id']]);
    check('remettre dans la file Service IT → « À faire »', $r['ok'] && $r['tache']['statut'] === 'À faire' && (int) $r['tache']['assigne_id'] === $file['id']);
    $nbEvt = (int) $pdo->query("SELECT COUNT(*) FROM t_taches_evenements WHERE tache_id = $tid")->fetchColumn();
    check('chaque transition a écrit un évènement (8 attendus)', $nbEvt === 8, "(reçu $nbEvt)");

    // Annulation par le déclarant : possible tant que personne n'a pris la panne.
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_AGENT2', 'priorite' => 'Basse'], $auth['agent']);
    $tid2 = (int) $pdo->query("SELECT id FROM t_taches WHERE titre = 'ZZ_TEST_AGENT2'")->fetchColumn();
    $r = tache_transition($pdo, $tid2, $usr('agent'), 'statut', ['statut' => 'Annulé']);
    check('le déclarant annule sa panne avant prise en charge', $r['ok'] && $r['tache']['statut'] === 'Annulé');
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_AGENT3', 'priorite' => 'Basse'], $auth['agent']);
    $tid3 = (int) $pdo->query("SELECT id FROM t_taches WHERE titre = 'ZZ_TEST_AGENT3'")->fetchColumn();
    tache_transition($pdo, $tid3, $usr('tech'), 'prendre');
    $r = tache_transition($pdo, $tid3, $usr('agent'), 'statut', ['statut' => 'Annulé']);
    check('…mais plus une fois prise en charge : 403', !$r['ok'] && $r['code'] === 403);

    echo "Concurrence : une seule prise en charge gagnante\n";
    [$c] = call('POST', "$base/taches.php?action=create", $t + ['titre' => 'ZZ_TEST_COURSE', 'priorite' => 'Moyenne'], $auth['agent']);
    $tid4 = (int) $pdo->query("SELECT id FROM t_taches WHERE titre = 'ZZ_TEST_COURSE'")->fetchColumn();
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $qui = $i % 2 === 0 ? $ids['tech'] : $ids['tech2'];
        $procs[] = proc_open([PHP_BINARY, __DIR__ . '/prendre_worker.php', (string) $tid4, (string) $qui], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        $pipes[] = $pp;
    }
    $sorties = [];
    foreach ($procs as $k => $pr) { $sorties[] = trim(stream_get_contents($pipes[$k][1])); proc_close($pr); }
    $gagnants = count(array_filter($sorties, fn($x) => $x === 'OK'));
    // Un même agent peut « reprendre » sa propre panne (idempotent) ; deux agents distincts ne peuvent pas gagner.
    $qui = $pdo->query("SELECT assigne_id FROM t_taches WHERE id = $tid4")->fetchColumn();
    $evtPrise = (int) $pdo->query("SELECT COUNT(DISTINCT nouveau_assigne_id) FROM t_taches_evenements WHERE tache_id = $tid4 AND type = 'prise_en_charge'")->fetchColumn();
    check('un seul agent détient la panne à l\'issue de 6 prises simultanées', $evtPrise === 1 && in_array((int) $qui, [$ids['tech'], $ids['tech2']], true), '(sorties ' . implode(',', $sorties) . ')');
    check('les perdants reçoivent 409', count(array_filter($sorties, fn($x) => $x === '409')) >= 2, '(sorties ' . implode(',', $sorties) . ')');
} finally {
    nettoyer($pdo, $comptes);
}
echo "\n$ok réussi(s), $ko échec(s)\n";
exit($ko === 0 ? 0 : 1);
