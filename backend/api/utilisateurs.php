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
        try {
            // Le mot de passe (haché) n'est jamais renvoyé au client, même en interne :
            // on sélectionne explicitement les colonnes plutôt que SELECT *.
            $userColumns = "id, nom, login, role, date_creation, updated_by_login, created_by_login, date_modification";

            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT $userColumns FROM t_utilisateurs WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Requête pour récupérer tous les utilisateurs
                $sql = "SELECT $userColumns FROM t_utilisateurs ORDER BY nom ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                // Récupération des résultats
                $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Envoi des résultats au format JSON
            header('Content-Type: application/json');
            echo json_encode($utilisateurs);
        } catch (PDOException $e) {
            // En cas d'erreur, on renvoie un message d'erreur
            http_response_code(500);
            error_log('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des utilisateurs.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA CRÉATION
    // ===============================================
    case 'create':
        // Gestion des utilisateurs réservée aux administrateurs
        require_role($currentUser, ['Administrateur']);

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
        $login = isset($_POST['login']) ? $_POST['login'] : '';
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        $mot_de_passe = isset($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : '';
        $created_by_login = isset($_POST['created_by_login']) ? $_POST['created_by_login'] : $_SESSION['login']; // login de l'utilisateur courant

        // Store all fields and their values for validation error messages
        $fields = [
            'nom' => $nom,
            'login' => $login,
            'role' => $role,
            'mot_de_passe' => $mot_de_passe,
            'created_by_login' => $created_by_login
        ];

        // Log the extracted values
        error_log("CREATE - Extracted values: nom=$nom, login=$login, role=$role, mot_de_passe=[REDACTED], created_by_login=$created_by_login");

        // Validation simple
        if (empty($nom) || empty($login) || empty($role) || empty($mot_de_passe)) {
            http_response_code(400); // Bad Request
            $emptyFields = [];
            if (empty($nom)) $emptyFields[] = 'nom';
            if (empty($login)) $emptyFields[] = 'login';
            if (empty($role)) $emptyFields[] = 'role';
            if (empty($mot_de_passe)) $emptyFields[] = 'mot_de_passe';

            echo json_encode([
                'error' => 'Tous les champs sont requis.',
                'empty_fields' => $emptyFields,
                'fields' => $fields
            ]);
            exit();
        }

        try {
            // Vérifier si l'login existe déjà
            $checkSql = "SELECT id FROM t_utilisateurs WHERE login = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$login]);

            if ($checkStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Un utilisateur avec cet login existe déjà.']);
                exit();
            }

            // Hachage du mot de passe
            $hashed_password = password_hash($mot_de_passe, PASSWORD_DEFAULT);

            // Requête d'insertion préparée
            $sql = "INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe, created_by_login) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec les valeurs
            $result = $stmt->execute([$nom, $login, $role, $hashed_password, $created_by_login]);

            if ($result) {
                // On récupère l'ID de l'utilisateur créé
                $userId = $pdo->lastInsertId();

                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Utilisateur créé avec succès.', 'id' => $userId]);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("CREATE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la création de l\'utilisateur: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("CREATE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la création de l\'utilisateur: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la création de l\'utilisateur.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA MISE À JOUR
    // ===============================================
    case 'update':
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("UPDATE - REQUEST_METHOD: " . $method);

        // On récupère les données du formulaire
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $nom = isset($_POST['nom']) ? $_POST['nom'] : '';
        $login = isset($_POST['login']) ? $_POST['login'] : '';
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        $mot_de_passe = isset($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : '';
        $updated_by_login = isset($_POST['updated_by_login']) ? $_POST['updated_by_login'] : $_SESSION['login']; // login de l'utilisateur courant

        // Gestion des utilisateurs réservée aux administrateurs, à une exception près :
        // un utilisateur peut modifier SON PROPRE compte (ex: changer son mot de passe).
        $isSelfEdit = ($currentUser['role'] !== 'Administrateur') && (intval($id) === intval($currentUser['id']));
        if ($currentUser['role'] !== 'Administrateur' && !$isSelfEdit) {
            require_role($currentUser, ['Administrateur']); // renvoie 403 et arrête l'exécution
        }
        if ($isSelfEdit) {
            // Un utilisateur non-administrateur ne peut pas s'auto-attribuer un autre
            // rôle via ce formulaire (empêche une élévation de privilèges).
            $role = $currentUser['role'];
        }

        // Store all fields and their values for validation error messages
        $fields = [
            'id' => $id,
            'nom' => $nom,
            'login' => $login,
            'role' => $role,
            'mot_de_passe' => $mot_de_passe
        ];

        // Log the extracted values
        error_log("UPDATE - Extracted values: id=$id, nom=$nom, login=$login, role=$role, mot_de_passe=[REDACTED], updated_by_login=$updated_by_login");

        // Validation simple
        if (empty($id) || empty($nom) || empty($login) || empty($role)) {
            http_response_code(400); // Bad Request
            $emptyFields = [];
            if (empty($id)) $emptyFields[] = 'id';
            if (empty($nom)) $emptyFields[] = 'nom';
            if (empty($login)) $emptyFields[] = 'login';
            if (empty($role)) $emptyFields[] = 'role';

            echo json_encode([
                'error' => 'Les champs nom, login et rôle sont requis.',
                'empty_fields' => $emptyFields,
                'fields' => $fields
            ]);
            exit();
        }

        try {
            // Vérifier si l'login existe déjà pour un autre utilisateur
            $checkSql = "SELECT id FROM t_utilisateurs WHERE login = ? AND id != ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$login, $id]);

            if ($checkStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Un utilisateur avec cet login existe déjà.']);
                exit();
            }

            if (!empty($mot_de_passe)) {
                // Hachage du nouveau mot de passe
                $hashed_password = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                $sql = "UPDATE t_utilisateurs SET nom = ?, login = ?, role = ?, mot_de_passe = ?, updated_by_login = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([$nom, $login, $role, $hashed_password, $updated_by_login, $id]);
            } else {
                // Mise à jour sans changer le mot de passe
                $sql = "UPDATE t_utilisateurs SET nom = ?, login = ?, role = ?, updated_by_login = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([$nom, $login, $role, $updated_by_login, $id]);
            }

            if ($result) {
                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Utilisateur mis à jour avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("UPDATE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour de l\'utilisateur: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("UPDATE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la mise à jour de l\'utilisateur: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la mise à jour de l\'utilisateur.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA SUPPRESSION
    // ===============================================
    case 'delete':
        // Gestion des utilisateurs réservée aux administrateurs
        require_role($currentUser, ['Administrateur']);

        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("DELETE - REQUEST_METHOD: " . $method);

        // On récupère l'ID de l'utilisateur à supprimer
        $id = isset($_POST['id']) ? $_POST['id'] : '';

        // Log the extracted values
        error_log("DELETE - Extracted values: id=$id");

        // Validation simple
        if (empty($id)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'L\'ID de l\'utilisateur est requis.']);
            exit();
        }

        try {
            // Requête de suppression préparée
            $sql = "DELETE FROM t_utilisateurs WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec l'ID
            $result = $stmt->execute([$id]);

            if ($result) {
                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("DELETE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la suppression de l\'utilisateur: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("DELETE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la suppression de l\'utilisateur: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la suppression de l\'utilisateur.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue.']);
        break;
}
