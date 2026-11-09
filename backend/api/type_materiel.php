<?php
// api_type_materiel.php

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../lib/legacy.php';
$currentUser = require_auth($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

legacy_migre($action, ['read', 'create', 'update', 'delete']);

switch ($action) {
    // ===============================================
    // READ: Liste des types de matériel
    // ===============================================
    case 'read':
        require_permission($currentUser, 'referentiel.voir');
        try {
            $sql = "SELECT * FROM t_type_materiel ORDER BY type ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($types);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des types de matériel: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des types de matériel.']);
        }
        break;

    // ===============================================
    // CREATE: Créer un type de matériel
    // ===============================================
    case 'create':
        require_permission($currentUser, 'referentiel.gerer');
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug

        $type = isset($_POST['type']) ? trim($_POST['type']) : '';

        if (empty($type)) {
            http_response_code(400);
            echo json_encode(['error' => 'Le champ type est requis.']);
            exit();
        }

        try {
            // Vérifier l'unicité
            $check = $pdo->prepare('SELECT id FROM t_type_materiel WHERE type = ?');
            $check->execute([$type]);
            if ($check->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Ce type existe déjà.']);
                exit();
            }

            $stmt = $pdo->prepare('INSERT INTO t_type_materiel (type) VALUES (?)');
            $ok = $stmt->execute([$type]);
            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Type créé avec succès.', 'id' => $pdo->lastInsertId()]);
            } else {
                $errorInfo = $stmt->errorInfo();
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la création du type: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la création du type: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la création du type.']);
        }
        break;

    // ===============================================
    // UPDATE: Mettre à jour un type de matériel
    // ===============================================
    case 'update':
        require_permission($currentUser, 'referentiel.gerer');
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $type = isset($_POST['type']) ? trim($_POST['type']) : '';
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID invalide.']);
            exit();
        }
        if (empty($type)) {
            http_response_code(400);
            echo json_encode(['error' => 'Le champ type est requis.']);
            exit();
        }

        try {
            // Vérifier l'unicité sur un autre enregistrement
            $check = $pdo->prepare('SELECT id FROM t_type_materiel WHERE type = ? AND id != ?');
            $check->execute([$type, $id]);
            if ($check->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Un autre type avec ce nom existe déjà.']);
                exit();
            }

            $stmt = $pdo->prepare('UPDATE t_type_materiel SET type = ? WHERE id = ?');
            $ok = $stmt->execute([$type, $id]);
            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Type mis à jour avec succès.']);
            } else {
                $errorInfo = $stmt->errorInfo();
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour du type: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la mise à jour du type: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la mise à jour du type.']);
        }
        break;

    // ===============================================
    // DELETE: Supprimer un type de matériel
    // ===============================================
    case 'delete':
        require_permission($currentUser, 'referentiel.supprimer');
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID invalide.']);
            exit();
        }

        try {
            // Optionnel: empêcher la suppression si utilisé par du matériel
            $checkUse = $pdo->prepare('SELECT COUNT(*) FROM t_materiel WHERE type = (SELECT type FROM t_type_materiel WHERE id = ?)');
            $checkUse->execute([$id]);
            $useCount = (int)$checkUse->fetchColumn();
            if ($useCount > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Ce type est utilisé par ' . $useCount . ' matériel(s) et ne peut pas être supprimé.']);
                exit();
            }

            $stmt = $pdo->prepare('DELETE FROM t_type_materiel WHERE id = ?');
            $ok = $stmt->execute([$id]);
            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Type supprimé avec succès.']);
            } else {
                $errorInfo = $stmt->errorInfo();
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la suppression du type: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la suppression du type: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la suppression du type.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide.']);
        break;
}
