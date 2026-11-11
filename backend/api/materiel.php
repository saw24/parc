<?php
// api_materiel.php

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../lib/legacy.php';
$currentUser = require_auth($pdo);

// On détermine si la requête est de type GET ou POST
$method = $_SERVER['REQUEST_METHOD'];

// On récupère l'action. Pour une requête POST, l'action peut être dans les données POST.
$action = $_GET['action'] ?? $_POST['action'] ?? '';

legacy_migre($action, ['read', 'create', 'update', 'delete', 'historique_affectations']);

switch ($action) {
    case 'read':
        require_permission($currentUser, 'materiel.voir_tous');
        // Ce bloc reste identique
        try {
            $stmt = $pdo->query("SELECT * FROM t_materiel ORDER BY id DESC");
            $materiels = $stmt->fetchAll();
            echo json_encode($materiels);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des données: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des données.']);
        }
        break;

    // ===============================================
    // HISTORIQUE D'AFFECTATION D'UN MATÉRIEL
    // ===============================================
    case 'historique_affectations':
        require_permission($currentUser, 'materiel.voir_tous');
        $materielId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($materielId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de matériel invalide.']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                SELECT id, utilisateur_assigne, service_affecte, date_debut, date_fin, changed_by
                FROM t_materiel_affectations
                WHERE materiel_id = ?
                ORDER BY date_debut DESC
            ");
            $stmt->execute([$materielId]);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération de l\'historique d\'affectation: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération de l\'historique d\'affectation.']);
        }
        break;

    // ===============================================
    // NOUVEAU BLOC POUR LA CRÉATION
    // ===============================================
    case 'create':
        require_permission($currentUser, 'materiel.creer');
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("REQUEST_METHOD: " . $method);

        // On récupère les données du formulaire
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $marque = isset($_POST['marque']) ? $_POST['marque'] : '';
        $modele = isset($_POST['modele']) ? $_POST['modele'] : '';
        $num_serie = isset($_POST['num_serie']) ? $_POST['num_serie'] : '';
        $date_achat = isset($_POST['date_achat']) && !empty($_POST['date_achat']) ? $_POST['date_achat'] : null;
        $date_fin_garantie = !empty($_POST['date_fin_garantie']) ? $_POST['date_fin_garantie'] : null;
        $statut = isset($_POST['statut']) ? $_POST['statut'] : '';
        $service_affecte = isset($_POST['service']) ? $_POST['service'] : '';
        $utilisateur_assigne = isset($_POST['utilisateur']) ? $_POST['utilisateur'] : '';
        $date_derniere_affect = isset($_POST['date_derniere_affect']) && !empty($_POST['date_derniere_affect']) ? $_POST['date_derniere_affect'] : null;
        $created_by_login = $currentUser['login'];
        $updated_by_login = $currentUser['login']; // login de l'utilisateur courant

        // Log the extracted values
        error_log("Extracted values: type=$type, marque=$marque, modele=$modele, num_serie=$num_serie, date_achat=$date_achat, statut=$statut, service_affecte=$service_affecte, utilisateur_assigne=$utilisateur_assigne, created_by_login=$created_by_login, updated_by_login=$updated_by_login");

        // Validation simple (on pourrait en faire une plus complexe)
        if (empty($type) || empty($marque) || empty($statut)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Tous les champs marqués comme requis doivent être remplis.']);
            exit();
        }

        // Un service affecté impose une date d'affectation (confirmée côté formulaire) :
        // contrôle serveur en plus de la confirmation demandée côté client.
        if (!empty($service_affecte) && empty($date_derniere_affect)) {
            http_response_code(400);
            echo json_encode(['error' => 'La date d\'affectation est requise lorsqu\'un service est affecté.']);
            exit();
        }

        try {
            // Requête d'insertion préparée pour la sécurité (évite les injections SQL)
            $sql = "INSERT INTO t_materiel (type, marque, modele, num_serie, date_achat, date_fin_garantie, statut, service_affecte, utilisateur_assigne, date_derniere_affect, created_by_login, updated_by_login) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec les valeurs
            $result = $stmt->execute([$type, $marque, $modele, $num_serie, $date_achat, $date_fin_garantie, $statut, $service_affecte, $utilisateur_assigne, $date_derniere_affect, $created_by_login, $updated_by_login]);

            if ($result) {
                // Historique d'affectation : on enregistre l'affectation initiale si elle existe.
                // On utilise la date d'affectation confirmée par l'utilisateur (si fournie)
                // plutôt que l'heure serveur, pour refléter la date réelle de remise du matériel.
                $newMaterielId = $pdo->lastInsertId();
                if (!empty($utilisateur_assigne) || !empty($service_affecte)) {
                    if ($date_derniere_affect) {
                        $histSql = "INSERT INTO t_materiel_affectations (materiel_id, utilisateur_assigne, service_affecte, date_debut, changed_by) VALUES (?, ?, ?, ?, ?)";
                        $pdo->prepare($histSql)->execute([$newMaterielId, $utilisateur_assigne ?: null, $service_affecte ?: null, $date_derniere_affect, $created_by_login ?: null]);
                    } else {
                        $histSql = "INSERT INTO t_materiel_affectations (materiel_id, utilisateur_assigne, service_affecte, changed_by) VALUES (?, ?, ?, ?)";
                        $pdo->prepare($histSql)->execute([$newMaterielId, $utilisateur_assigne ?: null, $service_affecte ?: null, $created_by_login ?: null]);
                    }
                }

                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Matériel ajouté avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de l\'ajout du matériel: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            // On vérifie si l'erreur est une duplication de numéro de série
            if ($e->getCode() == 23000) {
                echo json_encode(['error' => 'Ce numéro de série existe déjà.']);
            } else {
                error_log('Erreur lors de l\'ajout du matériel: ' . $e->getMessage());
                echo json_encode(['error' => 'Erreur lors de l\'ajout du matériel.']);
            }
        }
        break;

    // ===============================================
    // BLOC POUR LA MISE À JOUR
    // ===============================================
    case 'update':
        require_permission($currentUser, 'materiel.modifier');
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("UPDATE - REQUEST_METHOD: " . $method);

        // On récupère l'ID du matériel à mettre à jour
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'ID de matériel invalide.']);
            exit();
        }

        // On récupère les données du formulaire
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $marque = isset($_POST['marque']) ? $_POST['marque'] : '';
        $modele = isset($_POST['modele']) ? $_POST['modele'] : '';
        $num_serie = isset($_POST['num_serie']) ? $_POST['num_serie'] : '';
        $date_achat = isset($_POST['date_achat']) && !empty($_POST['date_achat']) ? $_POST['date_achat'] : null;
        $date_fin_garantie = !empty($_POST['date_fin_garantie']) ? $_POST['date_fin_garantie'] : null;
        $statut = isset($_POST['statut']) ? $_POST['statut'] : '';
        $service_affecte = isset($_POST['service']) ? $_POST['service'] : '';
        $utilisateur_assigne = isset($_POST['utilisateur']) ? $_POST['utilisateur'] : '';
        $date_derniere_affect = isset($_POST['date_derniere_affect']) && !empty($_POST['date_derniere_affect']) ? $_POST['date_derniere_affect'] : null;
        $updated_by_login = $currentUser['login']; // login de l'utilisateur courant


        // Log the extracted values
        error_log("UPDATE - Extracted values: id=$id, type=$type, marque=$marque, modele=$modele, num_serie=$num_serie, date_achat=$date_achat, statut=$statut, service_affecte=$service_affecte, utilisateur_assigne=$utilisateur_assigne,  updated_by_login=$updated_by_login");

        // Validation simple
        if (empty($type) || empty($statut)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Tous les champs marqués comme requis doivent être remplis.']);
            exit();
        }

        // Un service affecté impose une date d'affectation (confirmée côté formulaire) :
        // contrôle serveur en plus de la confirmation demandée côté client.
        if (!empty($service_affecte) && empty($date_derniere_affect)) {
            http_response_code(400);
            echo json_encode(['error' => 'La date d\'affectation est requise lorsqu\'un service est affecté.']);
            exit();
        }

        try {
            // Vérifier si le numéro de série existe déjà pour un autre matériel
            if (!empty($num_serie)) {
                $checkSql = "SELECT id FROM t_materiel WHERE num_serie = ? AND id != ?";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute([$num_serie, $id]);

                if ($checkStmt->rowCount() > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Ce numéro de série est déjà utilisé par un autre matériel.']);
                    exit();
                }
            }

            // Affectation actuelle, avant modification (pour détecter un changement et l'historiser)
            $avantStmt = $pdo->prepare("SELECT utilisateur_assigne, service_affecte FROM t_materiel WHERE id = ?");
            $avantStmt->execute([$id]);
            $avant = $avantStmt->fetch();

            // Requête de mise à jour préparée
            $sql = "UPDATE t_materiel SET type = ?, marque = ?, modele = ?, num_serie = ?, date_achat = ?, date_fin_garantie = ?, statut = ?, service_affecte = ?, utilisateur_assigne = ?, date_derniere_affect = ?, updated_by_login = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec les valeurs
            $result = $stmt->execute([$type, $marque, $modele, $num_serie, $date_achat, $date_fin_garantie, $statut, $service_affecte, $utilisateur_assigne, $date_derniere_affect, $updated_by_login, $id]);

            if ($result) {
                // Historique d'affectation : si l'utilisateur ou le service assigné a changé,
                // on clôture l'affectation en cours et on en ouvre une nouvelle. On utilise la
                // date d'affectation confirmée par l'utilisateur (si fournie) plutôt que l'heure
                // serveur, pour refléter la date réelle de remise du matériel.
                $avantUtilisateur = $avant ? ($avant['utilisateur_assigne'] ?? '') : '';
                $avantService = $avant ? ($avant['service_affecte'] ?? '') : '';
                if ($avant && ($avantUtilisateur !== $utilisateur_assigne || $avantService !== $service_affecte)) {
                    $pdo->prepare("UPDATE t_materiel_affectations SET date_fin = NOW() WHERE materiel_id = ? AND date_fin IS NULL")
                        ->execute([$id]);

                    if (!empty($utilisateur_assigne) || !empty($service_affecte)) {
                        if ($date_derniere_affect) {
                            $histSql = "INSERT INTO t_materiel_affectations (materiel_id, utilisateur_assigne, service_affecte, date_debut, changed_by) VALUES (?, ?, ?, ?, ?)";
                            $pdo->prepare($histSql)->execute([$id, $utilisateur_assigne ?: null, $service_affecte ?: null, $date_derniere_affect, $updated_by_login ?: null]);
                        } else {
                            $histSql = "INSERT INTO t_materiel_affectations (materiel_id, utilisateur_assigne, service_affecte, changed_by) VALUES (?, ?, ?, ?)";
                            $pdo->prepare($histSql)->execute([$id, $utilisateur_assigne ?: null, $service_affecte ?: null, $updated_by_login ?: null]);
                        }
                    }
                }

                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Matériel mis à jour avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("UPDATE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour du matériel: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("UPDATE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la mise à jour du matériel: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la mise à jour du matériel.']);
        }
        break;

    // ===============================================
    // BLOC POUR LA SUPPRESSION
    // ===============================================
    case 'delete':
        require_permission($currentUser, 'materiel.supprimer');
        // On s'assure que la requête est bien de type POST
        if ($method !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        // Debug: Log all received data
        error_log("DELETE - REQUEST_METHOD: " . $method);

        // On récupère l'ID du matériel à supprimer
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'ID de matériel invalide.']);
            exit();
        }

        try {
            // Vérifier si le matériel existe
            $checkSql = "SELECT id FROM t_materiel WHERE id = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$id]);

            if ($checkStmt->rowCount() === 0) {
                http_response_code(404); // Not Found
                echo json_encode(['error' => 'Matériel non trouvé.']);
                exit();
            }

            // Requête de suppression préparée
            $sql = "DELETE FROM t_materiel WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec l'ID
            $result = $stmt->execute([$id]);

            if ($result) {
                // On renvoie une réponse de succès
                echo json_encode(['success' => true, 'message' => 'Matériel supprimé avec succès.']);
            } else {
                // Log the error information
                $errorInfo = $stmt->errorInfo();
                error_log("DELETE - SQL Error: " . print_r($errorInfo, true));

                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la suppression du matériel: ' . $errorInfo[2]]);
            }
        } catch (PDOException $e) {
            // Log the exception
            error_log("DELETE - PDO Exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");

            http_response_code(500);
            error_log('Erreur lors de la suppression du matériel: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la suppression du matériel.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide.']);
        break;
}
