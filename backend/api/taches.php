<?php
// api_taches.php

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);

// On détermine si la requête est de type GET ou POST
$method = $_SERVER['REQUEST_METHOD'];

// On récupère l'action. Pour une requête POST, l'action peut être dans les données POST.
$actions = $_GET['action'] ?? $_POST['actions'] ?? '';

switch ($actions) {
    case 'read':
        try {
            $sql = "SELECT * FROM t_taches";
            $params = [];

            if (isset($_GET['assigne_a'])) {
                $sql .= " WHERE assigne_a = ?";
                $params[] = $_GET['assigne_a'];
            }

            $sql .= " ORDER BY date_creation DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $taches = $stmt->fetchAll();
            echo json_encode($taches);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des tâches: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des tâches.']);
        }
        break;

    case 'create':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Récupération des données du formulaire
        $titre = isset($_POST['titre']) ? $_POST['titre'] : '';
        $motif_cause = isset($_POST['motif_cause']) ? $_POST['motif_cause'] : '';
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        $priorite = isset($_POST['priorite']) ? $_POST['priorite'] : '';
        $statut = isset($_POST['statut']) ? $_POST['statut'] : '';
        $assigne_a = isset($_POST['assigne_a']) ? $_POST['assigne_a'] : '';
        $materiel_concerne = isset($_POST['materiel_concerne']) ? $_POST['materiel_concerne'] : '';
        $service_concerne = isset($_POST['service_concerne']) ? $_POST['service_concerne'] : '';
        $date_limite = isset($_POST['date_limite']) && !empty($_POST['date_limite']) ? $_POST['date_limite'] : null;
        $actions = isset($_POST['actions']) ? $_POST['actions'] : '';
        $created_by_login = isset($_POST['created_by_login']) ? $_POST['created_by_login'] : '';
        $updated_by_login = isset($_POST['updated_by_login']) ? $_POST['updated_by_login'] : '';
        // Validation
        if (empty($titre) || empty($priorite) || empty($statut)) {
            http_response_code(400);
            echo json_encode(['error' => 'Le titre, la priorité et le statut sont requis.']);
            exit();
        }

        try {
            // Trace: created_by_login et updated_by_login (si colonnes présentes)
            $sql = "INSERT INTO t_taches (titre, motif_cause, description, priorite, statut, assigne_a, materiel_concerne, service_concerne, date_limite, actions, created_by_login, updated_by_login) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);

            $result = $stmt->execute([$titre, $motif_cause, $description, $priorite, $statut, $assigne_a, $materiel_concerne, $service_concerne, $date_limite, $actions, $created_by_login, $updated_by_login]);

            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Tâche créée avec succès.']);
            } else {
                $errorInfo = $stmt->errorInfo();
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la création de la tâche: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la création de la tâche: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la création de la tâche.']);
        }
        break;

    case 'update':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de tâche invalide.']);
            exit();
        }

        $titre = isset($_POST['titre']) ? $_POST['titre'] : '';
        $motif_cause = isset($_POST['motif_cause']) ? $_POST['motif_cause'] : '';
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        $priorite = isset($_POST['priorite']) ? $_POST['priorite'] : '';
        $statut = isset($_POST['statut']) ? $_POST['statut'] : '';
        $assigne_a = isset($_POST['assigne_a']) ? $_POST['assigne_a'] : '';
        $materiel_concerne = isset($_POST['materiel_concerne']) ? $_POST['materiel_concerne'] : '';
        $service_concerne = isset($_POST['service_concerne']) ? $_POST['service_concerne'] : '';
        $date_limite = isset($_POST['date_limite']) && !empty($_POST['date_limite']) ? $_POST['date_limite'] : null;
        $actions = isset($_POST['actions']) ? $_POST['actions'] : '';
        $updated_by_login = isset($_POST['updated_by_login']) ? $_POST['updated_by_login'] : $_SESSION['login']; // login de l'utilisateur courant

        if (empty($titre) || empty($priorite) || empty($statut)) {
            http_response_code(400);
            echo json_encode(['error' => 'Le titre, la priorité et le statut sont requis.']);
            exit();
        }

        try {
            $sql = "UPDATE t_taches SET titre = ?, motif_cause = ?, description = ?, priorite = ?, statut = ?, assigne_a = ?, materiel_concerne = ?, service_concerne = ?, date_limite = ?, actions = ?, updated_by_login = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            $result = $stmt->execute([$titre, $motif_cause, $description, $priorite, $statut, $assigne_a, $materiel_concerne, $service_concerne, $date_limite, $actions, $updated_by_login, $id]);

            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Tâche mise à jour avec succès.']);
            } else {
                $errorInfo = $stmt->errorInfo();
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour de la tâche: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la mise à jour de la tâche: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la mise à jour de la tâche.']);
        }
        break;

    case 'delete':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de tâche invalide.']);
            exit();
        }

        try {
            $checkSql = "SELECT id FROM t_taches WHERE id = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$id]);

            if ($checkStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Tâche non trouvée.']);
                exit();
            }

            $sql = "DELETE FROM t_taches WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            $result = $stmt->execute([$id]);

            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Tâche supprimée avec succès.']);
            } else {
                $errorInfo = $stmt->errorInfo();
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la suppression de la tâche: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la suppression de la tâche: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la suppression de la tâche.']);
        }
        break;

    case 'user_task_stats':
        if (!isset($_GET['user_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'User Name is required.']);
            exit();
        }

        $userName = $_GET['user_name'];


        try {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN statut = 'À faire' THEN 1 ELSE 0 END) as new_tasks,
                    SUM(CASE WHEN statut = 'En cours' THEN 1 ELSE 0 END) as in_progress_tasks
                FROM t_taches
                WHERE assigne_a = ?
            ");
            $stmt->execute([$userName]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Ensure we return 0 if counts are null (no tasks)
            $stats['new_tasks'] = (int) $stats['new_tasks'];
            $stats['in_progress_tasks'] = (int) $stats['in_progress_tasks'];

            // Liste des tâches "À faire" assignées (id + titre), pour permettre au frontend
            // de détecter précisément les nouvelles tâches et afficher une notification.
            $listStmt = $pdo->prepare("
                SELECT id, titre, date_creation
                FROM t_taches
                WHERE assigne_a = ? AND statut = 'À faire'
                ORDER BY date_creation DESC
            ");
            $listStmt->execute([$userName]);
            $stats['new_tasks_list'] = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            echo json_encode($stats);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Database error: ' . $e->getMessage());
            echo json_encode(['error' => 'Database error.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue.']);
        break;
}
