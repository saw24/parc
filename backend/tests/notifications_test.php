<?php
// Tests des notifications de pannes (lot 4) : règles de destinataires, boîte de réception (HTTP réel),
// file d'envoi e-mail/SMS/push avec reprises, passerelle SMS (serveur local jetable). Usage :
//   php backend/tests/notifications_test.php [URL_V1]
// Crée des données « zz_nt_ » / « ZZ_NT_ » et les supprime à la fin. NE PAS lancer sur la production.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI uniquement.'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/taches.php';

$base = rtrim($argv[1] ?? 'http://localhost/parc/backend/api/v1/index.php', '/');
$ok = 0; $ko = 0;
function check(string $nom, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ✓ $nom\n"; } else { $ko++; echo "  ✗ $nom $detail\n"; }
}
function api(string $m, string $route, ?array $body = null, ?string $token = null): array {
    global $base;
    $ch = curl_init("$base/$route");
    $h = ['Content-Type: application/json'];
    if ($token) { $h[] = "Authorization: Bearer $token"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $raw, true)];
}

/** Le serveur traite les évènements en fin de requête (et tente déjà un envoi réel, qui échoue ici) :
 *  on attend la fin de ce traitement puis on remet les envois à zéro pour les piloter avec des transports simulés. */
function attendre_traitement(PDO $pdo, int $tacheId): void {
    for ($i = 0; $i < 40; $i++) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM t_taches_evenements WHERE tache_id = ? AND notifie_at IS NULL"); $n->execute([$tacheId]);
        if ((int) $n->fetchColumn() === 0) { break; }
        usleep(250000);
    }
    for ($i = 0; $i < 60; $i++) {   // attend que le serveur ait tenté chaque envoi une fois (il échoue : rien n'est configuré)
        $n = $pdo->prepare("SELECT COUNT(*) FROM t_notifications_envois v JOIN t_notifications n ON n.id = v.notification_id
            WHERE n.tache_id = ? AND v.tentatives = 0 AND v.statut = 'en_attente'"); $n->execute([$tacheId]);
        if ((int) $n->fetchColumn() === 0) { break; }
        usleep(250000);
    }
}

/** À appeler juste avant de piloter les envois à la main : toute requête serveur suivante retenterait les envois dus. */
function reinitialiser_envois(PDO $pdo): void {
    $pdo->exec("UPDATE t_notifications_envois SET statut = 'en_attente', tentatives = 0, erreur = NULL, prochain_essai = CURRENT_TIMESTAMP - INTERVAL '1 minute'
        WHERE notification_id IN (SELECT n.id FROM t_notifications n JOIN t_utilisateurs u ON u.id = n.user_id WHERE u.login LIKE 'zz_nt_%')");
}
$pwd = 'Zz-' . bin2hex(random_bytes(7));
$comptes = ['tech' => 'zz_nt_tech', 'tech2' => 'zz_nt_tech2', 'agent' => 'zz_nt_agent', 'agent2' => 'zz_nt_agent2'];
$roles = [
    'zz_nt_tech' => ['tache.voir_toutes', 'tache.creer', 'tache.modifier_siennes', 'tache.prendre', 'tache.affecter'],
    'zz_nt_agent' => ['tache.declarer', 'tache.voir_siennes'],
];
$parametresAvant = get_parametres($pdo);
$procSms = null;

function nettoyer(PDO $pdo, array $comptes, array $roles): void {
    $pdo->exec("DELETE FROM t_taches WHERE titre LIKE 'ZZ_NT_%'");
    $pdo->exec("DELETE FROM t_login_attempts WHERE login LIKE 'zz_nt_%'");
    $pdo->exec("DELETE FROM t_services WHERE nom LIKE 'ZZ_NT_%'");
    $pdo->prepare("DELETE FROM t_utilisateurs WHERE login IN (" . implode(',', array_fill(0, count($comptes), '?')) . ")")->execute(array_values($comptes));
    $pdo->prepare("DELETE FROM t_roles WHERE nom IN (" . implode(',', array_fill(0, count($roles), '?')) . ")")->execute(array_keys($roles));
}
nettoyer($pdo, $comptes, $roles);

try {
    foreach ($roles as $nom => $perms) {
        $st = $pdo->prepare("INSERT INTO t_roles (nom, description) VALUES (?, 'test') RETURNING id"); $st->execute([$nom]); $rid = (int) $st->fetchColumn();
        foreach ($perms as $p) { $pdo->prepare("INSERT INTO t_roles_permissions VALUES (?, ?)")->execute([$rid, $p]); }
    }
    $pdo->exec("INSERT INTO t_services (nom) VALUES ('ZZ_NT_SVC')");
    $svc = (int) $pdo->query("SELECT id FROM t_services WHERE nom = 'ZZ_NT_SVC'")->fetchColumn();
    $ids = [];
    foreach ($comptes as $k => $login) {
        $st = $pdo->prepare("INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe, email, telephone, service_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id");
        $st->execute(["ZZ $login", $login, str_starts_with($k, 'tech') ? 'zz_nt_tech' : 'zz_nt_agent', password_hash($pwd, PASSWORD_DEFAULT), "$login@example.org", '+22790000' . random_int(100, 999), $svc]);
        $ids[$k] = (int) $st->fetchColumn();
    }
    $tok = [];
    foreach ($comptes as $k => $login) {
        $r = api('POST', 'auth/login', ['login' => $login, 'password' => $pwd, 'appareil' => ['nom' => $k, 'plateforme' => 'android', 'version_app' => '1.0.0']]);
        $tok[$k] = $r[1]['data']['access_token'] ?? '';
        if (!$tok[$k]) { echo 'LOGIN ' . $k . ': ' . json_encode($r) . "\n"; }
    }
    $notifs = fn(string $k, string $qs = '') => api('GET', 'notifications' . $qs, null, $tok[$k]);
    $types = fn(array $r) => array_map(fn($n) => $n['type'], $r[1]['data'] ?? []);

    echo "Déclaration : l'équipe IT est prévenue, pas le déclarant lui-même\n";
    $r = api('POST', 'taches', ['titre' => 'ZZ_NT_écran noir', 'description' => 'd', 'priorite' => 'Haute', 'client_uuid' => '11111111-2222-4333-8444-555555555555'], $tok['agent']);
    $tid = (int) ($r[1]['data']['id'] ?? 0);
    check('panne déclarée', $r[0] === 201 && $tid > 0, json_encode($r));
    $nt = $notifs('tech');
    check('tech reçoit « nouvelle »', $types($nt) === ['nouvelle'] && $nt[1]['data'][0]['tache']['id'] === $tid);
    check('« Je prends » proposé (panne en file)', $nt[1]['data'][0]['peut_prendre'] === true);
    check('tech2 aussi', $types($notifs('tech2')) === ['nouvelle']);
    check('le déclarant (auteur) ne reçoit rien', $notifs('agent')[1]['data'] === []);
    check('un autre agent non plus', $notifs('agent2')[1]['data'] === []);
    check('compteur non_lues', ($nt[1]['meta']['non_lues'] ?? -1) === 1);

    echo "Prise en charge\n";
    $r = api('POST', "taches/$tid/prendre", [], $tok['tech']);
    check('prise en charge', $r[0] === 200);
    check('déclarant prévenu avec le nom de l\'agent', ($n = $notifs('agent')[1]['data'][0] ?? null) && $n['type'] === 'prise_en_charge' && str_contains($n['message'], 'ZZ zz_nt_tech'));
    check('tech (auteur) pas notifié de sa propre action', $types($notifs('tech')) === ['nouvelle']);
    check('« Je prends » disparaît pour tech2 (déjà prise)', $notifs('tech2')[1]['data'][0]['peut_prendre'] === false);

    echo "Statut et commentaires\n";
    api('POST', "taches/$tid/statut", ['statut' => 'En attente', 'motif_attente' => 'Pièce commandée', 'date_relance' => date('Y-m-d', strtotime('+3 days'))], $tok['tech']);
    $m = $notifs('agent')[1]['data'][0];
    check('changement de statut notifié avec le motif', $m['type'] === 'statut' && str_contains($m['message'], 'Pièce commandée'));
    $avant = count($notifs('agent')[1]['data']);
    api('POST', "taches/$tid/commentaires", ['commentaire' => 'note interne', 'visible_declarant' => false], $tok['tech']);
    check('note interne : déclarant non notifié', count($notifs('agent')[1]['data']) === $avant);
    api('POST', "taches/$tid/commentaires", ['commentaire' => 'Je passe demain "matin" é à'], $tok['tech']);
    $m = $notifs('agent')[1]['data'][0];
    check('commentaire visible notifié', $m['type'] === 'commentaire' && str_contains($m['message'], 'demain'));
    api('POST', "taches/$tid/commentaires", ['commentaire' => 'Merci !'], $tok['agent']);
    check('commentaire du déclarant → agent assigné prévenu', ($notifs('tech')[1]['data'][0]['type'] ?? '') === 'commentaire');

    echo "Affectation\n";
    api('POST', "taches/$tid/affecter", ['assigne_id' => $ids['tech2']], $tok['tech']);
    check('nouvel assigné prévenu', ($notifs('tech2')[1]['data'][0]['type'] ?? '') === 'affectee');
    check('déclarant informé de l\'affectation', ($notifs('agent')[1]['data'][0]['type'] ?? '') === 'affectation');

    echo "Boîte de réception\n";
    $tout = $notifs('agent');
    $lastId = $tout[1]['data'][0]['id'];
    check('plus récentes d\'abord', $tout[1]['data'][0]['id'] > $tout[1]['data'][1]['id']);
    check('apres_id filtre', $notifs('agent', '?apres_id=' . $lastId)[1]['data'] === []);
    $r = api('POST', 'notifications/lues', ['ids' => [$lastId]], $tok['agent']);
    check('marquer lue', $r[0] === 200 && $r[1]['data']['marquees'] === 1);
    $r = api('POST', 'notifications/lues', ['ids' => [$lastId]], $tok['agent']);
    check('déjà lue : rien de plus', $r[1]['data']['marquees'] === 0);
    check('non_lues=1 exclut les lues', !in_array($lastId, array_column($notifs('agent', '?non_lues=1')[1]['data'], 'id'), true));
    $autre = $notifs('tech')[1]['data'][0]['id'];
    $r = api('POST', 'notifications/lues', ['ids' => [$autre]], $tok['agent']);
    check('on ne peut pas lire les notifications d\'autrui', $r[1]['data']['marquees'] === 0);
    check('ids invalides → 422', api('POST', 'notifications/lues', ['ids' => ['x']], $tok['agent'])[0] === 422);
    $r = api('POST', 'notifications/lues', ['toutes' => true], $tok['agent']);
    check('tout marquer lu', $r[0] === 200 && ($notifs('agent')[1]['meta']['non_lues'] ?? -1) === 0);
    check('sans jeton → 401', api('GET', 'notifications')[0] === 401);
    $e = $pdo->prepare("SELECT COUNT(*) FROM t_taches_evenements WHERE tache_id = ? AND notifie_at IS NULL"); $e->execute([$tid]);
    check('tous les évènements marqués traités', (int) $e->fetchColumn() === 0);
    check('retraitement : aucun doublon', notif_traiter_evenements($pdo) === 0);

    echo "Préférences de canaux\n";
    $r = api('PATCH', 'me', ['notif_sms' => false], $tok['agent']);
    check('désactiver les SMS', $r[0] === 200 && $r[1]['data']['notifications']['sms'] === false && $r[1]['data']['notifications']['email'] === true);
    check('valeur non booléenne → 422', api('PATCH', 'me', ['notif_sms' => 'non'], $tok['agent'])[0] === 422);

    echo "Canaux externes (transports simulés)\n";
    save_parametres($pdo, ['email_notifications_actives' => '1', 'sms_actif' => '1', 'sms_url' => 'http://127.0.0.1:9/x', 'fcm_actif' => '1'], 'test');
    $pdo->prepare("UPDATE t_appareils SET fcm_token = ? WHERE user_id = ?")->execute(['tok-' . bin2hex(random_bytes(4)), $ids['tech']]);
    $r = api('POST', 'taches', ['titre' => 'ZZ_NT_canaux', 'priorite' => 'Basse'], $tok['agent']);
    $t2 = (int) $r[1]['data']['id'];
    attendre_traitement($pdo, $t2);
    notif_traiter_evenements($pdo);
    $envois = $pdo->prepare("SELECT v.canal, u.login FROM t_notifications_envois v JOIN t_notifications n ON n.id = v.notification_id
        JOIN t_utilisateurs u ON u.id = n.user_id WHERE n.tache_id = ? ORDER BY 1, 2");
    $envois->execute([$t2]);
    $liste = array_map(fn($x) => $x['canal'] . ':' . $x['login'], $envois->fetchAll());
    check('e-mail pour les deux techniciens', in_array('email:zz_nt_tech', $liste, true) && in_array('email:zz_nt_tech2', $liste, true), implode(',', $liste));
    check('SMS pour les deux techniciens', in_array('sms:zz_nt_tech', $liste, true) && in_array('sms:zz_nt_tech2', $liste, true));
    check('push seulement pour l\'appareil enregistré', in_array('push:zz_nt_tech', $liste, true) && !in_array('push:zz_nt_tech2', $liste, true));
    // Préférence : agent2 désactive l'e-mail, puis une panne affectée → pas d'envoi e-mail pour lui
    $pdo->prepare("UPDATE t_utilisateurs SET notif_email = FALSE WHERE id = ?")->execute([$ids['tech2']]);
    $r = api('POST', 'taches', ['titre' => 'ZZ_NT_pref', 'priorite' => 'Basse'], $tok['agent']);
    attendre_traitement($pdo, (int) $r[1]['data']['id']);
    notif_traiter_evenements($pdo);
    $c = $pdo->prepare("SELECT v.canal FROM t_notifications_envois v JOIN t_notifications n ON n.id = v.notification_id WHERE n.tache_id = ? AND n.user_id = ?");
    $c->execute([(int) $r[1]['data']['id'], $ids['tech2']]);
    check('préférence respectée (pas d\'e-mail, SMS oui)', $c->fetchAll(PDO::FETCH_COLUMN) === ['sms']);

    reinitialiser_envois($pdo);
    $recus = [];
    $ok_t = function ($canal) use (&$recus) { return function ($dest, $titre, $msg) use (&$recus, $canal) { $recus[] = [$canal, $dest, $titre]; }; };
    [$env, $ech] = notif_envoyer_file($pdo, get_parametres($pdo), 100, ['email' => $ok_t('email'), 'sms' => $ok_t('sms'), 'push' => $ok_t('push')]);
    check('envois livrés', $env >= 5 && $ech === 0 && count($recus) === $env, "env=$env ech=$ech recus=" . json_encode(array_map(fn($x) => $x[0], $recus)) . ' ' . json_encode($liste));
    check('titre transmis', str_contains($recus[0][2] ?? '', 'anne'), json_encode($recus[0] ?? null));
    [$env2] = notif_envoyer_file($pdo, get_parametres($pdo), 100, ['email' => $ok_t('email'), 'sms' => $ok_t('sms'), 'push' => $ok_t('push')]);
    check('rien n\'est renvoyé deux fois', $env2 === 0);

    echo "Reprises en cas d'échec\n";
    $r = api('POST', 'taches', ['titre' => 'ZZ_NT_echec', 'priorite' => 'Basse'], $tok['agent']);
    $t3 = (int) $r[1]['data']['id'];
    attendre_traitement($pdo, $t3);
    notif_traiter_evenements($pdo);
    $pdo->prepare("UPDATE t_notifications_envois SET statut = 'envoye' WHERE notification_id IN (SELECT id FROM t_notifications WHERE tache_id <> ?)")->execute([$t3]);
    $pdo->prepare("UPDATE t_notifications_envois SET statut = 'en_attente', tentatives = 0, erreur = NULL, prochain_essai = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE notification_id IN (SELECT id FROM t_notifications WHERE tache_id = ?)")->execute([$t3]);
    $ko_t = function () { throw new RuntimeException('passerelle indisponible'); };
    $tous_ko = ['email' => $ko_t, 'sms' => $ko_t, 'push' => $ko_t];
    notif_envoyer_file($pdo, get_parametres($pdo), 100, $tous_ko);
    $l = $pdo->query("SELECT statut, tentatives, erreur, prochain_essai > CURRENT_TIMESTAMP AS futur FROM t_notifications_envois WHERE statut <> 'envoye' ORDER BY id LIMIT 1")->fetch();
    check('1er échec : reste en attente, erreur gardée, essai reporté', $l['statut'] === 'en_attente' && (int) $l['tentatives'] === 1 && $l['erreur'] === 'passerelle indisponible' && $l['futur']);
    [$env3] = notif_envoyer_file($pdo, get_parametres($pdo), 100, $ok_t('x') ? ['email' => $ok_t('e'), 'sms' => $ok_t('s'), 'push' => $ok_t('p')] : []);
    check('pas de nouvel essai avant l\'heure prévue', $env3 === 0);
    for ($i = 2; $i <= NOTIF_MAX_TENTATIVES; $i++) {
        $pdo->exec("UPDATE t_notifications_envois SET prochain_essai = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE statut = 'en_attente'");
        [, $abandons] = notif_envoyer_file($pdo, get_parametres($pdo), 100, $tous_ko);
    }
    check('abandon après ' . NOTIF_MAX_TENTATIVES . ' tentatives', $abandons > 0 && (int) $pdo->query("SELECT COUNT(*) FROM t_notifications_envois WHERE statut = 'en_attente'")->fetchColumn() === 0);
    check('la notification dans l\'application reste disponible', count($notifs('tech')[1]['data']) > 0);

    echo "Passerelle SMS HTTP\n";
    $dir = sys_get_temp_dir() . '/zz_nt_sms_' . bin2hex(random_bytes(3));
    mkdir($dir);
    file_put_contents("$dir/router.php", '<?php file_put_contents(' . var_export("$dir/recu.json", true) . ', json_encode(["body" => file_get_contents("php://input"), "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? ""])); http_response_code(file_exists(' . var_export("$dir/refus", true) . ') ? 500 : 202); echo "ok";');
    $port = random_int(20000, 40000);
    $procSms = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(600000);
    $p = ['sms_url' => "http://127.0.0.1:$port/envoi", 'sms_jeton' => 'secret123', 'sms_expediteur' => 'ParcIT', 'sms_corps' => '{"to":"{to}","from":"{from}","message":"{message}"}'];
    notif_transport_sms('+22790123456', 'Panne T-1', 'Écran "noir" à l\'accueil', $p);
    $recu = json_decode(file_get_contents("$dir/recu.json"), true);
    $corps = json_decode($recu['body'], true);
    check('corps JSON valide (guillemets et accents échappés)', is_array($corps) && $corps['to'] === '+22790123456' && str_contains($corps['message'], 'Écran "noir"'));
    check('jeton Bearer envoyé', $recu['auth'] === 'Bearer secret123');
    touch("$dir/refus");
    $leve = false;
    try { notif_transport_sms('+227', 't', 'm', $p); } catch (RuntimeException $e) { $leve = str_contains($e->getMessage(), '500'); }
    check('réponse HTTP 500 → échec signalé (donc réessayé)', $leve);
    $leve = false;
    try { notif_transport_sms('+227', 't', 'm', ['sms_url' => 'http://127.0.0.1:9/', 'sms_jeton' => '', 'sms_expediteur' => 'x', 'sms_corps' => '{}']); } catch (RuntimeException $e) { $leve = true; }
    check('passerelle injoignable → échec signalé', $leve);
} finally {
    if ($procSms) { proc_terminate($procSms); }
    if (isset($dir) && is_dir($dir)) { array_map('unlink', glob("$dir/*")); rmdir($dir); }
    foreach (['email_notifications_actives', 'sms_actif', 'sms_url', 'fcm_actif'] as $cle) {
        $avant = [$cle => $parametresAvant[$cle]];
        save_parametres($pdo, $avant, 'test');
    }
    nettoyer($pdo, $comptes, $roles);
}
echo "\n$ok réussis, $ko échec(s)\n";
exit($ko ? 1 : 0);
