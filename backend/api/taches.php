<?php
// api_taches.php

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);
require_once __DIR__ . '/../lib/taches.php';
// Il faut au moins un droit lié aux pannes pour utiliser cette API ; le détail est contrôlé par action.
require_any_permission($currentUser, ['tache.voir_toutes', 'tache.voir_siennes', 'tache.creer', 'tache.declarer']);

// On détermine si la requête est de type GET ou POST
$method = $_SERVER['REQUEST_METHOD'];

// On récupère l'action. Pour une requête POST, l'action peut être dans les données POST.
$actions = $_GET['action'] ?? $_POST['actions'] ?? '';

switch ($actions) {
    case 'read':
        try {
            $sql = "SELECT * FROM t_taches";
            $params = [];
            $conditions = [];

            // Sans « voir toutes les pannes », on ne voit que celles dont on est déclarant ou assigné.
            if (!has_permission($currentUser, 'tache.voir_toutes')) {
                require_permission($currentUser, 'tache.voir_siennes');
                $conditions[] = "(declarant_id = ? OR assigne_id = ?)";
                $params[] = $currentUser['id'];
                $params[] = $currentUser['id'];
            }
            if (isset($_GET['assigne_a'])) {
                $conditions[] = "assigne_a = ?";
                $params[] = $_GET['assigne_a'];
            }
            if ($conditions) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
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
        require_any_permission($currentUser, ['tache.creer', 'tache.declarer']);
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
        $motif_attente = isset($_POST['motif_attente']) ? $_POST['motif_attente'] : '';
        $date_relance = isset($_POST['date_relance']) && !empty($_POST['date_relance']) ? $_POST['date_relance'] : null;
        // Date limite : par défaut la date du jour si non renseignée.
        $date_limite = isset($_POST['date_limite']) && !empty($_POST['date_limite']) ? $_POST['date_limite'] : date('Y-m-d');
        $actions = isset($_POST['actions']) ? $_POST['actions'] : '';
        $created_by_login = $currentUser['login'];
        $updated_by_login = $currentUser['login'];
        // Un agent qui peut seulement DÉCLARER (sans « créer une tâche » à assignation libre) ne choisit
        // ni l'assigné ni le statut : sa panne rejoint la file « Service IT », à l'état « À faire ».
        if (!has_permission($currentUser, 'tache.creer')) {
            $file = tache_file_commune($pdo);
            if (!$file) {
                http_response_code(500);
                echo json_encode(['error' => 'File « Service IT » introuvable (migrations non appliquées ?).']);
                exit();
            }
            $assigne_a = $file['nom'];
            $statut = 'À faire';
            $motif_attente = '';
            $date_relance = null;
        }
        // Validation : le titre, le statut, l'assigné à, la date limite et le service
        // concerné sont obligatoires (la priorité conserve son défaut existant).
        if (empty($titre) || empty($priorite) || empty($statut) || empty($assigne_a) || empty($date_limite) || empty($service_concerne)) {
            http_response_code(400);
            echo json_encode(['error' => 'Le titre, la priorité, le statut, l\u2019assigné à, la date limite et le service concerné sont requis.']);
            exit();
        }
        // Statut "En attente" : le motif d'attente et la date de relance sont exigés,
        // pour que la pause soit tracée et relançable (visible par les décideurs).
        if ($statut === 'En attente' && (empty($motif_attente) || empty($date_relance))) {
            http_response_code(400);
            echo json_encode(['error' => 'Pour le statut « En attente », le motif d\'attente et la date de relance sont requis.']);
            exit();
        }

        try {
            // Trace: created_by_login et updated_by_login (si colonnes présentes)
            $sql = "INSERT INTO t_taches (titre, motif_cause, description, priorite, statut, assigne_a, materiel_concerne, service_concerne, motif_attente, date_relance, date_limite, actions, created_by_login, updated_by_login) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);

            $pdo->beginTransaction();
            $result = $stmt->execute([$titre, $motif_cause, $description, $priorite, $statut, $assigne_a, $materiel_concerne, $service_concerne, $motif_attente, $date_relance, $date_limite, $actions, $created_by_login, $updated_by_login]);
            if ($result) {
                $nouvelId = (int) $pdo->lastInsertId('t_taches_id_seq');
                tache_apres_creation($pdo, $nouvelId, $currentUser, 'web');
            }
            $pdo->commit();

            if ($result) {
                // Email best-effort : ne doit jamais faire échouer la réponse de création,
                // même si le SMTP est mal configuré ou indisponible.
                try {
                    notify_task_assigned($pdo, get_parametres($pdo), [
                        'titre' => $titre,
                        'priorite' => $priorite,
                        'assigne_a' => $assigne_a,
                        'service_concerne' => $service_concerne,
                        'date_limite' => $date_limite,
                        'description' => $description,
                    ]);
                } catch (Throwable $e) {
                    error_log('Notification email (création tâche) ignorée: ' . $e->getMessage());
                }

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
        $motif_attente = isset($_POST['motif_attente']) ? $_POST['motif_attente'] : '';
        $date_relance = isset($_POST['date_relance']) && !empty($_POST['date_relance']) ? $_POST['date_relance'] : null;
        // Date limite : par défaut la date du jour si non renseignée.
        $date_limite = isset($_POST['date_limite']) && !empty($_POST['date_limite']) ? $_POST['date_limite'] : date('Y-m-d');
        $actions = isset($_POST['actions']) ? $_POST['actions'] : '';
        $updated_by_login = $currentUser['login']; // login de l'utilisateur courant

        // Validation : le titre, le statut, l'assigné à, la date limite et le service
        // concerné sont obligatoires.
        if (empty($titre) || empty($priorite) || empty($statut) || empty($assigne_a) || empty($date_limite) || empty($service_concerne)) {
            http_response_code(400);
            echo json_encode(['error' => 'Le titre, la priorité, le statut, l\u2019assigné à, la date limite et le service concerné sont requis.']);
            exit();
        }
        // Statut "En attente" : le motif d'attente et la date de relance sont exigés.
        if ($statut === 'En attente' && (empty($motif_attente) || empty($date_relance))) {
            http_response_code(400);
            echo json_encode(['error' => 'Pour le statut « En attente », le motif d\'attente et la date de relance sont requis.']);
            exit();
        }

        try {
            // Mémorise l'assignation précédente pour ne notifier par email qu'en cas de
            // réassignation (et non à chaque simple mise à jour de la même tâche).
            $ancienneStmt = $pdo->prepare("SELECT * FROM t_taches WHERE id = ?");
            $ancienneStmt->execute([$id]);
            $ancienne = $ancienneStmt->fetch();
            if (!$ancienne || !tache_peut_voir($currentUser, $ancienne)) {
                http_response_code(404);
                echo json_encode(['error' => 'Tâche non trouvée.']);
                exit();
            }
            $ancienAssigne = $ancienne['assigne_a'];

            // Droits de modification (contrôlés ici, plus seulement masqués dans l'interface) :
            //  - tache.modifier_toutes : toute tâche ;
            //  - tache.modifier_siennes : les tâches qui nous sont assignées ;
            //  - tache.prendre : prise en charge d'une tâche « À faire » (passage à « En cours »).
            $estProprietaire = $ancienne['assigne_id'] !== null && (int) $ancienne['assigne_id'] === (int) $currentUser['id'];
            $estPriseEnCharge = $ancienne['statut'] === 'À faire' && $statut === 'En cours' && has_permission($currentUser, 'tache.prendre');
            if (!has_permission($currentUser, 'tache.modifier_toutes')
                && !(has_permission($currentUser, 'tache.modifier_siennes') && $estProprietaire)
                && !$estPriseEnCharge) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous ne pouvez modifier que les tâches qui vous sont assignées.']);
                exit();
            }

            $sql = "UPDATE t_taches SET titre = ?, motif_cause = ?, description = ?, priorite = ?, statut = ?, assigne_a = ?, materiel_concerne = ?, service_concerne = ?, motif_attente = ?, date_relance = ?, date_limite = ?, actions = ?, updated_by_login = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);

            $pdo->beginTransaction();
            $result = $stmt->execute([$titre, $motif_cause, $description, $priorite, $statut, $assigne_a, $materiel_concerne, $service_concerne, $motif_attente, $date_relance, $date_limite, $actions, $updated_by_login, $id]);
            if ($result) {
                tache_apres_modification($pdo, $id, $ancienne, $currentUser);
            }
            $pdo->commit();

            if ($result) {
                if ($assigne_a !== '' && $assigne_a !== $ancienAssigne) {
                    try {
                        notify_task_assigned($pdo, get_parametres($pdo), [
                            'titre' => $titre,
                            'priorite' => $priorite,
                            'assigne_a' => $assigne_a,
                            'service_concerne' => $service_concerne,
                            'date_limite' => $date_limite,
                            'description' => $description,
                        ]);
                    } catch (Throwable $e) {
                        error_log('Notification email (réassignation tâche) ignorée: ' . $e->getMessage());
                    }
                }

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
        require_permission($currentUser, 'tache.supprimer');
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
        // Les statistiques d'un autre agent ne sont visibles qu'avec « voir toutes les pannes ».
        if ($userName !== $currentUser['nom'] && !has_permission($currentUser, 'tache.voir_toutes')) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé.']);
            exit();
        }
        $params = get_parametres($pdo);
        $seuilEcheanceProche = (int) $params['seuil_echeance_proche_jours'];
        $fenetreEfficacite = (int) $params['efficacite_fenetre_jours'];

        try {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN statut = 'À faire' THEN 1 ELSE 0 END) as new_tasks,
                    SUM(CASE WHEN statut = 'En cours' THEN 1 ELSE 0 END) as in_progress_tasks,
                    SUM(CASE WHEN statut = 'En attente' THEN 1 ELSE 0 END) as pending_tasks,
                    SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé', 'En attente')
                             AND date_limite IS NOT NULL AND date_limite < CURRENT_DATE
                        THEN 1 ELSE 0 END) as late_tasks,
                    SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé', 'En attente')
                             AND date_limite IS NOT NULL
                             AND date_limite >= CURRENT_DATE
                             AND date_limite <= CURRENT_DATE + (? || ' days')::interval
                        THEN 1 ELSE 0 END) as echeance_proche_tasks,
                    SUM(CASE WHEN statut = 'Terminé' AND date_modification::date = CURRENT_DATE
                        THEN 1 ELSE 0 END) as completed_today
                FROM t_taches
                WHERE assigne_a = ?
            ");
            $stmt->execute([$seuilEcheanceProche, $userName]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Ensure we return 0 if counts are null (no tasks)
            $stats['new_tasks'] = (int) $stats['new_tasks'];
            $stats['in_progress_tasks'] = (int) $stats['in_progress_tasks'];
            $stats['pending_tasks'] = (int) $stats['pending_tasks'];
            $stats['late_tasks'] = (int) $stats['late_tasks'];
            $stats['echeance_proche_tasks'] = (int) $stats['echeance_proche_tasks'];
            $stats['completed_today'] = (int) $stats['completed_today'];

            // Jauge d'efficacité : Terminées / (Terminées + En retard), sur les tâches
            // assignées à l'utilisateur depuis moins de `efficacite_fenetre_jours` jours.
            // Les tâches encore ouvertes non en retard, les tâches Annulées et les tâches
            // délibérément mises "En attente" sont exclues du calcul (ni un échec, ni
            // imputable à l'agent — voir backend/config/parametres.php::compute_efficacite_pct).
            $effStmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN statut = 'Terminé' THEN 1 ELSE 0 END) as resolues,
                    SUM(CASE WHEN statut NOT IN ('Terminé', 'Annulé', 'En attente')
                             AND date_limite IS NOT NULL AND date_limite < CURRENT_DATE
                        THEN 1 ELSE 0 END) as en_retard
                FROM t_taches
                WHERE assigne_a = ?
                  AND date_creation >= NOW() - (? || ' days')::interval
            ");
            $effStmt->execute([$userName, $fenetreEfficacite]);
            $effRow = $effStmt->fetch(PDO::FETCH_ASSOC);
            $efficacitePct = compute_efficacite_pct((int) $effRow['resolues'], (int) $effRow['en_retard']);
            $stats['efficacite_pct'] = $efficacitePct;
            $stats['efficacite_zone'] = efficacite_zone($efficacitePct, $params);

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
