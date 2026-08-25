<?php
// api_dashboard.php - API pour les données du tableau de bord

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);

// On détermine si la requête est de type GET ou POST
$method = $_SERVER['REQUEST_METHOD'];

// On récupère l'action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'stats':
        try {
            // Statistiques du matériel
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM t_materiel");
            $totalMateriel = $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) as en_panne FROM t_materiel WHERE statut = 'En panne'");
            $materielEnPanne = $stmt->fetchColumn();

            // Statistiques des tâches
            $stmt = $pdo->query("SELECT COUNT(*) as en_cours FROM t_taches WHERE statut = 'En cours'");
            $tachesEnCours = $stmt->fetchColumn();

            // Tâches déclarées aujourd'hui
            $stmt = $pdo->query("SELECT COUNT(*) as crees_aujourdhui FROM t_taches WHERE date_creation::date = CURRENT_DATE");
            $tachesCreesAujourdhui = $stmt->fetchColumn();

            // Tâches terminées aujourd'hui
            $stmt = $pdo->query("SELECT COUNT(*) as terminees_aujourdhui FROM t_taches WHERE statut = 'Terminé' AND date_modification::date = CURRENT_DATE");
            $tachesTermineesAujourdhui = $stmt->fetchColumn();

            // Statistiques des taches
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM t_taches");
            $totalTaches = $stmt->fetchColumn();

            // Tâches en retard : date limite dépassée, pas encore terminées/annulées
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM t_taches
                WHERE date_limite IS NOT NULL
                  AND date_limite < CURRENT_DATE
                  AND statut NOT IN ('Terminé', 'Annulé')
            ");
            $tachesEnRetard = $stmt->fetchColumn();

            // Tâches à échéance proche (dans les 3 prochains jours inclus), pas terminées/annulées
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM t_taches
                WHERE date_limite IS NOT NULL
                  AND date_limite >= CURRENT_DATE
                  AND date_limite <= CURRENT_DATE + INTERVAL '3 days'
                  AND statut NOT IN ('Terminé', 'Annulé')
            ");
            $tachesEcheanceProche = $stmt->fetchColumn();

            // Matériel en panne sans aucune tâche de réparation actuellement ouverte
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM t_materiel m
                WHERE m.statut = 'En panne'
                  AND NOT EXISTS (
                      SELECT 1 FROM t_taches t
                      WHERE t.statut NOT IN ('Terminé', 'Annulé')
                        AND (t.materiel_concerne LIKE '%' || m.num_serie || '%'
                             OR t.materiel_concerne LIKE '%' || m.marque || '%' || m.modele || '%')
                  )
            ");
            $materielPannesSansTache = $stmt->fetchColumn();

            echo json_encode([
                'materiel' => [
                    'total' => $totalMateriel,
                    'en_panne' => $materielEnPanne,
                    'pannes_sans_tache' => $materielPannesSansTache
                ],
                'taches' => [
                    'en_cours' => $tachesEnCours,
                    'total' => $totalTaches,
                    'crees_aujourdhui' => $tachesCreesAujourdhui,
                    'terminees_aujourdhui' => $tachesTermineesAujourdhui,
                    'en_retard' => $tachesEnRetard,
                    'echeance_proche' => $tachesEcheanceProche
                ],
                /*'services' => [
                    'total' => $totalServices
                ]*/
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des statistiques: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des statistiques.']);
        }
        break;

    case 'activites':
        try {
            // Récupérer uniquement les tâches récentes (dernières 10)
            $sql = "
                            SELECT
                                'Tâche' as type,
                                titre as description,
                                service_concerne,
                                statut,
                                date_creation as date,
                                CASE
                                    WHEN statut = 'Terminé' THEN 'success'
                                    WHEN statut = 'En cours' THEN 'warning'
                                    WHEN statut = 'Annulé' THEN 'danger'
                                    ELSE 'info'
                                END as status_class
                            FROM t_taches
                            ORDER BY date_creation DESC
                            LIMIT 10
                        ";

            $stmt = $pdo->query($sql);
            $activites = $stmt->fetchAll();

            echo json_encode($activites);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des activités: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des activités.']);
        }
        break;

    case 'pannes_recurrentes':
        try {
            // Top 5 du matériel avec le plus de tâches déclarées (toutes tâches confondues,
            // plus de filtre par mot-clé ni par statut).
            $sql = "
                SELECT
                    m.type,
                    m.marque,
                    m.modele,
                    COUNT(t.id) as nb_pannes,
                    STRING_AGG(DISTINCT t.titre, '; ') as types_pannes
                FROM t_materiel m
                INNER JOIN t_taches t ON (t.materiel_concerne LIKE CONCAT('%', m.num_serie, '%')
                                         OR t.materiel_concerne LIKE CONCAT('%', m.marque, '%', m.modele, '%'))
                GROUP BY m.type, m.marque, m.modele
                ORDER BY nb_pannes DESC
                LIMIT 5
            ";

            $stmt = $pdo->query($sql);
            $pannes = $stmt->fetchAll();

            echo json_encode($pannes);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des pannes récurrentes: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des pannes récurrentes.']);
        }
        break;

    case 'services_demandeurs':
        try {
            // Top 5 des services qui demandent le plus de tâches
            $sql = "
                SELECT 
                    COALESCE(service_concerne, 'Non spécifié') as service,
                    COUNT(*) as nb_taches,
                    COUNT(CASE WHEN statut = 'Terminé' THEN 1 END) as taches_terminees,
                    COUNT(CASE WHEN statut = 'En cours' THEN 1 END) as taches_en_cours
                FROM t_taches
                WHERE date_creation >= NOW() - INTERVAL '6 months'
                GROUP BY service_concerne
                ORDER BY nb_taches DESC
                LIMIT 5
            ";

            $stmt = $pdo->query($sql);
            $services = $stmt->fetchAll();

            echo json_encode($services);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des services demandeurs: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des services demandeurs.']);
        }
        break;

    case 'pannes_par_mois':
        try {
            // Nombre de tâches déclarées par mois, sur les 12 derniers mois
            // (toutes les tâches sont comptées, plus de filtre par mot-clé).
            $sql = "
                SELECT
                    TO_CHAR(t.date_creation, 'YYYY-MM') as mois,
                    TRIM(TO_CHAR(t.date_creation, 'TMMonth')) as nom_mois,
                    EXTRACT(YEAR FROM t.date_creation)::int as annee,
                    COUNT(*) as nb_pannes
                FROM t_taches t
                WHERE t.date_creation >= NOW() - INTERVAL '12 months'
                GROUP BY TO_CHAR(t.date_creation, 'YYYY-MM'), TRIM(TO_CHAR(t.date_creation, 'TMMonth')), EXTRACT(YEAR FROM t.date_creation)
                ORDER BY mois ASC
            ";

            $stmt = $pdo->query($sql);
            $pannes_mois = $stmt->fetchAll();

            echo json_encode($pannes_mois);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des pannes par mois: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des pannes par mois.']);
        }
        break;

    case 'parc_lifecycle':
        try {
            // Seuil de renouvellement recommandé : matériel de plus de 5 ans.
            $seuilRenouvellementAnnees = 5;

            // Âge moyen du parc (uniquement le matériel avec une date d'achat connue)
            $stmt = $pdo->query("
                SELECT ROUND(AVG(EXTRACT(EPOCH FROM (NOW() - date_achat)) / (365.25 * 86400))::numeric, 1) as age_moyen
                FROM t_materiel
                WHERE date_achat IS NOT NULL
            ");
            $ageMoyen = $stmt->fetchColumn();

            // Répartition du parc par type
            $stmt = $pdo->query("
                SELECT type, COUNT(*) as total
                FROM t_materiel
                GROUP BY type
                ORDER BY total DESC
            ");
            $repartitionParType = $stmt->fetchAll();

            // Matériel à renouveler (plus vieux que le seuil)
            $stmt = $pdo->prepare("
                SELECT id, type, marque, modele, num_serie, date_achat,
                       ROUND(EXTRACT(EPOCH FROM (NOW() - date_achat)) / (365.25 * 86400)) as age_annees
                FROM t_materiel
                WHERE date_achat IS NOT NULL
                  AND date_achat < NOW() - (? || ' years')::interval
                ORDER BY date_achat ASC
            ");
            $stmt->execute([$seuilRenouvellementAnnees]);
            $materielARenouveler = $stmt->fetchAll();

            echo json_encode([
                'seuil_renouvellement_annees' => $seuilRenouvellementAnnees,
                'age_moyen_annees' => $ageMoyen !== null ? (float) $ageMoyen : null,
                'repartition_par_type' => $repartitionParType,
                'materiel_a_renouveler' => $materielARenouveler
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération du cycle de vie du parc: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération du cycle de vie du parc.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide.']);
        break;
}
