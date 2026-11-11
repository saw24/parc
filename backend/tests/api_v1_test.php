<?php
// Tests de bout en bout de l'API mobile v1 (HTTP réel). Usage :
//   php backend/tests/api_v1_test.php [URL_V1]   (défaut : http://localhost/parc/backend/api/v1/index.php)
// Crée des données « zz_v1_ » / « ZZ_V1_ » dans la base configurée et les supprime à la fin.
// NE PAS lancer sur la production.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI uniquement.'); }
require_once __DIR__ . '/../config/db.php';

$base = rtrim($argv[1] ?? 'http://localhost/parc/backend/api/v1/index.php', '/');
$ok = 0; $ko = 0;
function check(string $nom, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ✓ $nom\n"; } else { $ko++; echo "  ✗ $nom $detail\n"; }
}
/** @return array{0:int,1:mixed,2:array<string,string>} */
function api(string $m, string $route, ?array $body = null, ?string $token = null, array $hdr = []): array {
    global $base;
    $ch = curl_init("$base/$route");
    $h = ['Content-Type: application/json'];
    if ($token) { $h[] = "Authorization: Bearer $token"; }
    foreach ($hdr as $k => $v) { $h[] = "$k: $v"; }
    $reponseH = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$reponseH) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $reponseH[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $raw, true), $reponseH, (string) $raw];
}
function erreur($r): string { return is_array($r[1]) ? ($r[1]['error']['code'] ?? '') : ''; }

$pwd = 'Zz-' . bin2hex(random_bytes(7));
$rolesTest = [
    'zz_v1_tech' => ['tache.voir_toutes', 'tache.creer', 'tache.modifier_siennes', 'tache.prendre', 'tache.affecter',
        'materiel.voir_tous', 'materiel.creer', 'materiel.modifier', 'referentiel.voir'],
    'zz_v1_agent' => ['tache.declarer', 'tache.voir_siennes', 'materiel.voir_siens'],
    'zz_v1_vide' => [],
    'zz_v1_gest' => ['tache.voir_toutes', 'tache.modifier_toutes', 'tache.supprimer', 'utilisateur.lister', 'utilisateur.gerer',
        'referentiel.voir', 'referentiel.gerer', 'referentiel.supprimer'],
];
$comptes = [ // clé => [login, rôle, nom]
    'admin' => ['zz_v1_admin', 'Administrateur'], 'tech' => ['zz_v1_tech', 'zz_v1_tech'], 'tech2' => ['zz_v1_tech2', 'zz_v1_tech'],
    'agent' => ['zz_v1_agent', 'zz_v1_agent'], 'agent2' => ['zz_v1_agent2', 'zz_v1_agent'], 'vide' => ['zz_v1_vide', 'zz_v1_vide'],
    'gest' => ['zz_v1_gest', 'zz_v1_gest'],
];

function nettoyer(PDO $pdo, array $comptes, array $rolesTest): void {
    $pdo->exec("DELETE FROM t_taches WHERE titre LIKE 'ZZ_V1_%' OR titre LIKE '[Préventif] ZZ_V1_%'");
    $pdo->exec("DELETE FROM t_plans_maintenance WHERE titre LIKE 'ZZ_V1_%'");
    $pdo->exec("DELETE FROM t_materiel WHERE num_serie LIKE 'ZZ_V1_%'");
    $pdo->exec("DELETE FROM t_login_attempts WHERE login LIKE 'zz_v1_%'");
    $pdo->exec("DELETE FROM t_utilisateurs WHERE login LIKE 'zz_v1_new%'");
    $pdo->exec("DELETE FROM t_type_materiel WHERE type LIKE 'ZZ_V1_%'");
    $pdo->exec("DELETE FROM t_services WHERE nom LIKE 'ZZ_V1_%'");
    $in = implode(',', array_fill(0, count($comptes), '?'));
    $pdo->prepare("DELETE FROM t_utilisateurs WHERE login IN ($in)")->execute(array_map(fn($c) => $c[0], array_values($comptes)));
    $pdo->exec("DELETE FROM t_roles WHERE nom LIKE 'zz_v1_nr%'");
    $pdo->prepare("DELETE FROM t_roles WHERE nom IN (" . implode(',', array_fill(0, count($rolesTest), '?')) . ")")->execute(array_keys($rolesTest));
}
nettoyer($pdo, $comptes, $rolesTest);

try {
    foreach ($rolesTest as $nom => $perms) {
        $id = $pdo->prepare("INSERT INTO t_roles (nom, description) VALUES (?, 'test') RETURNING id"); $id->execute([$nom]); $id = (int) $id->fetchColumn();
        foreach ($perms as $p) { $pdo->prepare("INSERT INTO t_roles_permissions VALUES (?, ?)")->execute([$id, $p]); }
    }
    $pdo->exec("INSERT INTO t_services (nom) VALUES ('ZZ_V1_SVC')");
    $svc = (int) $pdo->query("SELECT id FROM t_services WHERE nom = 'ZZ_V1_SVC'")->fetchColumn();
    $ids = [];
    foreach ($comptes as $k => [$login, $role]) {
        $st = $pdo->prepare("INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe, service_id) VALUES (?, ?, ?, ?, ?) RETURNING id");
        $st->execute(["ZZ $login", $login, $role, password_hash($pwd, PASSWORD_DEFAULT), $svc]);
        $ids[$k] = (int) $st->fetchColumn();
    }

    echo "Routage et réponses publiques\n";
    $r = api('GET', 'config');
    check('GET /config public', $r[0] === 200 && ($r[1]['data']['api_version'] ?? 0) === 1);
    check('server_time en ISO UTC (Z)', str_ends_with($r[1]['data']['server_time'] ?? '', 'Z'));
    check('route inconnue → 404', api('GET', 'nimporte/quoi')[0] === 404);
    $r = api('DELETE', 'config');
    check('méthode non autorisée → 405 + Allow', $r[0] === 405 && str_contains($r[2]['allow'] ?? '', 'GET'));
    $r = api('GET', 'taches');
    check('sans jeton → 401 token_manquant', $r[0] === 401 && erreur($r) === 'token_manquant');
    $r = api('GET', 'taches', null, str_repeat('a', 64));
    check('jeton inconnu → 401 token_invalide', $r[0] === 401 && erreur($r) === 'token_invalide');

    echo "Connexion par appareil\n";
    $r = api('POST', 'auth/login', ['login' => 'zz_v1_tech2', 'password' => 'faux']);
    check('mauvais mot de passe → 401', $r[0] === 401 && erreur($r) === 'identifiants_invalides');
    $r = api('POST', 'auth/login', ['login' => 'zz_v1_tech2']);
    check('mot de passe manquant → 422', $r[0] === 422);
    $r = api('POST', 'auth/login', ['login' => 'service_it', 'password' => '!compte-sans-connexion']);
    check('compte « Service IT » : connexion impossible', $r[0] === 401);
    $tok = [];
    foreach (['admin', 'tech', 'tech2', 'agent', 'agent2', 'vide', 'gest'] as $k) {
        $r = api('POST', 'auth/login', ['login' => $comptes[$k][0], 'password' => $pwd, 'appareil' => ['nom' => "Test $k", 'plateforme' => 'android', 'version_app' => '1.0.0']]);
        $tok[$k] = $r[1]['data'] ?? [];
        if ($k === 'tech') {
            check('login OK : jetons + utilisateur + permissions', $r[0] === 200 && strlen($tok[$k]['access_token'] ?? '') === 64
                && strlen($tok[$k]['refresh_token'] ?? '') === 64 && ($tok[$k]['expires_in'] ?? 0) === 3600
                && in_array('tache.prendre', $tok[$k]['utilisateur']['permissions'] ?? [], true) && ($tok[$k]['utilisateur']['agent_it'] ?? false) === true);
        }
    }
    $A = function (string $k) use (&$tok) { return $tok[$k]['access_token']; };
    $h = $pdo->prepare("SELECT access_hash FROM t_appareils WHERE user_id = ?"); $h->execute([$ids['tech']]);
    check('seul le haché du jeton est stocké', trim((string) $h->fetchColumn()) === hash('sha256', $A('tech')));
    $r = api('GET', 'me', null, $A('agent'));
    check('GET /me', $r[0] === 200 && $r[1]['data']['login'] === 'zz_v1_agent' && ($r[1]['data']['agent_it'] ?? true) === false
        && ($r[1]['data']['service']['nom'] ?? '') === 'ZZ_V1_SVC');
    $r = api('POST', 'auth/logout', null, null);
    check('logout sans jeton → 401', $r[0] === 401);
    $r = api('PATCH', 'me', ['telephone' => 'abc'], $A('agent'));
    check('téléphone invalide → 422', $r[0] === 422);
    $r = api('PATCH', 'me', ['telephone' => '+227 90 00 00 00', 'email' => 'agent@example.org'], $A('agent'));
    check('mise à jour du profil', $r[0] === 200 && $r[1]['data']['telephone'] === '+227 90 00 00 00');
    $r = api('GET', 'taches', null, $A('agent'), ['X-App-Version' => '0.0.1']);
    check('version d\'application trop ancienne → 426 app_obsolete', $r[0] === 426 && erreur($r) === 'app_obsolete');
    $r = api('GET', 'taches', null, $A('agent'), ['X-App-Version' => '1.2.0']);
    check('version récente acceptée', $r[0] === 200);

    echo "Rafraîchissement tournant\n";
    $ancienRefresh = $tok['tech2']['refresh_token']; $ancienAccess = $A('tech2');
    $r = api('POST', 'auth/refresh', ['refresh_token' => $ancienRefresh]);
    check('refresh → nouveaux jetons', $r[0] === 200 && $r[1]['data']['access_token'] !== $ancienAccess && $r[1]['data']['refresh_token'] !== $ancienRefresh);
    $nouveau = $r[1]['data'];   // R1
    check('ancien jeton d\'accès invalidé', api('GET', 'me', null, $ancienAccess)[0] === 401);
    check('nouveau jeton d\'accès valide', api('GET', 'me', null, $nouveau['access_token'])[0] === 200);
    $r = api('POST', 'auth/refresh', ['refresh_token' => $ancienRefresh]);
    check('ancien refresh rejoué dans le délai de grâce → réémission (réponse perdue)', $r[0] === 200);
    $reemis = $r[1]['data'];    // R2 ; l'immédiatement précédent est désormais R1
    $pdo->prepare("UPDATE t_appareils SET refresh_tourne_le = NOW() - interval '10 minutes' WHERE user_id = ?")->execute([$ids['tech2']]);
    $r = api('POST', 'auth/refresh', ['refresh_token' => $nouveau['refresh_token']]);
    check('refresh précédent rejoué hors grâce → 401 refresh_rejoue', $r[0] === 401 && erreur($r) === 'refresh_rejoue');
    check('…et la session entière est révoquée', api('GET', 'me', null, $reemis['access_token'])[0] === 401);
    check('…y compris le refresh légitime', api('POST', 'auth/refresh', ['refresh_token' => $reemis['refresh_token']])[0] === 401);
    $tok['tech2'] = api('POST', 'auth/login', ['login' => 'zz_v1_tech2', 'password' => $pwd])[1]['data'];   // nouvelle session
    $pdo->prepare("UPDATE t_appareils SET access_expire = NOW() - interval '1 minute' WHERE user_id = ?")->execute([$ids['agent2']]);
    $r = api('GET', 'me', null, $A('agent2'));
    check('jeton d\'accès expiré → 401 token_expire', $r[0] === 401 && erreur($r) === 'token_expire');
    $r = api('POST', 'auth/refresh', ['refresh_token' => $tok['agent2']['refresh_token']]);
    check('…et le refresh le renouvelle', $r[0] === 200);
    $tok['agent2'] = array_merge($tok['agent2'], $r[1]['data']);

    echo "Mot de passe et appareils\n";
    $r = api('POST', 'auth/login', ['login' => 'zz_v1_vide', 'password' => $pwd, 'appareil' => ['nom' => 'Second téléphone']]);
    $second = $r[1]['data'];
    check('second appareil pour le même compte', $r[0] === 200);
    $r = api('GET', 'appareils', null, $A('vide'));
    check('liste des appareils (courant signalé)', $r[0] === 200 && count($r[1]['data']) === 2 && count(array_filter($r[1]['data'], fn($a) => $a['courant'])) === 1);
    $r = api('PUT', 'appareils/courant', ['fcm_token' => 'tok-fcm-123', 'version_app' => '1.0.1'], $A('vide'));
    check('enregistrement du jeton FCM (optionnel)', $r[0] === 200 && $pdo->query("SELECT COUNT(*) FROM t_appareils WHERE fcm_token = 'tok-fcm-123'")->fetchColumn() == 1);
    check('mot de passe actuel incorrect → 403', api('POST', 'me/mot_de_passe', ['actuel' => 'x', 'nouveau' => 'NouveauMdp-2026!'], $A('vide'))[0] === 403);
    check('nouveau mot de passe faible → 422', api('POST', 'me/mot_de_passe', ['actuel' => $pwd, 'nouveau' => 'court'], $A('vide'))[0] === 422);
    $r = api('POST', 'me/mot_de_passe', ['actuel' => $pwd, 'nouveau' => 'NouveauMdp-2026!'], $A('vide'));
    check('changement de mot de passe', $r[0] === 200);
    check('…les autres appareils sont déconnectés', api('GET', 'me', null, $second['access_token'])[0] === 401);
    check('…l\'appareil courant reste connecté', api('GET', 'me', null, $A('vide'))[0] === 200);
    check('…connexion avec le nouveau mot de passe', api('POST', 'auth/login', ['login' => 'zz_v1_vide', 'password' => 'NouveauMdp-2026!'])[0] === 200);
    $r = api('POST', 'auth/logout', null, $A('vide'));
    check('logout', $r[0] === 200 && api('GET', 'me', null, $A('vide'))[0] === 401);

    echo "Déclaration d'une panne (agent)\n";
    $r = api('GET', 'referentiels', null, $A('agent'));
    check('référentiels accessibles à l\'agent', $r[0] === 200 && in_array('ZZ_V1_SVC', array_column($r[1]['data']['services'], 'nom'), true));
    $uuid = '3f2b8a40-1c2d-4e5f-9a6b-7c8d9e0f1a2b';
    $pdo->prepare("INSERT INTO t_materiel (type, marque, modele, num_serie, statut, utilisateur_assigne) VALUES ('PC', 'Dell', 'X1', 'ZZ_V1_SN1', 'En service', ?)")->execute(["ZZ zz_v1_agent"]);
    $pdo->prepare("INSERT INTO t_materiel (type, marque, modele, num_serie, statut, utilisateur_assigne) VALUES ('PC', 'HP', 'Z2', 'ZZ_V1_SN2', 'En service', ?)")->execute(["ZZ zz_v1_agent2"]);
    $mat1 = (int) $pdo->query("SELECT id FROM t_materiel WHERE num_serie = 'ZZ_V1_SN1'")->fetchColumn();
    $mat2 = (int) $pdo->query("SELECT id FROM t_materiel WHERE num_serie = 'ZZ_V1_SN2'")->fetchColumn();
    $r = api('POST', 'taches', ['titre' => 'ZZ_V1_Écran noir', 'description' => 'Plus d\'image', 'priorite' => 'Haute', 'materiel_id' => $mat1, 'client_uuid' => $uuid], $A('agent'));
    $t1 = $r[1]['data'] ?? [];
    check('agent déclare → 201', $r[0] === 201, "(reçu {$r[0]} " . substr($r[3], 0, 200) . ')');
    check('affectée à la file Service IT, statut À faire', ($t1['en_file'] ?? false) === true && $t1['statut'] === 'À faire' && ($t1['assigne']['nom'] ?? '') === 'Service IT');
    check('service par défaut = service du compte, matériel lié, échéances SLA', $t1['service'] === 'ZZ_V1_SVC' && ($t1['materiel']['id'] ?? 0) === $mat1
        && $t1['dates']['echeance_prise_en_charge'] !== null && str_starts_with($t1['reference'], 'T-'));
    check('actions possibles de l\'agent : annuler/commenter seulement', $t1['actions']['annuler'] === true && $t1['actions']['prendre'] === false && $t1['actions']['changer_statut'] === false);
    $r = api('POST', 'taches', ['titre' => 'ZZ_V1_Écran noir', 'client_uuid' => $uuid], $A('agent'));
    check('rejeu du même client_uuid → 200, même panne, pas de doublon', $r[0] === 200 && $r[1]['data']['id'] === $t1['id'] && ($r[1]['meta']['deja_existante'] ?? false) === true
        && $pdo->query("SELECT COUNT(*) FROM t_taches WHERE client_uuid = '$uuid'")->fetchColumn() == 1);
    $r = api('POST', 'taches', ['titre' => 'ZZ_V1_autre', 'client_uuid' => $uuid], $A('agent2'));
    check('client_uuid d\'un autre déclarant → 409', $r[0] === 409, substr($r[3], 0, 200));
    check('titre trop court → 422', api('POST', 'taches', ['titre' => 'ab'], $A('agent'))[0] === 422);
    check('priorité invalide → 422', api('POST', 'taches', ['titre' => 'ZZ_V1_x', 'priorite' => 'Urgente'], $A('agent'))[0] === 422);
    check('assigne_id interdit pour un agent → 403', api('POST', 'taches', ['titre' => 'ZZ_V1_x', 'assigne_id' => $ids['tech']], $A('agent'))[0] === 403);
    check('service inconnu → 422', api('POST', 'taches', ['titre' => 'ZZ_V1_x', 'service_id' => 999999], $A('agent'))[0] === 422);
    check('matériel d\'un autre agent → 422', api('POST', 'taches', ['titre' => 'ZZ_V1_x', 'materiel_id' => $mat2], $A('agent'))[0] === 422);
    check('JSON invalide → 400', (function () use ($base, $A) { $ch = curl_init("$base/taches"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{pas du json', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $A('agent'), 'Content-Type: application/json']]); curl_exec($ch); return curl_getinfo($ch, CURLINFO_HTTP_CODE) === 400; })());
    check('compte sans droit : déclaration → 403', api('POST', 'taches', ['titre' => 'ZZ_V1_x'], api('POST', 'auth/login', ['login' => 'zz_v1_vide', 'password' => 'NouveauMdp-2026!'])[1]['data']['access_token'])[0] === 403);
    api('POST', 'taches', ['titre' => 'ZZ_V1_Deuxième panne', 'priorite' => 'Basse'], $A('agent'));
    api('POST', 'taches', ['titre' => 'ZZ_V1_Troisième panne agent2'], $A('agent2'));

    echo "Consultation (visibilité, filtres, pagination)\n";
    $r = api('GET', 'taches', null, $A('agent'));
    check('l\'agent ne voit que ses 2 pannes', $r[0] === 200 && count($r[1]['data']) === 2, '(reçu ' . count($r[1]['data'] ?? []) . ')');
    $p1 = api('GET', 'taches?limit=1', null, $A('agent'));
    $cur = $p1[1]['meta']['next_cursor'] ?? null;
    $p2 = $cur ? api('GET', 'taches?limit=1&cursor=' . $cur, null, $A('agent')) : [0, []];
    check('pagination par curseur : 2 pages distinctes, fin sans curseur', $cur !== null && count($p2[1]['data'] ?? []) === 1
        && $p2[1]['data'][0]['id'] !== $p1[1]['data'][0]['id'] && array_key_exists('next_cursor', $p2[1]['meta']) && $p2[1]['meta']['next_cursor'] === null);
    $r = api('GET', 'taches?updated_since=' . urlencode(gmdate('Y-m-d\TH:i:s\Z', time() + 3600)), null, $A('agent'));
    check('updated_since dans le futur → liste vide', $r[0] === 200 && $r[1]['data'] === []);
    $r = api('GET', 'taches?updated_since=' . urlencode(gmdate('Y-m-d\TH:i:s\Z', time() - 3600)), null, $A('agent'));
    check('updated_since dans le passé → les pannes récentes', count($r[1]['data']) === 2);
    check('updated_since invalide → 422', api('GET', 'taches?updated_since=hier', null, $A('agent'))[0] === 422);
    $r = api('GET', 'taches?ids_only=1', null, $A('agent'));
    check('ids_only', $r[0] === 200 && count($r[1]['data']) === 2 && is_int($r[1]['data'][0]));
    $r = api('GET', 'taches?q=' . urlencode('écran'), null, $A('agent'));
    check('recherche texte', count($r[1]['data']) === 1);
    check('détail d\'une panne d\'autrui → 404', api('GET', 'taches/' . (int) $pdo->query("SELECT id FROM t_taches WHERE titre = 'ZZ_V1_Troisième panne agent2'")->fetchColumn(), null, $A('agent'))[0] === 404);
    $r = api('GET', 'taches?assigne=file', null, $A('tech'));
    check('l\'agent IT voit la file commune', $r[0] === 200 && count(array_filter($r[1]['data'], fn($t) => str_starts_with($t['titre'], 'ZZ_V1_'))) === 3);

    echo "Prise en charge et traitement (agent IT)\n";
    $id = $t1['id'];
    check('agent : prendre → 403', api('POST', "taches/$id/prendre", [], $A('agent'))[0] === 403);
    check('version périmée → 409', api('POST', "taches/$id/prendre", ['version' => 999], $A('tech'))[0] === 409);
    $r = api('POST', "taches/$id/prendre", ['version' => $t1['version']], $A('tech'));
    check('prendre → En cours, assignée à l\'agent IT, évènement', $r[0] === 200 && $r[1]['data']['statut'] === 'En cours' && $r[1]['data']['assigne']['nom'] === 'ZZ zz_v1_tech'
        && $r[1]['data']['en_file'] === false && ($r[1]['meta']['evenement'] ?? '') === 'prise_en_charge' && $r[1]['data']['dates']['prise_en_charge'] !== null);
    $r = api('POST', "taches/$id/prendre", [], $A('admin'));
    check('prendre une panne déjà prise → 409 (nom de l\'agent dans le message)', $r[0] === 409 && str_contains($r[1]['error']['message'], 'zz_v1_tech'));
    $r = api('POST', "taches/$id/commentaires", ['commentaire' => 'Note interne', 'visible_declarant' => false], $A('tech'));
    check('commentaire interne par l\'agent IT', $r[0] === 201 || $r[0] === 200);
    $r = api('POST', "taches/$id/commentaires", ['commentaire' => 'Je passe demain matin'], $A('tech'));
    $r = api('POST', "taches/$id/commentaires", ['commentaire' => 'Merci, ok', 'visible_declarant' => false], $A('agent'));
    $d = api('GET', "taches/$id", null, $A('agent'))[1]['data'];
    $texte = implode('|', array_column($d['evenements'], 'commentaire'));
    check('le déclarant ne voit pas la note interne, voit le reste', !str_contains($texte, 'Note interne') && str_contains($texte, 'Je passe demain') && str_contains($texte, 'Merci, ok'));
    $dt = api('GET', "taches/$id", null, $A('tech'))[1]['data'];
    check('l\'agent IT voit tout l\'historique', str_contains(implode('|', array_column($dt['evenements'], 'commentaire')), 'Note interne')
        && in_array('creation', array_column($dt['evenements'], 'type'), true) && in_array('prise_en_charge', array_column($dt['evenements'], 'type'), true));
    check('« En attente » sans motif → 422', api('POST', "taches/$id/statut", ['statut' => 'En attente'], $A('tech'))[0] === 422);
    $r = api('POST', "taches/$id/statut", ['statut' => 'En attente', 'motif_attente' => 'Pièce commandée', 'date_relance' => date('Y-m-d', strtotime('+3 days'))], $A('tech'));
    check('« En attente » avec motif et relance', $r[0] === 200 && $r[1]['data']['statut'] === 'En attente' && $r[1]['data']['motif_attente'] === 'Pièce commandée');
    check('statut invalide → 422', api('POST', "taches/$id/statut", ['statut' => 'Fini'], $A('tech'))[0] === 422);
    check('autre agent IT sans droit de modification → 403', api('POST', "taches/$id/statut", ['statut' => 'En cours'], $A('tech2')) [0] === 403);
    $r = api('POST', "taches/$id/affecter", ['assigne_id' => $ids['agent']], $A('tech'));
    check('affecter à un compte sans droit « prendre » → 422', $r[0] === 422);
    $r = api('POST', "taches/$id/affecter", ['assigne_id' => $ids['tech2'], 'commentaire' => 'Je te passe le dossier'], $A('tech'));
    check('affecter à un collègue', $r[0] === 200 && $r[1]['data']['assigne']['nom'] === 'ZZ zz_v1_tech2');
    $r = api('POST', "taches/$id/statut", ['statut' => 'Terminé'], $A('tech2'));
    check('le nouvel assigné termine', $r[0] === 200 && $r[1]['data']['dates']['resolution'] !== null && $r[1]['data']['actions']['rouvrir'] === true);
    $r = api('GET', 'agents', null, $A('tech'));
    check('liste des agents : file commune en tête + charge', $r[0] === 200 && $r[1]['data'][0]['file_commune'] === true && count($r[1]['data']) >= 3);
    check('agents : interdit à l\'agent', api('GET', 'agents', null, $A('agent'))[0] === 403);
    $aujourdhui = date('Y-m-d'); $demain = date('Y-m-d', strtotime('+1 day')); $hier = date('Y-m-d', strtotime('-1 day'));
    $r = api('GET', 'taches?depuis=' . $aujourdhui, null, $A('tech'));
    check('période : depuis aujourd\'hui contient les pannes du jour', $r[0] === 200 && count($r[1]['data']) >= 1);
    $r = api('GET', 'taches?depuis=' . $demain, null, $A('tech'));
    check('période : depuis demain → vide', $r[0] === 200 && count($r[1]['data']) === 0);
    $r = api('GET', 'taches?jusqu_a=' . $hier, null, $A('tech'));
    check('période : jusqu\'à hier exclut les pannes du jour', $r[0] === 200 && !array_filter($r[1]['data'], fn($t) => str_starts_with((string) ($t['dates']['creation'] ?? ''), $aujourdhui)));
    check('période : date invalide → 422', api('GET', 'taches?depuis=hier', null, $A('tech'))[0] === 422);
    $r = api('GET', 'accueil', null, $A('agent'));
    check('accueil agent : mes pannes ouvertes', $r[0] === 200 && $r[1]['data']['mes_pannes_ouvertes'] === 1 && $r[1]['data']['en_file'] === 0, json_encode($r[1]['data'] ?? null));
    $r = api('GET', 'accueil', null, $A('tech'));
    check('accueil agent IT : file visible', $r[0] === 200 && $r[1]['data']['en_file'] >= 2);

    echo "Annulation par le déclarant\n";
    $idDeux = (int) $pdo->query("SELECT id FROM t_taches WHERE titre = 'ZZ_V1_Deuxième panne'")->fetchColumn();
    $r = api('POST', "taches/$idDeux/statut", ['statut' => 'Annulé'], $A('agent'));
    check('le déclarant annule sa panne non prise en charge', $r[0] === 200 && $r[1]['data']['statut'] === 'Annulé');

    echo "Parc matériel\n";
    $r = api('GET', 'materiel', null, $A('agent'));
    check('l\'agent voit uniquement son matériel', $r[0] === 200 && count($r[1]['data']) === 1 && $r[1]['data'][0]['id'] === $mat1);
    check('…avec le nombre de pannes ouvertes', $r[1]['data'][0]['pannes_ouvertes'] === 0);
    check('matériel d\'un autre → 404', api('GET', "materiel/$mat2", null, $A('agent'))[0] === 404);
    $qr = "PC Dell X1 | SN:ZZ_V1_SN1 | ID:$mat1";
    $r = api('GET', 'materiel/scan?code=' . urlencode($qr), null, $A('agent'));
    check('scan QR de son matériel', $r[0] === 200 && $r[1]['data']['id'] === $mat1);
    check('scan d\'un numéro de série brut', api('GET', 'materiel/scan?code=ZZ_V1_SN1', null, $A('agent'))[0] === 200);
    check('scan du matériel d\'autrui → 404', api('GET', 'materiel/scan?code=' . urlencode("X | SN:ZZ_V1_SN2 | ID:$mat2"), null, $A('agent'))[0] === 404);
    check('agent : création de matériel → 403', api('POST', 'materiel', ['type' => 'PC', 'statut' => 'En service'], $A('agent'))[0] === 403);
    $r = api('GET', 'materiel?q=ZZ_V1_SN', null, $A('tech'));
    check('agent IT : voit tout le parc (recherche)', $r[0] === 200 && count($r[1]['data']) === 2);
    $r = api('POST', 'materiel', ['type' => 'Imprimante', 'marque' => 'Canon', 'modele' => 'LBP', 'num_serie' => 'ZZ_V1_SN3', 'statut' => 'En service', 'service_id' => $svc, 'utilisateur_id' => $ids['agent2']], $A('tech'));
    $m3 = $r[1]['data'] ?? [];
    check('création de matériel → 201 (service, utilisateur, libellé)', $r[0] === 201 && $m3['service']['nom'] === 'ZZ_V1_SVC' && $m3['utilisateur']['id'] === $ids['agent2'] && str_contains($m3['libelle'], 'ZZ_V1_SN3'));
    check('…avec historique d\'affectation initial', $pdo->query("SELECT COUNT(*) FROM t_materiel_affectations WHERE materiel_id = {$m3['id']} AND date_fin IS NULL")->fetchColumn() == 1);
    check('numéro de série dupliqué → 409', api('POST', 'materiel', ['type' => 'PC', 'num_serie' => 'ZZ_V1_SN3', 'statut' => 'En service'], $A('tech'))[0] === 409);
    check('statut matériel invalide → 422', api('POST', 'materiel', ['type' => 'PC', 'statut' => 'Cassé'], $A('tech'))[0] === 422);
    $r = api('PATCH', "materiel/{$m3['id']}", ['statut' => 'En panne', 'utilisateur_id' => $ids['agent']], $A('tech'));
    check('modification partielle (statut + réaffectation)', $r[0] === 200 && $r[1]['data']['statut'] === 'En panne' && $r[1]['data']['utilisateur']['id'] === $ids['agent'] && $r[1]['data']['marque'] === 'Canon');
    $r = api('GET', "materiel/{$m3['id']}/affectations", null, $A('tech'));
    check('historique : ancienne affectation clôturée + nouvelle ouverte', $r[0] === 200 && count($r[1]['data']) === 2 && $r[1]['data'][0]['fin'] === null && $r[1]['data'][1]['fin'] !== null);
    check('agent IT : suppression → 403 (pas de permission)', api('DELETE', "materiel/{$m3['id']}", null, $A('tech'))[0] === 403);
    $r = api('POST', 'taches', ['titre' => 'ZZ_V1_Panne imprimante', 'materiel_id' => $m3['id'], 'service_id' => $svc, 'assigne_id' => $ids['tech2'], 'priorite' => 'Moyenne'], $A('tech'));
    check('agent IT crée une panne avec assignation directe', $r[0] === 201 && $r[1]['data']['assigne']['nom'] === 'ZZ zz_v1_tech2' && $r[1]['data']['dates']['prise_en_charge'] !== null);
    $r = api('GET', "materiel/{$m3['id']}/pannes", null, $A('tech'));
    check('pannes d\'un matériel', $r[0] === 200 && count($r[1]['data']) === 1);
    $r = api('DELETE', "materiel/{$m3['id']}", null, $A('admin'));
    check('administrateur : suppression', $r[0] === 200 && api('GET', "materiel/{$m3['id']}", null, $A('admin'))[0] === 404);

    echo "Limitation des tentatives\n";
    $dernier = 0;
    for ($i = 0; $i < 6; $i++) { $dernier = api('POST', 'auth/login', ['login' => 'zz_v1_agent2', 'password' => 'mauvais']); }
    check('6e échec → 429 + Retry-After', $dernier[0] === 429 && isset($dernier[2]['retry-after']) && erreur($dernier) === 'trop_de_tentatives');
    check('même le bon mot de passe est refusé pendant le blocage', api('POST', 'auth/login', ['login' => 'zz_v1_agent2', 'password' => $pwd])[0] === 429);

    echo "Liste des utilisateurs (affectation du matériel)\n";
    $r = api('GET', 'utilisateurs', null, $A('tech'));
    check('technicien : liste des agents actifs', $r[0] === 200 && in_array('ZZ zz_v1_agent', array_column($r[1]['data'] ?? [], 'nom'), true)
        && !in_array('Service IT', array_column($r[1]['data'] ?? [], 'nom'), true));
    check('agent simple : refusé', api('GET', 'utilisateurs', null, $A('agent'))[0] === 403);

    echo "Modification et suppression d'une panne\n";
    $r = api('POST', 'taches', ['titre' => 'ZZ_V1_Edit', 'service_id' => $svc, 'priorite' => 'Basse'], $A('agent'));
    $pid = $r[1]['data']['id'] ?? 0;
    check('déclaration pour test', $r[0] === 201 && $pid > 0);
    check('actions : le déclarant peut modifier', in_array('modifier', array_keys(array_filter($r[1]['data']['actions'] ?? [])), true) || ($r[1]['data']['actions']['modifier'] ?? false) === true);
    $r = api('PATCH', "taches/$pid", ['titre' => 'ZZ_V1_Edit 2', 'priorite' => 'Haute'], $A('agent'));
    check('déclarant : modifie titre et priorité', $r[0] === 200 && $r[1]['data']['titre'] === 'ZZ_V1_Edit 2' && $r[1]['data']['priorite'] === 'Haute');
    check('déclarant : date_limite interdite (403)', api('PATCH', "taches/$pid", ['date_limite' => '2030-01-01'], $A('agent'))[0] === 403);
    check('aucun champ → 422', api('PATCH', "taches/$pid", [], $A('agent'))[0] === 422);
    check('priorité invalide → 422', api('PATCH', "taches/$pid", ['priorite' => 'Folle'], $A('agent'))[0] === 422);
    check('autre agent : panne invisible (404)', api('PATCH', "taches/$pid", ['titre' => 'X'], $A('agent2'))[0] === 404);
    check('version périmée → 409', api('PATCH', "taches/$pid", ['titre' => 'ZZ_V1_Edit 3', 'version' => 1], $A('agent'))[0] === 409);
    $r = api('PATCH', "taches/$pid", ['date_limite' => '2030-01-01', 'description' => 'détail'], $A('gest'));
    check('gestionnaire : modifie date limite et description', $r[0] === 200 && $r[1]['data']['description'] === 'détail');
    check('agent sans permission supprimer : 403', api('DELETE', "taches/$pid", null, $A('agent'))[0] === 403);
    $r = api('DELETE', "taches/$pid", null, $A('gest'));
    check('gestionnaire : supprime la panne', $r[0] === 200 && api('GET', "taches/$pid", null, $A('gest'))[0] === 404);

    echo "Gestion des services\n";
    check('agent : liste des services refusée', api('GET', 'services', null, $A('agent'))[0] === 403);
    $r = api('POST', 'services', ['nom' => 'ZZ_V1_NEW', 'description' => 'essai'], $A('gest'));
    $nsid = $r[1]['data']['id'] ?? 0;
    check('création d\'un service', $r[0] === 201 && $nsid > 0);
    check('doublon → 409', api('POST', 'services', ['nom' => 'ZZ_V1_NEW'], $A('gest'))[0] === 409);
    check('nom vide → 422', api('POST', 'services', ['nom' => ''], $A('gest'))[0] === 422);
    check('technicien sans referentiel.gerer : 403', api('POST', 'services', ['nom' => 'ZZ_V1_X'], $A('tech'))[0] === 403);
    $r = api('POST', 'utilisateurs', ['nom' => 'ZZ New', 'login' => 'zz_v1_new1', 'mot_de_passe' => 'MotDePasse-1234', 'role' => 'zz_v1_agent', 'service_id' => $nsid], $A('gest'));
    $nuid = $r[1]['data']['id'] ?? 0;
    check('création d\'un utilisateur rattaché au service' . ($r[0] === 201 ? '' : ' ' . $r[3]), $r[0] === 201 && ($r[1]['data']['service']['id'] ?? 0) === $nsid);
    $pdo->prepare("INSERT INTO t_materiel (type, statut, service_affecte, num_serie) VALUES ('PC', 'En service', 'ZZ_V1_NEW', 'ZZ_V1_REN')")->execute();
    $r = api('PATCH', "services/$nsid", ['nom' => 'ZZ_V1_RENAMED'], $A('gest'));
    $serv = $pdo->query("SELECT service_affecte FROM t_materiel WHERE num_serie = 'ZZ_V1_REN'")->fetchColumn();
    check('renommage répercuté sur le matériel', $r[0] === 200 && $serv === 'ZZ_V1_RENAMED' && $r[1]['data']['nb_materiels'] === 1 && $r[1]['data']['nb_utilisateurs'] === 1);
    check('service utilisé : suppression refusée (409)', api('DELETE', "services/$nsid", null, $A('gest'))[0] === 409);

    echo "Gestion des utilisateurs\n";
    check('agent : refus de la liste détaillée', api('GET', 'utilisateurs?detail=1', null, $A('agent'))[0] === 403);
    $r = api('GET', 'utilisateurs?detail=1&q=zz_v1_new', null, $A('gest'));
    check('liste détaillée filtrée', $r[0] === 200 && count($r[1]['data']) === 1 && isset($r[1]['data'][0]['role']) && !isset($r[1]['data'][0]['mot_de_passe']));
    check('login en double → 409', api('POST', 'utilisateurs', ['nom' => 'ZZ Dup', 'login' => 'zz_v1_new1', 'mot_de_passe' => 'MotDePasse-1234', 'role' => 'zz_v1_agent'], $A('gest'))[0] === 409);
    check('mot de passe trop court → 422', api('POST', 'utilisateurs', ['nom' => 'ZZ Court', 'login' => 'zz_v1_new2', 'mot_de_passe' => 'court', 'role' => 'zz_v1_agent'], $A('gest'))[0] === 422);
    check('rôle inconnu → 422', api('POST', 'utilisateurs', ['nom' => 'ZZ R', 'login' => 'zz_v1_new3', 'mot_de_passe' => 'MotDePasse-1234', 'role' => 'inexistant'], $A('gest'))[0] === 422);
    check('attribuer Administrateur sans l\'être → 403', api('POST', 'utilisateurs', ['nom' => 'ZZ A', 'login' => 'zz_v1_new4', 'mot_de_passe' => 'MotDePasse-1234', 'role' => 'Administrateur'], $A('gest'))[0] === 403);
    check('technicien sans utilisateur.gerer : 403', api('POST', 'utilisateurs', ['nom' => 'ZZ T', 'login' => 'zz_v1_new5', 'mot_de_passe' => 'MotDePasse-1234', 'role' => 'zz_v1_agent'], $A('tech'))[0] === 403);
    $r = api('GET', 'roles', null, $A('gest'));
    check('liste des rôles', $r[0] === 200 && in_array('Administrateur', array_column($r[1]['data'], 'nom'), true));
    $rl = api('POST', 'auth/login', ['login' => 'zz_v1_new1', 'password' => 'MotDePasse-1234', 'appareil' => ['nom' => 'N', 'plateforme' => 'android']]);
    check('le nouvel utilisateur se connecte', $rl[0] === 200);
    $r = api('PATCH', "utilisateurs/$nuid", ['nom' => 'ZZ New Renamed', 'telephone' => '+227 11 22 33 44', 'role' => 'zz_v1_tech'], $A('gest'));
    check('modification (nom, téléphone, rôle)', $r[0] === 200 && $r[1]['data']['nom'] === 'ZZ New Renamed' && $r[1]['data']['role'] === 'zz_v1_tech');
    check('changement de rôle : sessions mobiles coupées', api('GET', 'me', null, $rl[1]['data']['access_token'] ?? 'x')[0] === 401);
    $r = api('PATCH', "utilisateurs/$nuid", ['actif' => false], $A('gest'));
    check('désactivation', $r[0] === 200 && $r[1]['data']['actif'] === false);
    check('compte désactivé : connexion refusée', api('POST', 'auth/login', ['login' => 'zz_v1_new1', 'password' => 'MotDePasse-1234'])[0] === 401);
    $r = api('PATCH', "utilisateurs/$nuid", ['actif' => true, 'mot_de_passe' => 'Autre-Passe-5678'], $A('gest'));
    check('réactivation + nouveau mot de passe', $r[0] === 200 && api('POST', 'auth/login', ['login' => 'zz_v1_new1', 'password' => 'Autre-Passe-5678'])[0] === 200);
    check('auto-désactivation interdite', api('PATCH', "utilisateurs/{$ids['gest']}", ['actif' => false], $A('gest'))[0] === 422);
    check('auto-suppression interdite', api('DELETE', "utilisateurs/{$ids['gest']}", null, $A('gest'))[0] === 422);
    $fc = (int) $pdo->query("SELECT id FROM t_utilisateurs WHERE est_file_commune LIMIT 1")->fetchColumn();
    check('compte « Service IT » protégé', api('PATCH', "utilisateurs/$fc", ['nom' => 'Autre'], $A('gest'))[0] === 403 && api('DELETE', "utilisateurs/$fc", null, $A('gest'))[0] === 403);
    check('admin : peut attribuer le rôle Administrateur', api('PATCH', "utilisateurs/$nuid", ['role' => 'Administrateur'], $A('admin'))[0] === 200);
    api('PATCH', "utilisateurs/$nuid", ['role' => 'zz_v1_agent'], $A('admin'));
    check('suppression d\'un utilisateur', api('DELETE', "utilisateurs/$nuid", null, $A('gest'))[0] === 200 && api('GET', "utilisateurs/$nuid", null, $A('gest'))[0] === 404);
    check('suppression du service vide', api('PATCH', "services/$nsid", ['nom' => 'ZZ_V1_NEW'], $A('gest'))[0] === 200
        && ($pdo->exec("DELETE FROM t_materiel WHERE num_serie = 'ZZ_V1_REN'") || true) && api('DELETE', "services/$nsid", null, $A('gest'))[0] === 200);


    echo "Rôles, permissions et paramètres\n";
    check('gestionnaire sans role.gerer : pas de permissions dans la liste des rôles', ($r = api('GET', 'roles', null, $A('gest')))[0] === 200 && !isset($r[1]['data'][0]['permissions']));
    check('liste des rôles avec permissions (admin)', ($r = api('GET', 'roles', null, $A('admin')))[0] === 200 && isset($r[1]['data'][0]['permissions']));
    check('agent : catalogue refusé', api('GET', 'permissions', null, $A('agent'))[0] === 403);
    $r = api('GET', 'permissions', null, $A('admin'));
    check('catalogue groupé', $r[0] === 200 && in_array('Pannes', array_column($r[1]['data'], 'groupe'), true));
    $r = api('POST', 'roles', ['nom' => 'zz_v1_nr1', 'description' => 'essai', 'permissions' => ['tache.declarer', 'tache.voir_siennes']], $A('admin'));
    $rid = $r[1]['data']['id'] ?? 0;
    check('création d\'un rôle', $r[0] === 201 && $r[1]['data']['permissions'] === ['tache.declarer', 'tache.voir_siennes']);
    check('rôle en double → 409', api('POST', 'roles', ['nom' => 'zz_v1_nr1', 'permissions' => []], $A('admin'))[0] === 409);
    check('permission inconnue → 422', api('POST', 'roles', ['nom' => 'zz_v1_nr2', 'permissions' => ['inexistante']], $A('admin'))[0] === 422);
    check('gestionnaire sans role.gerer : 403', api('POST', 'roles', ['nom' => 'zz_v1_nr3', 'permissions' => []], $A('gest'))[0] === 403);
    $r = api('PATCH', "roles/$rid", ['permissions' => ['tache.declarer', 'materiel.voir_siens']], $A('admin'));
    check('modification des permissions', $r[0] === 200 && $r[1]['data']['permissions'] === ['tache.declarer', 'materiel.voir_siens']);
    $adminRole = (int) $pdo->query("SELECT id FROM t_roles WHERE systeme LIMIT 1")->fetchColumn();
    check('rôle système non modifiable ni supprimable', api('PATCH', "roles/$adminRole", ['description' => 'x'], $A('admin'))[0] === 403 && api('DELETE', "roles/$adminRole", null, $A('admin'))[0] === 403);
    $u = api('POST', 'utilisateurs', ['nom' => 'ZZ NR', 'login' => 'zz_v1_new7', 'mot_de_passe' => 'MotDePasse-1234', 'role' => 'zz_v1_nr1'], $A('admin'));
    check('rôle utilisé : suppression refusée (409)', api('DELETE', "roles/$rid", null, $A('admin'))[0] === 409);
    $lg = api('POST', 'auth/login', ['login' => 'zz_v1_new7', 'password' => 'MotDePasse-1234']);
    api('PATCH', "roles/$rid", ['nom' => 'zz_v1_nr1b'], $A('admin'));
    check('renommage : le compte suit, sessions coupées', api('GET', 'me', null, $lg[1]['data']['access_token'])[0] === 401
        && api('GET', "utilisateurs/{$u[1]['data']['id']}", null, $A('admin'))[1]['data']['role'] === 'zz_v1_nr1b');
    api('DELETE', "utilisateurs/{$u[1]['data']['id']}", null, $A('admin'));
    check('suppression du rôle', api('DELETE', "roles/$rid", null, $A('admin'))[0] === 200);

    check('paramètres : gestionnaire refusé', api('GET', 'parametres', null, $A('gest'))[0] === 403);
    $r = api('PATCH', 'parametres', ['sms_actif' => true, 'sms_url' => 'http://sms.example/api', 'sms_jeton' => 'secret-123'], $A('admin'));
    check('paramètres : SMS enregistrés, jeton jamais renvoyé', $r[0] === 200 && $r[1]['data']['sms_actif'] === '1' && $r[1]['data']['sms_jeton_configure'] === true
        && !isset($r[1]['data']['sms_jeton']) && !str_contains($r[3], 'secret-123'));
    check('jeton vide = conservé', api('PATCH', 'parametres', ['sms_jeton' => '', 'sms_expediteur' => 'ParcIT'], $A('admin'))[1]['data']['sms_jeton_configure'] === true);
    check('paramètre inconnu → 422', api('PATCH', 'parametres', ['nimporte' => 'x'], $A('admin'))[0] === 422);
    check('diagnostic : refusé au gestionnaire', api('GET', 'diagnostic/notifications', null, $A('gest'))[0] === 403);
    $r = api('GET', 'diagnostic/notifications', null, $A('admin'));
    check('diagnostic : état de la file, envois et appareils', $r[0] === 200 && isset($r[1]['data']['configuration'], $r[1]['data']['file'], $r[1]['data']['appareils']) && !str_contains(json_encode($r[1]['data']), 'tok-fcm'));
    $r = api('POST', 'diagnostic/push-test', [], $A('admin'));
    check('test push : échec lisible si push désactivé', $r[0] === 200 && $r[1]['data']['ok'] === false && $r[1]['data']['etapes'][0]['nom'] === 'Configuration');
    check('test push : refusé au gestionnaire', api('POST', 'diagnostic/push-test', [], $A('gest'))[0] === 403);
    check('traiter la file : réservé à l\'admin', api('POST', 'diagnostic/notifications/traiter', [], $A('gest'))[0] === 403 && api('POST', 'diagnostic/notifications/traiter', [], $A('admin'))[0] === 200);
    check('compte de service FCM invalide → 422', api('PATCH', 'parametres', ['fcm_compte_service' => '{"a":1}'], $A('admin'))[0] === 422);
    $r = api('PATCH', 'parametres', ['fcm_compte_service' => json_encode(['private_key' => 'k', 'client_email' => 'x@y.iam', 'project_id' => 'zz-p'])], $A('admin'));
    check('compte de service FCM valide : enregistré, jamais renvoyé', $r[0] === 200 && $r[1]['data']['fcm_compte_service_configure'] === true && !isset($r[1]['data']['fcm_compte_service']));
    api('PATCH', 'parametres', ['fcm_compte_service' => null], $A('admin'));
    $r = api('PATCH', 'parametres', ['sms_actif' => false, 'sms_url' => '', 'sms_jeton' => null], $A('admin'));
    check('paramètres remis à zéro', $r[0] === 200 && $r[1]['data']['sms_jeton_configure'] === false);

    echo "Types de matériel, liste enrichie, actions menées\n";
    check('types : lecture avec referentiel.voir', api('GET', 'types_materiel', null, $A('gest'))[0] === 200);
    check('types : agent refusé', api('GET', 'types_materiel', null, $A('agent'))[0] === 403);
    $r = api('POST', 'types_materiel', ['nom' => 'ZZ_V1_TYPE'], $A('gest'));
    $tid = $r[1]['data']['id'] ?? 0;
    check('type créé', $r[0] === 201 && $tid > 0);
    check('type en double → 409', api('POST', 'types_materiel', ['nom' => 'ZZ_V1_TYPE'], $A('gest'))[0] === 409);
    $pdo->prepare("INSERT INTO t_materiel (type, statut, num_serie) VALUES ('ZZ_V1_TYPE', 'En service', 'ZZ_V1_TY')")->execute();
    check('type renommé : le matériel suit', api('PATCH', "types_materiel/$tid", ['nom' => 'ZZ_V1_TYPE2'], $A('gest'))[0] === 200
        && $pdo->query("SELECT type FROM t_materiel WHERE num_serie = 'ZZ_V1_TY'")->fetchColumn() === 'ZZ_V1_TYPE2');
    check('type utilisé : suppression refusée', api('DELETE', "types_materiel/$tid", null, $A('gest'))[0] === 409);
    $pdo->exec("DELETE FROM t_materiel WHERE num_serie = 'ZZ_V1_TY'");
    check('type supprimé', api('DELETE', "types_materiel/$tid", null, $A('gest'))[0] === 200);
    $r = api('GET', 'utilisateurs?detail=1&inclure_inactifs=1&inclure_file=1&efficacite=1', null, $A('gest'));
    check('liste : file « Service IT » incluse, efficacité présente', $r[0] === 200 && in_array(true, array_column($r[1]['data'], 'compte_technique'), true) && array_key_exists('efficacite', $r[1]['data'][0]));
    $r = api('GET', 'utilisateurs?detail=1&efficacite=1', null, $A('tech'));
    check('technicien sans lister/gerer : refus', $r[0] === 403);
    $r = api('POST', 'taches', ['titre' => 'ZZ_V1_Actions', 'service_id' => $svc], $A('agent'));
    $aid = $r[1]['data']['id'];
    check('actions menées : réservées à l\'équipe', api('PATCH', "taches/$aid", ['actions_texte' => 'x'], $A('agent'))[0] === 403);
    $r = api('PATCH', "taches/$aid", ['actions_texte' => 'redémarrage du poste'], $A('gest'));
    check('actions menées : enregistrées', $r[0] === 200 && $r[1]['data']['actions_texte'] === 'redémarrage du poste');
    api('DELETE', "taches/$aid", null, $A('gest'));


    echo "Renouvellement du matériel\n";
    $pdo->exec("INSERT INTO t_type_materiel (type, duree_vie_mois) VALUES ('ZZ_V1_RENT', 36)");
    $pdo->exec("INSERT INTO t_materiel (type, marque, statut, num_serie, date_achat) VALUES ('ZZ_V1_RENT', 'ZZ', 'En service', 'ZZ_V1_RN1', CURRENT_DATE - interval '5 years')");
    $pdo->exec("INSERT INTO t_materiel (type, marque, statut, num_serie, date_achat) VALUES ('ZZ_V1_RENT', 'ZZ', 'En service', 'ZZ_V1_RN2', CURRENT_DATE - interval '6 months')");
    $m1 = (int) $pdo->query("SELECT id FROM t_materiel WHERE num_serie = 'ZZ_V1_RN1'")->fetchColumn();
    $m2 = (int) $pdo->query("SELECT id FROM t_materiel WHERE num_serie = 'ZZ_V1_RN2'")->fetchColumn();
    check('renouvellement : sans permission → 403', api('GET', 'renouvellements/suggestions', null, $A('tech'))[0] === 403);
    $r = api('GET', 'renouvellements/suggestions', null, $A('admin'));
    $sug = array_map(fn($x) => $x['materiel']['id'], $r[1]['data'] ?? []);
    check('suggestion : matériel au-delà de sa durée de vie', $r[0] === 200 && in_array($m1, $sug, true));
    check('suggestion : matériel récent absent', !in_array($m2, $sug, true));
    check('proposition sans critère ni motif → 422', api('POST', 'renouvellements', ['materiel_id' => $m2], $A('admin'))[0] === 422);
    $r = api('POST', 'renouvellements', ['materiel_id' => $m1, 'cout_estime' => 850], $A('admin'));
    $rid = $r[1]['data']['id'] ?? 0;
    check('proposition créée (origine auto, critères figés)', $r[0] === 201 && $r[1]['data']['origine'] === 'auto' && count($r[1]['data']['criteres']) >= 1);
    check('proposition en double → 409', api('POST', 'renouvellements', ['materiel_id' => $m1], $A('admin'))[0] === 409);
    check('matériel proposé retiré des suggestions', !in_array($m1, array_map(fn($x) => $x['materiel']['id'], api('GET', 'renouvellements/suggestions', null, $A('admin'))[1]['data']), true));
    check('refus sans raison → 422', api('POST', "renouvellements/$rid/decision", ['decision' => 'Refusé'], $A('admin'))[0] === 422);
    check('Proposé → Remplacé interdit → 409', api('POST', "renouvellements/$rid/decision", ['decision' => 'Remplacé'], $A('admin'))[0] === 409);
    check('décision refusée sans permission', api('POST', "renouvellements/$rid/decision", ['decision' => 'Validé'], $A('tech'))[0] === 403);
    foreach (['Validé', 'Commandé', 'Remplacé'] as $d) {
        check("décision « $d »", api('POST', "renouvellements/$rid/decision", ['decision' => $d], $A('admin'))[0] === 200);
    }
    check('remplacé : ancien matériel retiré', $pdo->query("SELECT statut FROM t_materiel WHERE id = $m1")->fetchColumn() === 'Retiré');
    check('liste filtrée par statut', count(api('GET', 'renouvellements?statut=Remplacé', null, $A('admin'))[1]['data'] ?? []) >= 1);
    $pdo->exec("DELETE FROM t_renouvellements WHERE materiel_id IN ($m1, $m2)");
    $pdo->exec("DELETE FROM t_materiel WHERE num_serie LIKE 'ZZ_V1_RN%'");
    $pdo->exec("DELETE FROM t_type_materiel WHERE type = 'ZZ_V1_RENT'");

    echo "Maintenance préventive\n";
    $pdo->exec("INSERT INTO t_type_materiel (type) VALUES ('ZZ_V1_MNT')");
    $pdo->exec("INSERT INTO t_materiel (type, marque, statut, num_serie, date_fin_garantie) VALUES ('ZZ_V1_MNT', 'ZZ', 'En service', 'ZZ_V1_MA', CURRENT_DATE + 30)");
    $pdo->exec("INSERT INTO t_materiel (type, marque, statut, num_serie) VALUES ('ZZ_V1_MNT', 'ZZ', 'En service', 'ZZ_V1_MB')");
    $pdo->exec("INSERT INTO t_materiel (type, marque, statut, num_serie) VALUES ('ZZ_V1_MNT', 'ZZ', 'Retiré', 'ZZ_V1_MC')");
    $ma = (int) $pdo->query("SELECT id FROM t_materiel WHERE num_serie = 'ZZ_V1_MA'")->fetchColumn();
    check('garantie proche : matériel suggéré au renouvellement', in_array($ma, array_map(fn($x) => $x['materiel']['id'], api('GET', 'renouvellements/suggestions', null, $A('admin'))[1]['data']), true));
    check('maintenance : sans permission → 403', api('GET', 'maintenance/plans', null, $A('tech'))[0] === 403);
    check('plan sans cible → 422', api('POST', 'maintenance/plans', ['titre' => 'ZZ_V1_PLAN', 'periodicite_valeur' => 1, 'periodicite_unite' => 'mois', 'prochaine_echeance' => date('Y-m-d')], $A('admin'))[0] === 422);
    check('plan avec deux cibles → 422', api('POST', 'maintenance/plans', ['titre' => 'ZZ_V1_PLAN', 'materiel_id' => $ma, 'type_materiel' => 'ZZ_V1_MNT', 'periodicite_valeur' => 1, 'periodicite_unite' => 'mois', 'prochaine_echeance' => date('Y-m-d')], $A('admin'))[0] === 422);
    $r = api('POST', 'maintenance/plans', ['titre' => 'ZZ_V1_PLAN', 'type_materiel' => 'ZZ_V1_MNT', 'periodicite_valeur' => 3, 'periodicite_unite' => 'mois',
        'prochaine_echeance' => date('Y-m-d', strtotime('+5 days')), 'anticipation_jours' => 7, 'checklist' => "Nettoyer\nVérifier"], $A('admin'));
    $pid = $r[1]['data']['id'] ?? 0;
    check('plan créé (2 matériels actifs ciblés)', $r[0] === 201 && $r[1]['data']['nb_materiels'] === 2);
    $r = api('POST', 'maintenance/generer', ['plan_id' => $pid], $A('admin'));
    check('génération : une tâche par matériel actif', $r[0] === 200 && $r[1]['data']['taches'] === 2);
    check('tâches créées avec source système et date limite = échéance',
        (int) $pdo->query("SELECT COUNT(*) FROM t_taches WHERE titre = '[Préventif] ZZ_V1_PLAN' AND source = 'systeme' AND date_limite = CURRENT_DATE + 5")->fetchColumn() === 2);
    check('échéance avancée de 3 mois', api('GET', "maintenance/plans/$pid", null, $A('admin'))[1]['data']['prochaine_echeance'] === date('Y-m-d', strtotime(date('Y-m-d', strtotime('+5 days')) . ' +3 months')));
    check('rejeu sans doublon', api('POST', 'maintenance/generer', ['plan_id' => $pid], $A('admin'))[1]['data']['taches'] === 0);
    $pdo->exec("UPDATE t_plans_maintenance SET prochaine_echeance = CURRENT_DATE + 5 WHERE id = $pid");
    check('même échéance déjà générée : pas de doublon (idempotence)', api('POST', 'maintenance/generer', ['plan_id' => $pid], $A('admin'))[1]['data']['taches'] === 0);
    check('plan modifié', api('PATCH', "maintenance/plans/$pid", ['actif' => false], $A('admin'))[1]['data']['actif'] === false);
    check('plan inactif ignoré', api('POST', 'maintenance/generer', ['plan_id' => $pid], $A('admin'))[1]['data']['plans'] === 0);
    check('plan supprimé', api('DELETE', "maintenance/plans/$pid", null, $A('admin'))[0] === 200);
    check('garantie visible sur la fiche matériel', api('GET', "materiel/$ma", null, $A('tech'))[1]['data']['date_fin_garantie'] === date('Y-m-d', strtotime('+30 days')));
    check('garantie modifiable', api('PATCH', "materiel/$ma", ['date_fin_garantie' => '2030-01-31'], $A('tech'))[1]['data']['date_fin_garantie'] === '2030-01-31');
    $pdo->exec("DELETE FROM t_taches WHERE titre = '[Préventif] ZZ_V1_PLAN'");
    $pdo->exec("DELETE FROM t_materiel WHERE num_serie LIKE 'ZZ_V1_M_'");
    $pdo->exec("DELETE FROM t_type_materiel WHERE type = 'ZZ_V1_MNT'");

    echo "Planning\n";
    $pdo->exec("INSERT INTO t_type_materiel (type) VALUES ('ZZ_V1_PLN')");
    $pdo->exec("INSERT INTO t_materiel (type, marque, statut, num_serie, date_fin_garantie) VALUES ('ZZ_V1_PLN', 'ZZ', 'En service', 'ZZ_V1_PL', CURRENT_DATE + 3)");
    $r = api('POST', 'maintenance/plans', ['titre' => 'ZZ_V1_PLANNING', 'type_materiel' => 'ZZ_V1_PLN', 'periodicite_valeur' => 1, 'periodicite_unite' => 'semaine',
        'prochaine_echeance' => date('Y-m-d', strtotime('+2 days')), 'anticipation_jours' => 0], $A('admin'));
    $plid = $r[1]['data']['id'] ?? 0;
    $du = date('Y-m-d'); $au = date('Y-m-d', strtotime('+10 days'));
    $r = api('GET', "planning?du=$du&au=$au", null, $A('admin'));
    $ev = $r[1]['data'] ?? [];
    $plans = array_values(array_filter($ev, fn($e) => $e['type'] === 'plan' && $e['titre'] === 'ZZ_V1_PLANNING'));
    check('planning : plan hebdomadaire projeté (2 occurrences sur 10 jours)', $r[0] === 200 && count($plans) === 2);
    check('planning : fin de garantie', count(array_filter($ev, fn($e) => $e['type'] === 'garantie' && str_contains($e['detail'], 'ZZ_V1_PL'))) === 1);
    check('planning : période trop longue → 422', api('GET', "planning?du=$du&au=" . date('Y-m-d', strtotime('+90 days')), null, $A('admin'))[0] === 422);
    check('planning : sans jeton → 401', api('GET', 'planning')[0] === 401);
    $tp = api('POST', 'taches', ['titre' => 'ZZ_V1_PLN_T', 'priorite' => 'Haute', 'service_id' => $svc, 'date_limite' => date('Y-m-d', strtotime('+1 day'))], $A('tech'));
    $r = api('GET', "planning?du=$du&au=$au", null, $A('admin'));
    check('planning : échéance de panne listée', count(array_filter($r[1]['data'], fn($e) => $e['type'] === 'echeance' && $e['titre'] === 'ZZ_V1_PLN_T')) === 1 && $tp[0] === 201, $tp[3]);
    $r = api('GET', "planning?du=$du&au=$au", null, $A('agent'));
    check('planning : le déclarant ne voit ni plans ni garanties', $r[0] === 200 && !array_filter($r[1]['data'], fn($e) => in_array($e['type'], ['plan', 'garantie'], true)));
    api('DELETE', "maintenance/plans/$plid", null, $A('admin'));
    $pdo->exec("DELETE FROM t_taches WHERE titre = 'ZZ_V1_PLN_T'");
    $pdo->exec("DELETE FROM t_materiel WHERE num_serie = 'ZZ_V1_PL'");
    $pdo->exec("DELETE FROM t_type_materiel WHERE type = 'ZZ_V1_PLN'");

    echo "Pont avec la session du site web\n";
    $web = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO t_sessions (user_id, session_token, expiry_time) VALUES (?, ?, NOW() + interval '1 hour')")->execute([$ids['admin'], hash('sha256', $web)]);
    $r = api('GET', 'roles', null, null, ['X-Session-Token' => $web, 'X-User-Id' => $ids['admin']]);
    check('session web acceptée par la v1', $r[0] === 200 && isset($r[1]['data'][0]['permissions']));
    check('session web : mauvais utilisateur → 401', api('GET', 'roles', null, null, ['X-Session-Token' => $web, 'X-User-Id' => $ids['gest']])[0] === 401);
    check('session web inconnue → 401', api('GET', 'roles', null, null, ['X-Session-Token' => 'x', 'X-User-Id' => $ids['admin']])[0] === 401);


} finally {
    nettoyer($pdo, $comptes, $rolesTest);
}
echo "\n$ok réussi(s), $ko échec(s)\n";
exit($ko === 0 ? 0 : 1);
