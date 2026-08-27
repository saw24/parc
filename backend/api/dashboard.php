<?php
// api_dashboard.php - API pour les données du tableau de bord

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);

// On détermine si la requête est de type GET ou POST
$method = $_SERVER['REQUEST_METHOD'];

// On récupère l'action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Période du filtre dashboard (?debut=YYYY-MM-DD&fin=YYYY-MM-DD), appliquée aux
 * indicateurs et graphiques portant sur les tâches (voir le frontend : dashboardPeriode).
 * Par défaut, année civile en cours. Volontairement PAS appliquée aux indicateurs d'état
 * du parc matériel (inventaire courant, pas un flux d'évènements dans le temps) ni au
 * widget personnel "Mes tâches du jour" (liste d'action "maintenant", pas un rapport).
 */
function get_periode_dashboard(): array
{
    $anneeEnCours = date('Y');
    $debut = $_GET['debut'] ?? "$anneeEnCours-01-01";
    $fin = $_GET['fin'] ?? "$anneeEnCours-12-31";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) {
        $debut = "$anneeEnCours-01-01";
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
        $fin = "$anneeEnCours-12-31";
    }
    return [$debut, $fin];
}

switch ($action) {
    case 'stats':
        try {
            $params = get_parametres($pdo);
            $seuilEcheanceProche = (int) $params['seuil_echeance_proche_jours'];
            $seuilNonPriseEnCharge = (int) $params['seuil_non_prise_en_charge_jours'];
            // Filtre période (?debut=&fin=), appliqué aux tâches — pas au matériel (état
            // courant de l'inventaire, pas un flux d'évènements) ni aux compteurs "Aujourd'hui"
            // (déjà une fenêtre fixe d'1 jour, indépendante de la période choisie).
            [$periodeDebut, $periodeFin] = get_periode_dashboard();

            // Statistiques du matériel (état courant : non filtré par période)
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM t_materiel");
            $totalMateriel = $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) as en_panne FROM t_materiel WHERE statut = 'En panne'");
            $materielEnPanne = $stmt->fetchColumn();

            // Statistiques des tâches (bornées à la période sélectionnée, sur date_creation)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as en_cours FROM t_taches
                WHERE statut = 'En cours' AND date_creation::date BETWEEN ? AND ?
            ");
            $stmt->execute([$periodeDebut, $periodeFin]);
            $tachesEnCours = $stmt->fetchColumn();

            // Tâches "En attente" (pause volontaire, avec motif/date de relance), parmi
            // celles déclarées sur la période — KPI dédié aux décideurs.
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM t_taches
                WHERE statut = 'En attente' AND date_creation::date BETWEEN ? AND ?
            ");
            $stmt->execute([$periodeDebut, $periodeFin]);
            $tachesEnAttente = $stmt->fetchColumn();

            // Tâches déclarées aujourd'hui (toujours "aujourd'hui", indépendant de la période)
            $stmt = $pdo->query("SELECT COUNT(*) as crees_aujourdhui FROM t_taches WHERE date_creation::date = CURRENT_DATE");
            $tachesCreesAujourdhui = $stmt->fetchColumn();

            // Tâches terminées aujourd'hui (idem, toujours "aujourd'hui")
            $stmt = $pdo->query("SELECT COUNT(*) as terminees_aujourdhui FROM t_taches WHERE statut = 'Terminé' AND date_modification::date = CURRENT_DATE");
            $tachesTermineesAujourdhui = $stmt->fetchColumn();

            // Total des tâches déclarées sur la période
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM t_taches WHERE date_creation::date BETWEEN ? AND ?");
            $stmt->execute([$periodeDebut, $periodeFin]);
            $totalTaches = $stmt->fetchColumn();

            // Tâches en retard, parmi celles déclarées sur la période : date limite dépassée,
            // pas encore terminées/annulées
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM t_taches
                WHERE date_limite IS NOT NULL
                  AND date_limite < CURRENT_DATE
                  AND statut NOT IN ('Terminé', 'Annulé', 'En attente')
                  AND date_creation::date BETWEEN ? AND ?
            ");
            $stmt->execute([$periodeDebut, $periodeFin]);
            $tachesEnRetard = $stmt->fetchColumn();

            // Tâches à échéance proche (seuil configurable, Paramètres > Seuils métier),
            // parmi celles déclarées sur la période, pas terminées/annulées/en attente
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM t_taches
                WHERE date_limite IS NOT NULL
                  AND date_limite >= CURRENT_DATE
                  AND date_limite <= CURRENT_DATE + (? || ' days')::interval
                  AND statut NOT IN ('Terminé', 'Annulé', 'En attente')
                  AND date_creation::date BETWEEN ? AND ?
            ");
            $stmt->execute([$seuilEcheanceProche, $periodeDebut, $periodeFin]);
            $tachesEcheanceProche = $stmt->fetchColumn();

            // Tâches "À faire" jamais prises en charge depuis le seuil configuré, parmi
            // celles déclarées sur la période (même seuil que l'action 'escalations', qui
            // elle reste volontairement non bornée par période — alerte "maintenant").
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM t_taches
                WHERE statut = 'À faire'
                  AND date_creation::date <= CURRENT_DATE - (? || ' days')::interval
                  AND date_creation::date BETWEEN ? AND ?
            ");
            $stmt->execute([$seuilNonPriseEnCharge, $periodeDebut, $periodeFin]);
            $tachesNonPrisesEnCharge = $stmt->fetchColumn();

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
                    'en_attente' => $tachesEnAttente,
                    'total' => $totalTaches,
                    'crees_aujourdhui' => $tachesCreesAujourdhui,
                    'terminees_aujourdhui' => $tachesTermineesAujourdhui,
                    'en_retard' => $tachesEnRetard,
                    'echeance_proche' => $tachesEcheanceProche,
                    'non_prises_en_charge' => $tachesNonPrisesEnCharge
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
            [$periodeDebut, $periodeFin] = get_periode_dashboard();

            // Récupérer uniquement les tâches récentes (dernières 10) déclarées sur la période
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
                                    WHEN statut = 'En attente' THEN 'pending'
                                    WHEN statut = 'Annulé' THEN 'danger'
                                    ELSE 'info'
                                END as status_class
                            FROM t_taches
                            WHERE date_creation::date BETWEEN ? AND ?
                            ORDER BY date_creation DESC
                            LIMIT 10
                        ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$periodeDebut, $periodeFin]);
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
            [$periodeDebut, $periodeFin] = get_periode_dashboard();

            // Top 5 du matériel avec le plus de tâches déclarées sur la période (plus de
            // filtre par mot-clé ni par statut).
            // NB: NULLIF(TRIM(...), '') évite qu'un num_serie/marque/modele vide ne
            // se transforme en pattern LIKE '%%' qui matcherait TOUTES les tâches
            // (bug à l'origine d'un matériel affichant la liste de tous les tickets).
            $sql = "
                SELECT
                    m.type,
                    m.marque,
                    m.modele,
                    COUNT(t.id) as nb_pannes,
                    STRING_AGG(DISTINCT t.titre, '; ') as types_pannes
                FROM t_materiel m
                INNER JOIN t_taches t ON (
                    (NULLIF(TRIM(m.num_serie), '') IS NOT NULL
                        AND t.materiel_concerne LIKE '%' || m.num_serie || '%')
                    OR (NULLIF(TRIM(m.marque), '') IS NOT NULL AND NULLIF(TRIM(m.modele), '') IS NOT NULL
                        AND t.materiel_concerne LIKE '%' || m.marque || '%' || m.modele || '%')
                )
                WHERE t.date_creation::date BETWEEN ? AND ?
                GROUP BY m.type, m.marque, m.modele
                ORDER BY nb_pannes DESC
                LIMIT 5
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$periodeDebut, $periodeFin]);
            $pannes = $stmt->fetchAll();

            // Filet de sécurité : même en cas de correspondance légitime mais très
            // fréquente, on n'affiche que les 3 premiers intitulés distincts.
            foreach ($pannes as &$panne) {
                $titres = explode('; ', $panne['types_pannes']);
                if (count($titres) > 3) {
                    $reste = count($titres) - 3;
                    $panne['types_pannes'] = implode('; ', array_slice($titres, 0, 3)) . " … (+$reste autres)";
                }
            }
            unset($panne);

            echo json_encode($pannes);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des pannes récurrentes: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des pannes récurrentes.']);
        }
        break;

    case 'services_demandeurs':
        try {
            [$periodeDebut, $periodeFin] = get_periode_dashboard();

            // Top 5 des services qui demandent le plus de tâches sur la période
            $sql = "
                SELECT
                    COALESCE(service_concerne, 'Non spécifié') as service,
                    COUNT(*) as nb_taches,
                    COUNT(CASE WHEN statut = 'Terminé' THEN 1 END) as taches_terminees,
                    COUNT(CASE WHEN statut = 'En cours' THEN 1 END) as taches_en_cours
                FROM t_taches
                WHERE date_creation::date BETWEEN ? AND ?
                GROUP BY service_concerne
                ORDER BY nb_taches DESC
                LIMIT 5
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$periodeDebut, $periodeFin]);
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
            [$periodeDebut, $periodeFin] = get_periode_dashboard();

            // Nombre de tâches déclarées par mois, sur la période sélectionnée
            // (toutes les tâches sont comptées, plus de filtre par mot-clé).
            $sql = "
                SELECT
                    TO_CHAR(t.date_creation, 'YYYY-MM') as mois,
                    TRIM(TO_CHAR(t.date_creation, 'TMMonth')) as nom_mois,
                    EXTRACT(YEAR FROM t.date_creation)::int as annee,
                    COUNT(*) as nb_pannes
                FROM t_taches t
                WHERE t.date_creation::date BETWEEN ? AND ?
                GROUP BY TO_CHAR(t.date_creation, 'YYYY-MM'), TRIM(TO_CHAR(t.date_creation, 'TMMonth')), EXTRACT(YEAR FROM t.date_creation)
                ORDER BY mois ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$periodeDebut, $periodeFin]);
            $pannes_mois = $stmt->fetchAll();

            echo json_encode($pannes_mois);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des pannes par mois: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des pannes par mois.']);
        }
        break;

    case 'top_resolveurs':
        try {
            [$periodeDebut, $periodeFin] = get_periode_dashboard();

            // Top 5 des agents ayant terminé le plus de tâches sur la période sélectionnée.
            // Reconnaissance positive plutôt que classement des retards : encourage le
            // traitement des tâches sans stigmatiser qui en a le plus en attente.
            $sql = "
                SELECT
                    assigne_a,
                    COUNT(*) as nb_terminees
                FROM t_taches
                WHERE statut = 'Terminé'
                  AND assigne_a IS NOT NULL AND TRIM(assigne_a) <> ''
                  AND date_modification::date BETWEEN ? AND ?
                GROUP BY assigne_a
                ORDER BY nb_terminees DESC
                LIMIT 5
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$periodeDebut, $periodeFin]);
            $resolveurs = $stmt->fetchAll();

            echo json_encode($resolveurs);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération du top résolveurs: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération du top résolveurs.']);
        }
        break;

    case 'escalations':
        try {
            // Tâches "À faire" jamais prises en charge depuis plus de N jours (seuil
            // configurable, Paramètres > Seuils métier) : remonte l'information aux
            // administrateurs (pas de notion de hiérarchie/manager dans le schéma actuel,
            // donc on notifie tous les comptes Administrateur).
            $seuilJours = (int) get_parametres($pdo)['seuil_non_prise_en_charge_jours'];

            $stmt = $pdo->prepare("
                SELECT
                    id, titre, assigne_a, service_concerne, date_creation,
                    (CURRENT_DATE - date_creation::date) as jours_en_attente
                FROM t_taches
                WHERE statut = 'À faire'
                  AND date_creation::date <= CURRENT_DATE - (? || ' days')::interval
                ORDER BY date_creation ASC
                LIMIT 20
            ");
            $stmt->execute([$seuilJours]);
            $taches = $stmt->fetchAll();

            echo json_encode([
                'seuil_jours' => $seuilJours,
                'taches' => $taches
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des escalades: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des escalades.']);
        }
        break;

    case 'taches_en_attente':
        // Les 5 plus anciennes tâches "En attente" (par date de création croissante) :
        // met en lumière auprès des décideurs les dossiers bloqués le plus longtemps,
        // avec le motif d'attente et la date de relance pour relancer la résolution.
        try {
            $stmt = $pdo->query("
                SELECT id, titre, priorite, assigne_a, service_concerne, materiel_concerne,
                       motif_attente, date_relance, date_creation,
                       (CURRENT_DATE - date_creation::date) as jours_en_attente
                FROM t_taches
                WHERE statut = 'En attente'
                ORDER BY date_creation ASC
                LIMIT 5
            ");
            $taches = $stmt->fetchAll();

            echo json_encode([
                'limite' => 5,
                'taches' => $taches
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des tâches en attente: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des tâches en attente.']);
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
