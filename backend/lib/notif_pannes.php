<?php
// Notifications liées aux pannes : boîte de réception dans l'application + envois e-mail / SMS / push.
//
// Principe : les évènements de t_taches_evenements sont la source unique. notif_traiter_evenements()
// les transforme en notifications (une seule fois, grâce à notifie_at) ; notif_envoyer_file() livre
// ensuite les canaux externes. Tout est « au mieux » : un canal indisponible (SMTP, passerelle SMS,
// FCM) n'empêche jamais la création ou le suivi d'une panne, il est simplement réessayé plus tard.
//
// À inclure après config/db.php, config/parametres.php et lib/notifications.php.

require_once __DIR__ . '/notifications.php';

const NOTIF_MAX_TENTATIVES = 5;
const NOTIF_DELAIS_MIN = [1, 5, 30, 120];   // attente avant la 2e, 3e, 4e, 5e tentative

/**
 * Programme le traitement des notifications à la fin de la requête (après la réponse au client si
 * PHP-FPM le permet). Appelé par tache_evenement() : fonctionne pour le site web comme pour l'API mobile.
 */
function notif_planifier(PDO $pdo): void
{
    static $planifie = false;
    if ($planifie) {
        return;
    }
    $planifie = true;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            if ($pdo->inTransaction()) {
                return; // transaction restée ouverte : le script cron rattrapera
            }
            notif_traiter_evenements($pdo);
            notif_envoyer_file($pdo, get_parametres($pdo), 20);
        } catch (Throwable $e) {
            error_log('Notifications : ' . $e->getMessage());
        }
    });
}

/** Texte lisible d'un évènement, du point de vue de $role : 'declarant', 'equipe' ou 'assigne'. */
function notif_contenu(array $ev, array $t, string $role): ?array
{
    $ref = $t['reference'] ?: ('#' . $t['id']);
    $acteur = $ev['acteur_nom'] ?: 'Le service IT';
    $titre = $t['titre'];
    switch ($ev['type']) {
        case 'creation':
            return $role === 'declarant'
                ? ['declaree', "Panne $ref enregistrée", "Votre déclaration « $titre » est bien reçue. Vous serez prévenu dès sa prise en charge."]
                : ['nouvelle', "Nouvelle panne $ref", "« $titre » — priorité {$t['priorite']}" . ($t['service_concerne'] ? ", {$t['service_concerne']}" : '') . '.'];
        case 'prise_en_charge':
            return ['prise_en_charge', "Panne $ref prise en charge", "$acteur a pris en charge « $titre »."];
        case 'affectation':
            return $role === 'assigne'
                ? ['affectee', "Panne $ref à traiter", "$acteur vous a affecté « $titre »."]
                : ['affectation', "Panne $ref affectée", "« $titre » est maintenant suivie par " . ($ev['nouvel_assigne_nom'] ?: 'le service IT') . '.'];
        case 'statut':
            $extra = $ev['nouveau_statut'] === 'En attente' && $t['motif_attente'] ? " Motif : {$t['motif_attente']}." : '';
            return ['statut', "Panne $ref : {$ev['nouveau_statut']}", "« $titre » est passée de « {$ev['ancien_statut']} » à « {$ev['nouveau_statut']} ».$extra"];
        case 'commentaire':
            return ['commentaire', "Panne $ref : nouveau commentaire", "$acteur : " . mb_strimwidth((string) $ev['commentaire'], 0, 300, '…')];
    }
    return null;   // modification, relance, escalade : pas de notification mobile
}

/**
 * Transforme les évènements non traités en notifications. Sûr en concurrence (verrou consultatif +
 * SKIP LOCKED) et idempotent. Retourne le nombre de notifications créées.
 */
function notif_traiter_evenements(PDO $pdo, int $limite = 200): int
{
    $params = get_parametres($pdo);
    $crees = 0;
    $pdo->beginTransaction();
    try {
        $evs = $pdo->query("
            SELECT e.*, an.nom AS nouvel_assigne_nom
            FROM t_taches_evenements e LEFT JOIN t_utilisateurs an ON an.id = e.nouveau_assigne_id
            WHERE e.notifie_at IS NULL ORDER BY e.id LIMIT $limite FOR UPDATE OF e SKIP LOCKED
        ")->fetchAll();
        foreach ($evs as $ev) {
            $st = $pdo->prepare("SELECT t.*, f.id AS file_id FROM t_taches t
                LEFT JOIN t_utilisateurs f ON f.est_file_commune WHERE t.id = ?");
            $st->execute([$ev['tache_id']]);
            if ($t = $st->fetch()) {
                foreach (notif_destinataires($pdo, $ev, $t) as [$uid, $role]) {
                    if ($c = notif_contenu($ev, $t, $role)) {
                        $crees += notif_creer($pdo, $params, $uid, $t, $ev, $c, $role === 'equipe' && (int) $t['assigne_id'] === (int) $t['file_id']);
                    }
                }
            }
            $pdo->prepare("UPDATE t_taches_evenements SET notifie_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$ev['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $crees;
}

/** @return array<int, array{0:int,1:string}> [user_id, rôle] des personnes à prévenir (jamais l'auteur de l'action). */
function notif_destinataires(PDO $pdo, array $ev, array $t): array
{
    $acteur = $ev['acteur_id'] !== null ? (int) $ev['acteur_id'] : null;
    $out = [];
    $declarant = $t['declarant_id'] !== null ? (int) $t['declarant_id'] : null;
    $assigne = $t['assigne_id'] !== null ? (int) $t['assigne_id'] : null;

    switch ($ev['type']) {
        case 'creation':
            if ($declarant) {
                $out[] = [$declarant, 'declarant'];
            }
            $file = (int) $t['file_id'];
            $initial = $ev['nouveau_assigne_id'] !== null ? (int) $ev['nouveau_assigne_id'] : $assigne;
            if ($initial === $file) {
                // Panne en file : toute l'équipe IT est prévenue pour qu'un agent la prenne — sauf si quelqu'un l'a déjà prise.
                if ($assigne === $file) {
                    foreach (notif_agents_it($pdo) as $uid) {
                        $out[] = [$uid, 'equipe'];
                    }
                }
            } elseif ($initial) {
                $out[] = [$initial, 'assigne'];
            }
            break;
        case 'affectation':
            if ($declarant) {
                $out[] = [$declarant, 'declarant'];
            }
            if ($ev['nouveau_assigne_id'] && (int) $ev['nouveau_assigne_id'] !== (int) $t['file_id']) {
                $out[] = [(int) $ev['nouveau_assigne_id'], 'assigne'];
            } elseif ($ev['nouveau_assigne_id'] && $assigne === (int) $t['file_id']) {
                foreach (notif_agents_it($pdo) as $uid) {   // remise en file (seulement si elle y est encore)
                    $out[] = [$uid, 'equipe'];
                }
            }
            break;
        case 'prise_en_charge':
        case 'statut':
            if ($declarant) {
                $out[] = [$declarant, 'declarant'];
            }
            break;
        case 'commentaire':
            if (!$ev['visible_declarant']) {
                if ($assigne && $assigne !== (int) $t['file_id']) {
                    $out[] = [$assigne, 'assigne'];   // note interne : l'équipe seulement
                }
            } elseif ($acteur !== null && $acteur === $declarant) {
                if ($assigne && $assigne !== (int) $t['file_id']) {
                    $out[] = [$assigne, 'assigne'];
                }
            } elseif ($declarant) {
                $out[] = [$declarant, 'declarant'];
            }
            break;
    }
    // Un seul message par personne, jamais pour l'auteur de l'action, uniquement des comptes actifs.
    $vus = [];
    $res = [];
    foreach ($out as [$uid, $role]) {
        if ($uid === $acteur || isset($vus[$uid])) {
            continue;
        }
        $vus[$uid] = true;
        $res[] = [$uid, $role];
    }
    if (!$res) {
        return [];
    }
    $ids = implode(',', array_map(fn($r) => (int) $r[0], $res));
    $ok = array_flip($pdo->query("SELECT id FROM t_utilisateurs WHERE actif AND NOT est_file_commune AND id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($res, fn($r) => isset($ok[$r[0]])));
}

/** Agents pouvant prendre une panne (permission tache.prendre, ou rôle système). */
function notif_agents_it(PDO $pdo): array
{
    return array_map('intval', $pdo->query("
        SELECT u.id FROM t_utilisateurs u JOIN t_roles r ON r.nom = u.role
        WHERE u.actif AND u.connexion_autorisee AND NOT u.est_file_commune AND (r.systeme OR EXISTS (
            SELECT 1 FROM t_roles_permissions rp WHERE rp.role_id = r.id AND rp.permission_code = 'tache.prendre'))
    ")->fetchAll(PDO::FETCH_COLUMN));
}

/** Crée la notification et met ses envois externes en file. Retourne 1 si créée, 0 si déjà existante. */
function notif_creer(PDO $pdo, array $params, int $uid, array $t, array $ev, array $contenu, bool $prenable): int
{
    [$type, $titre, $message] = $contenu;
    $st = $pdo->prepare("INSERT INTO t_notifications (user_id, tache_id, evenement_id, type, titre, message, prenable)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (user_id, evenement_id) DO NOTHING RETURNING id");
    $st->execute([$uid, $t['id'], $ev['id'], $type, mb_substr($titre, 0, 200), $message, $prenable ? 'true' : 'false']);
    $nid = $st->fetchColumn();
    if (!$nid) {
        return 0;
    }
    $u = $pdo->prepare("SELECT email, telephone, notif_email, notif_sms FROM t_utilisateurs WHERE id = ?");
    $u->execute([$uid]);
    $u = $u->fetch();
    $ins = $pdo->prepare("INSERT INTO t_notifications_envois (notification_id, canal, destinataire) VALUES (?, ?, ?)");
    if (($params['email_notifications_actives'] ?? '0') === '1' && $u['notif_email'] && $u['email']) {
        $ins->execute([$nid, 'email', $u['email']]);
    }
    if (($params['sms_actif'] ?? '0') === '1' && $params['sms_url'] !== '' && $u['notif_sms'] && $u['telephone']) {
        $ins->execute([$nid, 'sms', $u['telephone']]);
    }
    if (($params['fcm_actif'] ?? '0') === '1') {
        $tk = $pdo->prepare("SELECT fcm_token FROM t_appareils WHERE user_id = ? AND revoque_at IS NULL AND fcm_token IS NOT NULL");
        $tk->execute([$uid]);
        foreach ($tk->fetchAll(PDO::FETCH_COLUMN) as $token) {
            $ins->execute([$nid, 'push', $token]);
        }
    }
    return 1;
}

// ------------------------------------------------------------------ Livraison

/**
 * Livre les envois en attente dont l'heure est venue. $transport permet d'injecter les canaux
 * (tests) : ['email' => fn(dest,titre,message,params): void, 'sms' => …, 'push' => …] ;
 * un transport lève une exception pour signaler l'échec. Retourne [envoyés, échecs définitifs].
 */
function notif_envoyer_file(PDO $pdo, array $params, int $limite = 50, array $transport = []): array
{
    $transport += ['email' => 'notif_transport_email', 'sms' => 'notif_transport_sms', 'push' => 'notif_transport_push'];
    $envoyes = 0;
    $echecs = 0;
    $pdo->beginTransaction();
    try {
        $lignes = $pdo->query("
            SELECT v.*, n.titre, n.message, n.tache_id, n.id AS nid, n.prenable FROM t_notifications_envois v
            JOIN t_notifications n ON n.id = v.notification_id
            WHERE v.statut = 'en_attente' AND v.prochain_essai <= CURRENT_TIMESTAMP
            ORDER BY v.id LIMIT $limite FOR UPDATE OF v SKIP LOCKED
        ")->fetchAll();
        foreach ($lignes as $l) {
            try {
                $l['_pdo'] = $pdo;
                ($transport[$l['canal']])($l['destinataire'], $l['titre'], $l['message'], $params, $l);
                $pdo->prepare("UPDATE t_notifications_envois SET statut='envoye', envoye_at=CURRENT_TIMESTAMP,
                    tentatives=tentatives+1, erreur=NULL WHERE id = ?")->execute([$l['id']]);
                $envoyes++;
            } catch (Throwable $e) {
                $n = (int) $l['tentatives'] + 1;
                $final = $n >= NOTIF_MAX_TENTATIVES;
                $delai = NOTIF_DELAIS_MIN[min($n - 1, count(NOTIF_DELAIS_MIN) - 1)];
                $pdo->prepare("UPDATE t_notifications_envois SET tentatives=?, erreur=?, statut=?,
                    prochain_essai = CURRENT_TIMESTAMP + (? || ' minutes')::interval WHERE id = ?")
                    ->execute([$n, mb_substr($e->getMessage(), 0, 500), $final ? 'echec' : 'en_attente', $delai, $l['id']]);
                $echecs += $final ? 1 : 0;
                error_log("Notification {$l['canal']} #{$l['id']} (essai $n) : " . $e->getMessage());
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [$envoyes, $echecs];
}

function notif_transport_email(string $dest, string $titre, string $message, array $params): void
{
    $mailer = new SmtpMailer($params);
    $mailer->send($dest, $titre, "$message\n\nOuvrez l'application Parc IT pour le détail.\n");
}

/** Passerelle SMS HTTP générique (voir paramètres sms_*). Message limité à 300 caractères. */
function notif_transport_sms(string $dest, string $titre, string $message, array $params): void
{
    $texte = mb_strimwidth("$titre — $message", 0, 300, '…');
    $js = fn(string $v) => substr(json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1);
    $corps = strtr($params['sms_corps'], ['{to}' => $js($dest), '{message}' => $js($texte), '{from}' => $js($params['sms_expediteur'])]);
    $entetes = ['Content-Type: application/json'];
    if ($params['sms_jeton'] !== '') {
        $entetes[] = 'Authorization: Bearer ' . $params['sms_jeton'];
    }
    notif_http_post($params['sms_url'], $corps, $entetes);
}

/** Push FCM (HTTP v1). Un jeton refusé comme inconnu est effacé de l'appareil. */
function notif_transport_push(string $token, string $titre, string $message, array $params, array $ligne = []): void
{
    $access = notif_fcm_access_token($params);
    $corps = json_encode(['message' => [
        'token' => $token,
        // Message « données » seul : l'application construit elle-même la notification (action « Je prends »,
        // même identifiant que la scrutation → pas de doublon).
        'data' => [
            'tache_id' => (string) ($ligne['tache_id'] ?? ''), 'notification_id' => (string) ($ligne['nid'] ?? ''),
            'titre' => $titre, 'message' => mb_strimwidth($message, 0, 300, '…'),
            'peut_prendre' => !empty($ligne['prenable']) ? '1' : '0',
        ],
        'android' => ['priority' => 'HIGH'],
    ]], JSON_UNESCAPED_UNICODE);
    try {
        notif_http_post("https://fcm.googleapis.com/v1/projects/{$params['fcm_projet']}/messages:send", $corps,
            ['Content-Type: application/json', "Authorization: Bearer $access"]);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'UNREGISTERED') || str_contains($e->getMessage(), 'NOT_FOUND')) {
            $ligne['_pdo']->prepare("UPDATE t_appareils SET fcm_token = NULL WHERE fcm_token = ?")->execute([$token]);
        }
        throw $e;
    }
}

function notif_fcm_access_token(array $params): string
{
    static $cache = ['jeton' => null, 'expire' => 0];
    if ($cache['jeton'] && $cache['expire'] > time() + 60) {
        return $cache['jeton'];
    }
    // Le compte de service est stocké en base (contenu JSON collé ou importé depuis l'écran Paramètres) ;
    // un chemin de fichier reste accepté pour les installations existantes.
    $brut = trim((string) $params['fcm_compte_service']);
    $sa = json_decode($brut !== '' && $brut[0] === '{' ? $brut : (string) @file_get_contents($brut), true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
        throw new RuntimeException('Fichier de compte de service FCM illisible.');
    }
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $entree = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    openssl_sign($entree, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
    $rep = notif_http_post('https://oauth2.googleapis.com/token',
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$entree." . $b64($signature)]),
        ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode($rep, true);
    if (empty($j['access_token'])) {
        throw new RuntimeException('Jeton FCM non obtenu.');
    }
    $cache = ['jeton' => $j['access_token'], 'expire' => $now + (int) ($j['expires_in'] ?? 3000)];
    return $cache['jeton'];
}

function notif_http_post(string $url, string $corps, array $entetes): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corps, CURLOPT_HTTPHEADER => $entetes,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
    $rep = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($rep === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP $code" . ($err ? " ($err)" : '') . ' ' . mb_substr((string) $rep, 0, 300));
    }
    return (string) $rep;
}
