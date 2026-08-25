<?php
// api_parametres.php — Paramètres applicatifs (SMTP, seuils métier, fréquences de
// rappel). Écran "Paramètres", réservé aux comptes Administrateur (sauf action
// 'public', lue par tout utilisateur authentifié pour remplacer les valeurs codées
// en dur côté frontend).

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0); // ne jamais afficher les erreurs PHP au client (fuite d'infos internes)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../middleware/auth.php';
$currentUser = require_auth($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'public':
        // Seuils/fréquences non sensibles, lisibles par tout utilisateur connecté.
        try {
            echo json_encode(get_parametres_publics($pdo));
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des paramètres publics: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des paramètres.']);
        }
        break;

    case 'read':
        require_role($currentUser, ['Administrateur']);
        try {
            $params = get_parametres($pdo);
            // Le mot de passe SMTP n'est jamais renvoyé en clair : seul un booléen indique
            // s'il est configuré, pour permettre au formulaire de savoir s'il faut le
            // redemander ou afficher un simple "mot de passe déjà enregistré".
            $smtpPasswordConfigure = trim($params['smtp_password']) !== '';
            unset($params['smtp_password']);
            foreach (get_parametres_internes() as $cleInterne) {
                unset($params[$cleInterne]);
            }
            $params['smtp_password_configure'] = $smtpPasswordConfigure;

            echo json_encode($params);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de la récupération des paramètres: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de la récupération des paramètres.']);
        }
        break;

    case 'update':
        require_role($currentUser, ['Administrateur']);
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        try {
            $valeurs = get_default_parametres();
            unset($valeurs['smtp_password']); // géré séparément ci-dessous
            foreach (get_parametres_internes() as $cleInterne) {
                unset($valeurs[$cleInterne]);
            }

            $donnees = [];
            foreach (array_keys($valeurs) as $cle) {
                if (isset($_POST[$cle])) {
                    $donnees[$cle] = $_POST[$cle];
                }
            }

            // Cases à cocher : un champ absent du POST signifie "décoché" (comportement HTML
            // standard), pas "ne pas modifier" — on force explicitement à '0' celles connues
            // du formulaire mais absentes de la requête.
            $casesACocher = [
                'email_notifications_actives',
                'email_on_nouvelle_tache_assignee',
                'email_on_digest_quotidien',
                'email_on_escalade',
            ];
            foreach ($casesACocher as $cle) {
                if (!isset($_POST[$cle])) {
                    $donnees[$cle] = '0';
                }
            }

            // Mot de passe SMTP : seulement si un nouveau a été saisi (laisser vide = conserver
            // l'existant). '__clear__' permet de le réinitialiser explicitement (bouton dédié).
            $nouveauMotDePasse = $_POST['smtp_password'] ?? '';
            if ($nouveauMotDePasse === '__clear__') {
                $donnees['smtp_password'] = '';
            } elseif ($nouveauMotDePasse !== '') {
                $donnees['smtp_password'] = $nouveauMotDePasse;
            }

            $updatedByLogin = $currentUser['login'] ?? '';
            save_parametres($pdo, $donnees, $updatedByLogin);

            echo json_encode(['success' => true, 'message' => 'Paramètres enregistrés avec succès.']);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Erreur lors de l\'enregistrement des paramètres: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de l\'enregistrement des paramètres.']);
        }
        break;

    case 'test_email':
        require_role($currentUser, ['Administrateur']);
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'La méthode POST est requise.']);
            exit();
        }

        $destinataire = trim($_POST['destinataire'] ?? '');
        if ($destinataire === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Adresse email de test requise.']);
            exit();
        }

        try {
            // Test direct de la config SMTP soumise (pas encore forcément enregistrée),
            // avec repli sur la config déjà en base pour les champs non fournis (ex: mot de
            // passe déjà enregistré et laissé vide dans le formulaire).
            $params = get_parametres($pdo);
            $champsSmtp = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name'];
            foreach ($champsSmtp as $champ) {
                if (isset($_POST[$champ]) && $_POST[$champ] !== '') {
                    $params[$champ] = $_POST[$champ];
                }
            }
            if (!empty($_POST['smtp_password'])) {
                $params['smtp_password'] = $_POST['smtp_password'];
            }

            $mailer = new SmtpMailer($params);
            $mailer->send(
                $destinataire,
                'Parc Info — Email de test',
                "Ceci est un email de test envoyé depuis l'écran Paramètres de Parc Info.\n\n"
                . "Si vous recevez ce message, la configuration SMTP fonctionne correctement.\n"
            );

            echo json_encode(['success' => true, 'message' => 'Email de test envoyé avec succès.']);
        } catch (SmtpException $e) {
            http_response_code(422);
            echo json_encode(['error' => 'Échec de l\'envoi : ' . $e->getMessage()]);
        } catch (Exception $e) {
            http_response_code(500);
            error_log('Erreur lors du test SMTP: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur inattendue lors du test.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non reconnue.']);
        break;
}
