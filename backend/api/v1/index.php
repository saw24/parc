<?php
// API mobile v1 — point d'entrée unique et routeur.
//
// URL : <base>/backend/api/v1/index.php/<route>   (ex: .../index.php/taches/12/prendre)
// Repli sans PATH_INFO : .../index.php?r=taches/12/prendre
// Authentification : en-tête « Authorization: Bearer <access_token> » (obtenu par auth/login).
// Contrat complet : docs/API_V1.md

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/parametres.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../lib/api.php';
require_once __DIR__ . '/../../lib/device_auth.php';
require_once __DIR__ . '/../../lib/taches.php';
require_once __DIR__ . '/../../lib/materiel.php';
require_once __DIR__ . '/../../lib/renouvellement.php';
require_once __DIR__ . '/../../lib/maintenance.php';
require_once __DIR__ . '/../../lib/inventaire.php';

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ApiException) {
        api_send($e->status, ['error' => ['code' => $e->apiCode, 'message' => $e->getMessage()] + ($e->details ? ['details' => $e->details] : [])]);
    }
    error_log('API v1 erreur interne : ' . $e::class . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_send(500, ['error' => ['code' => 'erreur_interne', 'message' => 'Erreur interne du serveur.']]);
});

/** @var list<array{string,string,callable,bool}> $GLOBALS['ROUTES'] */
$GLOBALS['ROUTES'] = [];

function route(string $methode, string $modele, callable $handler, bool $auth = true): void
{
    $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $modele) . '$#';
    $GLOBALS['ROUTES'][] = [$methode, $regex, $handler, $auth];
}

/** Représentation JSON d'un utilisateur connecté. */
function v1_user_json(PDO $pdo, array $user): array
{
    $service = null;
    if (!empty($user['service_id'])) {
        $st = $pdo->prepare("SELECT id, nom FROM t_services WHERE id = ?");
        $st->execute([$user['service_id']]);
        if ($s = $st->fetch()) {
            $service = ['id' => (int) $s['id'], 'nom' => $s['nom']];
        }
    }
    return [
        'id' => (int) $user['id'], 'nom' => $user['nom'], 'login' => $user['login'], 'email' => $user['email'] ?? null,
        'telephone' => $user['telephone'] ?? null, 'role' => $user['role'], 'service' => $service,
        'permissions' => $user['permissions'], 'agent_it' => in_array('tache.prendre', $user['permissions'], true),
        'notifications' => ['email' => (bool) ($user['notif_email'] ?? true), 'sms' => (bool) ($user['notif_sms'] ?? true)],
    ];
}

foreach (['auth', 'divers', 'taches', 'materiel', 'notifications', 'renouvellement', 'maintenance', 'planning', 'inventaire', 'admin'] as $fichier) {
    require __DIR__ . "/routes/$fichier.php";
}

// ---------------------------------------------------------------- Répartition
$methode = $_SERVER['REQUEST_METHOD'];
$chemin = trim((string) (($_SERVER['PATH_INFO'] ?? '') ?: ($_GET['r'] ?? '')), '/');
$correspondances = [];
foreach ($GLOBALS['ROUTES'] as $r) {
    if (preg_match($r[1], $chemin, $m)) {
        $correspondances[$r[0]] = [$r, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
    }
}
if (!$correspondances) {
    api_fail(404, 'route_inconnue', 'Route inconnue.');
}
if (!isset($correspondances[$methode])) {
    header('Allow: ' . implode(', ', array_keys($correspondances)));
    api_fail(405, 'methode_non_autorisee', 'Méthode non autorisée pour cette route.');
}
[[$_, $_, $handler, $auth], $params] = $correspondances[$methode];

// Contrôle de version de l'application (sauf /config, pour pouvoir afficher le message de mise à jour).
$versionApp = $_SERVER['HTTP_X_APP_VERSION'] ?? '';
if ($versionApp !== '' && $chemin !== 'config') {
    $min = get_parametres($pdo)['app_version_minimale'];
    if ($min !== '' && api_version_inferieure($versionApp, $min)) {
        api_fail(426, 'app_obsolete', 'Cette version de l\'application n\'est plus prise en charge. Mettez-la à jour.',
            ['version_minimale' => $min, 'apk_url' => get_parametres($pdo)['app_apk_url']]);
    }
}

$contexte = ['pdo' => $pdo, 'user' => null, 'appareil' => null, 'params' => array_map('intval', $params),
    'query' => $_GET, 'body' => []];

if ($auth) {
    $entete = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $entete, $m)) {
        [$contexte['user'], $contexte['appareil']] = device_authentifier($pdo, $m[1]);
    } elseif (($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '') !== '') {
        // Le site web (session par en-têtes X-Session-Token / X-User-Id, sans cookie donc sans CSRF) appelle les mêmes routes.
        $contexte['user'] = device_authentifier_session_web($pdo, (string) $_SERVER['HTTP_X_SESSION_TOKEN'], (int) ($_SERVER['HTTP_X_USER_ID'] ?? 0));
    } else {
        api_fail(401, 'token_manquant', 'Authentification requise (en-tête Authorization: Bearer).');
    }
}
if (in_array($methode, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $contexte['body'] = api_body();
}

$handler($contexte);
api_fail(500, 'pas_de_reponse', 'Le gestionnaire n\'a produit aucune réponse.');
