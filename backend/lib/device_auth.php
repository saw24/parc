<?php
// Sessions par appareil de l'API mobile : jeton d'accès court + jeton de rafraîchissement
// tournant. Seuls des hachés SHA-256 sont stockés (voir migration 007).
//
// Rejeu : si un ancien jeton de rafraîchissement est présenté APRÈS le délai de grâce, la session
// est révoquée (le jeton a pu être volé). Pendant le délai de grâce (réponse perdue puis nouvel
// essai légitime), de nouveaux jetons sont simplement réémis.

const DEVICE_ACCESS_TTL_S = 3600;            // 1 heure
const DEVICE_REFRESH_TTL_S = 90 * 86400;     // 90 jours
const DEVICE_REFRESH_GRACE_S = 60;
const DEVICE_LOGIN_MAX_ECHECS = 5;
const DEVICE_LOGIN_MAX_ECHECS_IP = 30;
const DEVICE_LOGIN_FENETRE_MIN = 15;

function device_hash(string $jeton): string
{
    return hash('sha256', $jeton);
}

function device_nouveau_jeton(): string
{
    return bin2hex(random_bytes(32));
}

/** Anti force brute (mêmes seuils et même table que l'API web). */
function device_login_bloque(PDO $pdo, string $login, string $ip): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*) FILTER (WHERE login = ?) AS par_login,
               COUNT(*) FILTER (WHERE ip <> '' AND ip = ?) AS par_ip
        FROM t_login_attempts
        WHERE succes = FALSE AND created_at > NOW() - (? || ' minutes')::interval
    ");
    $st->execute([$login, $ip, DEVICE_LOGIN_FENETRE_MIN]);
    $c = $st->fetch();
    return (int) $c['par_login'] >= DEVICE_LOGIN_MAX_ECHECS || (int) $c['par_ip'] >= DEVICE_LOGIN_MAX_ECHECS_IP;
}

function device_login_echec(PDO $pdo, string $login, string $ip): void
{
    $pdo->prepare("INSERT INTO t_login_attempts (login, ip, succes) VALUES (?, ?, FALSE)")->execute([substr($login, 0, 100), $ip]);
}

function device_login_succes(PDO $pdo, string $login): void
{
    $pdo->prepare("DELETE FROM t_login_attempts WHERE login = ? AND succes = FALSE")->execute([$login]);
}

/** @return array{access_token:string,refresh_token:string,expires_in:int,appareil_id:int} */
function device_creer(PDO $pdo, int $userId, array $info, string $ip): array
{
    $access = device_nouveau_jeton();
    $refresh = device_nouveau_jeton();
    $st = $pdo->prepare("
        INSERT INTO t_appareils (user_id, nom, plateforme, version_app, access_hash, access_expire,
                                 refresh_hash, refresh_expire, ip, derniere_activite)
        VALUES (?, ?, ?, ?, ?, NOW() + (? || ' seconds')::interval, ?, NOW() + (? || ' seconds')::interval, ?, NOW())
        RETURNING id
    ");
    $st->execute([
        $userId,
        isset($info['nom']) ? mb_substr((string) $info['nom'], 0, 100) : null,
        in_array($info['plateforme'] ?? 'android', ['android', 'ios', 'web'], true) ? ($info['plateforme'] ?? 'android') : 'android',
        isset($info['version_app']) ? mb_substr((string) $info['version_app'], 0, 20) : null,
        device_hash($access), DEVICE_ACCESS_TTL_S, device_hash($refresh), DEVICE_REFRESH_TTL_S, $ip,
    ]);
    // Au plus 10 appareils actifs par compte : le plus ancien inactif est révoqué.
    $pdo->prepare("
        UPDATE t_appareils SET revoque_at = NOW(), revoque_motif = 'limite_appareils'
        WHERE id IN (SELECT id FROM t_appareils WHERE user_id = ? AND revoque_at IS NULL
                     ORDER BY COALESCE(derniere_activite, created_at) DESC OFFSET 10)
    ")->execute([$userId]);
    return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => DEVICE_ACCESS_TTL_S, 'appareil_id' => (int) $st->fetchColumn()];
}

/**
 * Authentifie un jeton d'accès. Retourne [user, appareil] ou lève ApiException
 * (401 token_expire → le client doit rafraîchir ; 401 token_invalide → reconnexion).
 * @return array{0:array,1:array}
 */
function device_authentifier(PDO $pdo, string $jeton): array
{
    $st = $pdo->prepare("
        SELECT a.id AS appareil_id, a.user_id, a.nom AS appareil_nom, a.revoque_at, a.version_app,
               (a.access_expire > NOW()) AS valide,
               (a.derniere_activite IS NULL OR a.derniere_activite < NOW() - interval '60 seconds') AS a_toucher,
               u.id, u.nom, u.login, u.role, u.email, u.telephone, u.service_id, u.actif, u.connexion_autorisee
        FROM t_appareils a JOIN t_utilisateurs u ON u.id = a.user_id
        WHERE a.access_hash = ?
    ");
    $st->execute([device_hash($jeton)]);
    $r = $st->fetch();
    if (!$r || $r['revoque_at'] !== null || !$r['actif'] || !$r['connexion_autorisee']) {
        api_fail(401, 'token_invalide', 'Session invalide : reconnectez-vous.');
    }
    if (!$r['valide']) {
        api_fail(401, 'token_expire', 'Jeton expiré : rafraîchissez la session.');
    }
    if ($r['a_toucher']) {
        $pdo->prepare("UPDATE t_appareils SET derniere_activite = NOW() WHERE id = ?")->execute([$r['appareil_id']]);
    }
    $user = ['id' => (int) $r['id'], 'nom' => $r['nom'], 'login' => $r['login'], 'role' => $r['role'],
             'email' => $r['email'], 'telephone' => $r['telephone'], 'service_id' => $r['service_id'] !== null ? (int) $r['service_id'] : null];
    $user['permissions'] = charger_permissions($pdo, $user['role']);
    return [$user, ['id' => (int) $r['appareil_id'], 'nom' => $r['appareil_nom']]];
}

/** Rotation du jeton de rafraîchissement. @return array{access_token:string,refresh_token:string,expires_in:int} */
function device_rafraichir(PDO $pdo, string $refresh): array
{
    $h = device_hash($refresh);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("
            SELECT a.*, (a.refresh_expire > NOW()) AS refresh_valide,
                   (a.refresh_tourne_le IS NOT NULL AND a.refresh_tourne_le > NOW() - (? || ' seconds')::interval) AS dans_grace,
                   u.actif, u.connexion_autorisee
            FROM t_appareils a JOIN t_utilisateurs u ON u.id = a.user_id
            WHERE a.refresh_hash = ? OR a.refresh_precedent_hash = ?
            FOR UPDATE OF a
        ");
        $st->execute([DEVICE_REFRESH_GRACE_S, $h, $h]);
        $a = $st->fetch();
        if (!$a || $a['revoque_at'] !== null || !$a['actif'] || !$a['connexion_autorisee']) {
            $pdo->rollBack();
            api_fail(401, 'refresh_invalide', 'Session invalide : reconnectez-vous.');
        }
        $estPrecedent = trim((string) $a['refresh_precedent_hash']) === $h && trim((string) $a['refresh_hash']) !== $h;
        if ($estPrecedent && !$a['dans_grace']) {
            // Ancien jeton rejoué hors délai de grâce : possible vol → on coupe la session.
            $pdo->prepare("UPDATE t_appareils SET revoque_at = NOW(), revoque_motif = 'rejeu_refresh' WHERE id = ?")->execute([$a['id']]);
            $pdo->commit();
            api_fail(401, 'refresh_rejoue', 'Session révoquée par sécurité : reconnectez-vous.');
        }
        if (!$a['refresh_valide']) {
            $pdo->rollBack();
            api_fail(401, 'refresh_expire', 'Session expirée : reconnectez-vous.');
        }
        $access = device_nouveau_jeton();
        $nouveau = device_nouveau_jeton();
        $pdo->prepare("
            UPDATE t_appareils SET access_hash = ?, access_expire = NOW() + (? || ' seconds')::interval,
                refresh_precedent_hash = refresh_hash, refresh_hash = ?, refresh_tourne_le = NOW(),
                refresh_expire = NOW() + (? || ' seconds')::interval, derniere_activite = NOW()
            WHERE id = ?
        ")->execute([device_hash($access), DEVICE_ACCESS_TTL_S, device_hash($nouveau), DEVICE_REFRESH_TTL_S, $a['id']]);
        $pdo->commit();
        return ['access_token' => $access, 'refresh_token' => $nouveau, 'expires_in' => DEVICE_ACCESS_TTL_S];
    } catch (ApiException $e) {
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function device_revoquer(PDO $pdo, int $appareilId, int $userId, string $motif = 'deconnexion'): bool
{
    $st = $pdo->prepare("UPDATE t_appareils SET revoque_at = NOW(), revoque_motif = ? WHERE id = ? AND user_id = ? AND revoque_at IS NULL");
    $st->execute([$motif, $appareilId, $userId]);
    return $st->rowCount() > 0;
}

/** Authentifie une session du site web (t_sessions) ; mêmes règles que middleware/auth.php. */
function device_authentifier_session_web(PDO $pdo, string $jeton, int $userId): array
{
    $st = $pdo->prepare("SELECT u.id, u.nom, u.login, u.role, u.email, u.telephone, u.service_id
        FROM t_sessions s JOIN t_utilisateurs u ON u.id = s.user_id
        WHERE s.session_token = ? AND s.user_id = ? AND s.expiry_time > NOW() AND u.actif AND u.connexion_autorisee");
    $st->execute([hash_session_token($jeton), $userId]);
    $r = $st->fetch();
    if (!$r) {
        api_fail(401, 'token_invalide', 'Session invalide : reconnectez-vous.');
    }
    $r['id'] = (int) $r['id'];
    $r['service_id'] = $r['service_id'] !== null ? (int) $r['service_id'] : null;
    $r['permissions'] = charger_permissions($pdo, $r['role']);
    return $r;
}
