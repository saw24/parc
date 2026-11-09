<?php
// Middleware d'authentification pour les API métier.
// À inclure APRÈS backend/config/db.php (a besoin de $pdo).
//
// Usage dans une API :
//     require_once __DIR__ . '/../config/db.php';
//     require_once __DIR__ . '/../middleware/auth.php';
//     $currentUser = require_auth($pdo);
//
// Reprend exactement la même logique de validation que api_auth.php
// (action=check_session) : token présent dans t_sessions, non expiré,
// rattaché à l'utilisateur indiqué.

/**
 * Les jetons de session ne sont jamais stockés en clair : t_sessions.session_token contient
 * le SHA-256 du jeton remis au client. Une fuite de la base ne permet donc pas de rejouer
 * les sessions en cours. (Le jeton aléatoire de 256 bits rend inutile un sel ou un hachage lent.)
 */
function hash_session_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Exige une session valide. Arrête l'exécution avec une réponse JSON 401
 * si le token est absent, invalide ou expiré. Retourne les infos de
 * l'utilisateur authentifié en cas de succès.
 */
function require_auth(PDO $pdo): array
{
    // Le frontend envoie le token via les en-têtes X-Session-Token / X-User-Id
    // (voir l'ajaxPrefilter dans script.js). On accepte aussi POST/GET en repli,
    // pour les appels directs (scripts de test, etc.).
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? $_POST['session_token'] ?? $_GET['session_token'] ?? '';
    $userId = intval($_SERVER['HTTP_X_USER_ID'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);

    if (empty($sessionToken) || $userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentification requise.']);
        exit();
    }

    try {
        $sql = "SELECT u.id, u.nom, u.login, u.role
                FROM t_sessions s
                JOIN t_utilisateurs u ON s.user_id = u.id
                WHERE s.session_token = ? AND s.user_id = ? AND s.expiry_time > NOW()
                  AND u.actif AND u.connexion_autorisee";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([hash_session_token($sessionToken), $userId]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Auth middleware DB error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la vérification de session.']);
        exit();
    }

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Session invalide ou expirée.']);
        exit();
    }

    $user['permissions'] = charger_permissions($pdo, $user['role']);
    return $user;
}

/**
 * Codes de permission d'un rôle. Un rôle système (Administrateur) possède TOUTES les
 * permissions, y compris celles ajoutées plus tard. Un rôle inconnu n'en a aucune.
 * @return list<string>
 */
function charger_permissions(PDO $pdo, string $role): array
{
    $stmt = $pdo->prepare("
        SELECT p.code
        FROM t_roles r
        JOIN t_permissions p ON r.systeme OR EXISTS (
            SELECT 1 FROM t_roles_permissions rp WHERE rp.role_id = r.id AND rp.permission_code = p.code)
        WHERE r.nom = ?
        ORDER BY p.ordre
    ");
    $stmt->execute([$role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function has_permission(array $user, string $code): bool
{
    return in_array($code, $user['permissions'] ?? [], true);
}

function has_any_permission(array $user, array $codes): bool
{
    foreach ($codes as $c) {
        if (has_permission($user, $c)) {
            return true;
        }
    }
    return false;
}

/** Arrête l'exécution avec 403 si l'utilisateur n'a pas la permission. */
function require_permission(array $user, string $code): void
{
    if (!has_permission($user, $code)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Accès refusé : permission manquante (' . $code . ').']);
        exit();
    }
}

/** Arrête l'exécution avec 403 si l'utilisateur n'a aucune des permissions. */
function require_any_permission(array $user, array $codes): void
{
    if (!has_any_permission($user, $codes)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Accès refusé : permission manquante.']);
        exit();
    }
}

/**
 * Exige que l'utilisateur authentifié ait l'un des rôles autorisés.
 * À appeler après require_auth(). Arrête l'exécution avec 403 sinon.
 */
function require_role(array $user, array $allowedRoles): void
{
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Accès refusé pour ce rôle.']);
        exit();
    }
}
