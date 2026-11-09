<?php
// Inclusion du fichier de connexion à la base de données
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);

// Activation du rapport d'erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

// Récupération de la méthode HTTP utilisée
$method = $_SERVER['REQUEST_METHOD'];

// Récupération de l'action demandée
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Traitement en fonction de l'action demandée
switch ($action) {
    // ===============================================
    // BLOC POUR LA LECTURE
    // ===============================================
    case 'read':
        require_permission($currentUser, 'referentiel.voir');
        try {
            // Requête pour récupérer tous les services
            $sql = "SELECT * FROM t_services ORDER BY nom ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            // Récupération des résultats
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Envoi des résultats au format JSON
            header('Content-Type: application/json');
            echo json_encode($services);
        } catch (PDOException $e) {
            // En cas d'erreur, on renvoie un message d'erreur
            http_response_code(500);
            error_log('Erreur lors de la récupération des services: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des services.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA CRÉATION
    // ===============================================
    case 'create':
        require_permission($currentUser, 'referentiel.gerer');
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("CREATE - REQUEST_METHOD: " . $method);

        // On récupère les données du formulaire
        $nom = isset($_POST['nom']) ? $_POST['nom'] : '';
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        $created_by_login = $currentUser['login']; // login de l'utilisateur courant

        // Log the extracted values
        error_log("CREATE - Extracted values: nom=$nom, description=$description, created_by_login=$created_by_login");

        // Validation simple
        if (empty($nom)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Le nom du service est requis.']);
            exit();
        }

        try {
            // Vérifier si le nom existe déjà
            $checkSql = "SELECT id FROM t_services WHERE nom = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$nom]);

            if ($checkStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Un service avec ce nom existe déjà.']);
                exit();
            }

            // Requête d'insertion préparée
            $sql = "INSERT INTO t_services (nom, description, created_by_login) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec les valeurs
            $result = $stmt->execute([$nom, $description, $created_by_login]);

            if ($result) {
                // On récupère l'ID du service créé
                $serviceId = $pdo->lastInsertId();

                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Service créé avec succès.', 'id' => $serviceId]);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("CREATE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la création du service: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("CREATE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la création du service: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la création du service.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA MISE À JOUR
    // ===============================================
    case 'update':
        require_permission($currentUser, 'referentiel.gerer');
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("UPDATE - REQUEST_METHOD: " . $method);

        // On récupère l'ID du service à mettre à jour
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'ID de service invalide.']);
            exit();
        }

        // On récupère les données du formulaire
        $nom = isset($_POST['nom']) ? $_POST['nom'] : '';
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        $updated_by_login = $currentUser['login']; // login de l'utilisateur courant

        // Log the extracted values
        error_log("UPDATE - Extracted values: id=$id, nom=$nom, description=$description, updated_by_login=$updated_by_login");

        // Validation simple
        if (empty($nom)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Le nom du service est requis.']);
            exit();
        }

        try {
            // Vérifier si le nom existe déjà pour un autre service
            $checkSql = "SELECT id FROM t_services WHERE nom = ? AND id != ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$nom, $id]);

            if ($checkStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Un service avec ce nom existe déjà.']);
                exit();
            }

            // Requête de mise à jour préparée
            $sql = "UPDATE t_services SET nom = ?, description = ?, updated_by_login = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec les valeurs
            $result = $stmt->execute([$nom, $description, $updated_by_login, $id]);

            if ($result) {
                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Service mis à jour avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("UPDATE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour du service: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("UPDATE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la mise à jour du service: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la mise à jour du service.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA SUPPRESSION
    // ===============================================
    case 'delete':
        require_permission($currentUser, 'referentiel.supprimer');
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("DELETE - REQUEST_METHOD: " . $method);

        // On récupère l'ID du service à supprimer
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'ID de service invalide.']);
            exit();
        }

        try {
            // Vérifier si le service existe
            $checkSql = "SELECT id FROM t_services WHERE id = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$id]);

            if ($checkStmt->rowCount() === 0) {
                http_response_code(404); // Not Found
                echo json_encode(['error' => 'Service non trouvé.']);
                exit();
            }

            // Vérifier si des matériels sont associés à ce service
            $checkMaterielSql = "SELECT COUNT(*) FROM t_materiel WHERE service_affecte = (SELECT nom FROM t_services WHERE id = ?)";
            $checkMaterielStmt = $pdo->prepare($checkMaterielSql);
            $checkMaterielStmt->execute([$id]);
            $materielCount = $checkMaterielStmt->fetchColumn();

            if ($materielCount > 0) {
                http_response_code(400); // Bad Request
                echo json_encode(['error' => 'Ce service ne peut pas être supprimé car il est associé à ' . $materielCount . ' matériel(s).']);
                exit();
            }

            // Requête de suppression préparée
            $sql = "DELETE FROM t_services WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec l'ID
            $result = $stmt->execute([$id]);

            if ($result) {
                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Service supprimé avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("DELETE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la suppression du service: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("DELETE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la suppression du service: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la suppression du service.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue.']);
        break;
}
