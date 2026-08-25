<?php
// api_auth.php - API pour l'authentification et la gestion des sessions

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';

// Démarrer la session PHP
session_start();

// On détermine si la requête est de type GET ou POST
$method = $_SERVER['REQUEST_METHOD'];

// On récupère l'action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $login = isset($_POST['login']) ? trim($_POST['login']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // Validation
        if (empty($login) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Login et mot de passe requis.']);
            exit();
        }

        try {
            // Vérifier les identifiants
            $sql = "SELECT id, nom, login, role, mot_de_passe FROM t_utilisateurs WHERE login = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['mot_de_passe'])) {
                // Connexion réussie
                
                // Générer un token de session unique
                $sessionToken = bin2hex(random_bytes(32));
                $expiryTime = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                // Stocker la session en base de données
                $sessionSql = "INSERT INTO t_sessions (user_id, session_token, expiry_time, created_at) VALUES (?, ?, ?, NOW())";
                $sessionStmt = $pdo->prepare($sessionSql);
                $sessionStmt->execute([$user['id'], $sessionToken, $expiryTime]);
                
                // Nettoyer les sessions expirées
                $cleanupSql = "DELETE FROM t_sessions WHERE expiry_time < NOW()";
                $pdo->exec($cleanupSql);
                
                // Retourner les informations de l'utilisateur (sans le mot de passe)
                unset($user['mot_de_passe']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Connexion réussie.',
                    'user' => $user,
                    'session_token' => $sessionToken
                ]);
            } else {
                // Identifiants incorrects
                http_response_code(401);
                echo json_encode(['error' => 'Identifiants incorrects.']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la connexion: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la connexion.']);
        }
        break;

    case 'check_session':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $sessionToken = isset($_POST['session_token']) ? $_POST['session_token'] : '';
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if (empty($sessionToken) || $userId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Token de session et ID utilisateur requis.']);
            exit();
        }

        try {
            // Vérifier la validité de la session
            $sql = "SELECT s.*, u.nom, u.login, u.role 
                    FROM t_sessions s 
                    JOIN t_utilisateurs u ON s.user_id = u.id 
                    WHERE s.session_token = ? AND s.user_id = ? AND s.expiry_time > NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$sessionToken, $userId]);
            $session = $stmt->fetch();

            if ($session) {
                // Session valide
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $session['user_id'],
                        'nom' => $session['nom'],
                        'login' => $session['login'],
                        'role' => $session['role']
                    ]
                ]);
            } else {
                // Session invalide ou expirée
                http_response_code(401);
                echo json_encode(['error' => 'Session invalide ou expirée.']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la vérification de session: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la vérification de session.']);
        }
        break;

    case 'logout':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $sessionToken = isset($_POST['session_token']) ? $_POST['session_token'] : '';

        if (!empty($sessionToken)) {
            try {
                // Supprimer la session de la base de données
                $sql = "DELETE FROM t_sessions WHERE session_token = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$sessionToken]);
            } catch (PDOException $e) {
                // Log l'erreur mais ne pas l'exposer à l'utilisateur
                error_log('Erreur lors de la déconnexion: ' . $e->getMessage());
            }
        }

        // Détruire la session PHP
        session_destroy();

        echo json_encode([
            'success' => true,
            'message' => 'Déconnexion réussie.'
        ]);
        break;

    case 'get_user_info':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $sessionToken = isset($_POST['session_token']) ? $_POST['session_token'] : '';
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if (empty($sessionToken) || $userId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Token de session et ID utilisateur requis.']);
            exit();
        }

        try {
            // Récupérer les informations de l'utilisateur
            $sql = "SELECT s.*, u.nom, u.login, u.role 
                    FROM t_sessions s 
                    JOIN t_utilisateurs u ON s.user_id = u.id 
                    WHERE s.session_token = ? AND s.user_id = ? AND s.expiry_time > NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$sessionToken, $userId]);
            $session = $stmt->fetch();

            if ($session) {
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $session['user_id'],
                        'nom' => $session['nom'],
                        'login' => $session['login'],
                        'role' => $session['role']
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Session invalide ou expirée.']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des informations utilisateur: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des informations utilisateur.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide.']);
        break;
}
?>
