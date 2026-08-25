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
                WHERE s.session_token = ? AND s.user_id = ? AND s.expiry_time > NOW()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sessionToken, $userId]);
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

    return $user;
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
