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
];
$comptes = [ // clé => [login, rôle, nom]
    'admin' => ['zz_v1_admin', 'Administrateur'], 'tech' => ['zz_v1_tech', 'zz_v1_tech'], 'tech2' => ['zz_v1_tech2', 'zz_v1_tech'],
    'agent' => ['zz_v1_agent', 'zz_v1_agent'], 'agent2' => ['zz_v1_agent2', 'zz_v1_agent'], 'vide' => ['zz_v1_vide', 'zz_v1_vide'],
];

function nettoyer(PDO $pdo, array $comptes, array $rolesTest): void {
    $pdo->exec("DELETE FROM t_taches WHERE titre LIKE 'ZZ_V1_%'");
    $pdo->exec("DELETE FROM t_materiel WHERE num_serie LIKE 'ZZ_V1_%'");
    $pdo->exec("DELETE FROM t_login_attempts WHERE login LIKE 'zz_v1_%'");
    $pdo->exec("DELETE FROM t_services WHERE nom LIKE 'ZZ_V1_%'");
    $in = implode(',', array_fill(0, count($comptes), '?'));
    $pdo->prepare("DELETE FROM t_utilisateurs WHERE login IN ($in)")->execute(array_map(fn($c) => $c[0], array_values($comptes)));
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
    foreach (['admin', 'tech', 'tech2', 'agent', 'agent2', 'vide'] as $k) {
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
} finally {
    nettoyer($pdo, $comptes, $rolesTest);
}
echo "\n$ok réussi(s), $ko échec(s)\n";
exit($ko === 0 ? 0 : 1);
