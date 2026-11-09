<?php
// Inclusion du fichier de connexion à la base de données
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);

/** Le rôle doit exister dans t_roles (empêche d'inscrire un rôle arbitraire envoyé par le client). */
function role_existe(PDO $pdo, string $role): bool
{
    $st = $pdo->prepare("SELECT 1 FROM t_roles WHERE nom = ?");
    $st->execute([$role]);
    return (bool) $st->fetchColumn();
}

/** Le compte technique « Service IT » (file commune) n'est ni modifiable ni supprimable ici. */
function est_file_commune_id(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare("SELECT est_file_commune FROM t_utilisateurs WHERE id = ?");
    $st->execute([$id]);
    return (bool) $st->fetchColumn();
}

/** Nombre d'administrateurs, hors l'utilisateur $exclureId (pour ne jamais laisser l'app sans admin). */
function compter_autres_admins(PDO $pdo, int $exclureId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_utilisateurs WHERE role = 'Administrateur' AND id <> ?");
    $stmt->execute([$exclureId]);
    return (int) $stmt->fetchColumn();
}

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
        // Lister les utilisateurs est accessible aux administrateurs et techniciens
        // (pour la sélection de l'assigné sur la fiche de tâches et matériel).
        // Un utilisateur non-admin ne peut lire que sa propre fiche détaillée avec ?id=...
        if (!isset($_GET['id']) || intval($_GET['id']) !== intval($currentUser['id'])) {
            require_permission($currentUser, 'utilisateur.lister');
        }

        try {
            // Le mot de passe (haché) n'est jamais renvoyé au client, même en interne :
            // on sélectionne explicitement les colonnes plutôt que SELECT *.
            $userColumns = "u.id, u.nom, u.login, u.email, u.role, u.date_creation, u.updated_by_login, u.created_by_login, u.date_modification, u.telephone, u.service_id, u.actif, u.connexion_autorisee, u.est_file_commune";

            // Jauge d'efficacité (Terminées / (Terminées + En retard) sur la fenêtre
            // configurée) : calculée uniquement pour les administrateurs (voir
            // isAdmin plus bas) — un utilisateur voit la sienne via son propre tableau
            // de bord (taches.php::user_task_stats), jamais celle des autres ici.
            $isAdmin = has_permission($currentUser, 'utilisateur.gerer');
            $effJoin = '';
            $effColumns = '';
            $effParams = [];
            if ($isAdmin) {
                $params = get_parametres($pdo);
                $fenetreEfficacite = (int) $params['efficacite_fenetre_jours'];
                $effColumns = ", COALESCE(eff.resolues, 0) as eff_resolues, COALESCE(eff.en_retard, 0) as eff_en_retard";
                $effJoin = "
                    LEFT JOIN (
                        SELECT
                            assigne_a,
                            SUM(CASE WHEN statut = 'Terminé' THEN 1 ELSE 0 END) as resolues,
                            SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé', 'En attente')
                                     AND date_limite IS NOT NULL AND date_limite < CURRENT_DATE
                                THEN 1 ELSE 0 END) as en_retard
                        FROM t_taches
                        WHERE date_creation >= NOW() - (? || ' days')::interval
                        GROUP BY assigne_a
                    ) eff ON eff.assigne_a = u.nom
                ";
                $effParams = [$fenetreEfficacite];
            }

            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT $userColumns $effColumns FROM t_utilisateurs u $effJoin WHERE u.id = ?");
                $stmt->execute(array_merge($effParams, [$_GET['id']]));
                $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $sql = "SELECT $userColumns $effColumns FROM t_utilisateurs u $effJoin ORDER BY u.nom ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($effParams);
                $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($isAdmin) {
                foreach ($utilisateurs as &$u) {
                    $pct = compute_efficacite_pct((int) $u['eff_resolues'], (int) $u['eff_en_retard']);
                    $u['efficacite_pct'] = $pct;
                    $u['efficacite_zone'] = efficacite_zone($pct, $params);
                    unset($u['eff_resolues'], $u['eff_en_retard']);
                }
                unset($u);
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
        require_permission($currentUser, 'utilisateur.gerer');

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
        $email = isset($_POST['email']) ? $_POST['email'] : '';
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        $mot_de_passe = isset($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : '';
        $created_by_login = $currentUser['login']; // login de l'utilisateur courant

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

        if (!role_existe($pdo, (string) $role)) {
            http_response_code(400);
            echo json_encode(['error' => 'Rôle invalide.']);
            exit();
        }

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
            $sql = "INSERT INTO t_utilisateurs (nom, login, email, role, mot_de_passe, created_by_login) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);

            // Exécution de la requête avec les valeurs
            $result = $stmt->execute([$nom, $login, $email, $role, $hashed_password, $created_by_login]);

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
        $email = isset($_POST['email']) ? $_POST['email'] : '';
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        $mot_de_passe = isset($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : '';
        $updated_by_login = $currentUser['login']; // login de l'utilisateur courant

        // Gestion des utilisateurs réservée aux administrateurs, à une exception près :
        // un utilisateur peut modifier SON PROPRE compte (ex: changer son mot de passe).
        $isSelfEdit = !has_permission($currentUser, 'utilisateur.gerer') && (intval($id) === intval($currentUser['id']));
        if (!$isSelfEdit) {
            require_permission($currentUser, 'utilisateur.gerer'); // renvoie 403 et arrête l'exécution
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

        if (!role_existe($pdo, (string) $role)) {
            http_response_code(400);
            echo json_encode(['error' => 'Rôle invalide.']);
            exit();
        }

        if (intval($id) > 0 && est_file_commune_id($pdo, intval($id))) {
            http_response_code(400);
            echo json_encode(['error' => 'Le compte « Service IT » (file commune) ne peut pas être modifié.']);
            exit();
        }
        // On ne retire pas le rôle du dernier administrateur.
        if (intval($id) > 0 && $role !== 'Administrateur') {
            $roleActuel = $pdo->prepare("SELECT role FROM t_utilisateurs WHERE id = ?");
            $roleActuel->execute([intval($id)]);
            if ($roleActuel->fetchColumn() === 'Administrateur' && compter_autres_admins($pdo, intval($id)) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Impossible de retirer le rôle du dernier administrateur.']);
                exit();
            }
        }

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
                $sql = "UPDATE t_utilisateurs SET nom = ?, login = ?, email = ?, role = ?, mot_de_passe = ?, updated_by_login = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([$nom, $login, $email, $role, $hashed_password, $updated_by_login, $id]);
            } else {
                // Mise à jour sans changer le mot de passe
                $sql = "UPDATE t_utilisateurs SET nom = ?, login = ?, email = ?, role = ?, updated_by_login = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([$nom, $login, $email, $role, $updated_by_login, $id]);
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
        require_permission($currentUser, 'utilisateur.gerer');

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

        if (intval($id) === intval($currentUser['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Vous ne pouvez pas supprimer votre propre compte.']);
            exit();
        }
        if (est_file_commune_id($pdo, intval($id))) {
            http_response_code(400);
            echo json_encode(['error' => 'Le compte « Service IT » (file commune) ne peut pas être supprimé.']);
            exit();
        }

        try {
            $roleCible = $pdo->prepare("SELECT role FROM t_utilisateurs WHERE id = ?");
            $roleCible->execute([intval($id)]);
            if ($roleCible->fetchColumn() === 'Administrateur' && compter_autres_admins($pdo, intval($id)) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Impossible de supprimer le dernier administrateur.']);
                exit();
            }

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
