<?php
// Fichier de configuration pour la connexion à la base de données (db.php)
// Connexion PostgreSQL, paramètres lus depuis le fichier .env à la racine du projet.

require_once __DIR__ . '/env.php';

// Le .env "officiel" vit hors du répertoire servi par le serveur web
// (/Applications/MAMP/parc-secrets/.env n'est pas sous htdocs/ donc jamais
// accessible en HTTP, quelle que soit la config du serveur). On retombe sur
// le .env à la racine du projet si le premier est absent (portabilité vers
// une autre machine / un autre déploiement).
// Ordre : PARC_ENV_FILE (variable d'environnement) > serveur de production > poste de dev > racine du projet.
$env = [];
foreach (array_filter([
    getenv('PARC_ENV_FILE') ?: null,
    '/srv/apps/parc-secrets/.env',
    '/Users/saw24/mes_fichiers_env/taches/.env',
    __DIR__ . '/../../.env',
]) as $fichier_env) {
    $env = load_env($fichier_env);
    if (!empty($env)) break;
}

// Paramètres de connexion (avec valeurs par défaut de secours si .env absent)
$host     = $env['DB_HOST']     ?? 'localhost';
$port     = $env['DB_PORT']     ?? '5432';
// PARC_DB_NAME (variable d'environnement) permet aux scripts/tests de cibler une autre base
// sans toucher au .env (ex: base jetable pour tester les migrations).
$dbname   = getenv('PARC_DB_NAME') ?: ($env['DB_NAME'] ?? 'taches');
$username = $env['DB_USER']     ?? 'saw24';
$password = $env['DB_PASSWORD'] ?? 'saw24';

// Création de la chaîne de connexion (DSN) PostgreSQL
// gssencmode=disable : libpq 18 tente par défaut une négociation GSS-encryption
// (Kerberos). Sous php-fpm, le worker tourne en tant que _www (utilisateur système
// sans session de connexion) et cette négociation fait planter le process en
// SIGSEGV (CFPreferences / CoreFoundation). On la désactive donc explicitement.
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;gssencmode=disable";

// Options pour PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Gérer les erreurs comme des exceptions
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Récupérer les résultats en tableau associatif
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Utiliser de vraies requêtes préparées
];

try {
    // Création de l'instance PDO
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // En cas d'erreur de connexion, on arrête tout et on affiche un message
    // En production, il faudrait logguer cette erreur plutôt que de l'afficher
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Service momentanément indisponible.']);
    error_log('DB Connection Error: ' . $e->getMessage());
    exit(); // Arrête l'exécution du script
}
