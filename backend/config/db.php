<?php
// Fichier de configuration pour la connexion à la base de données (db.php)
// Connexion PostgreSQL, paramètres lus depuis le fichier .env à la racine du projet.

require_once __DIR__ . '/env.php';

// Le .env "officiel" vit hors du répertoire servi par le serveur web
// (/Applications/MAMP/parc-secrets/.env n'est pas sous htdocs/ donc jamais
// accessible en HTTP, quelle que soit la config du serveur). On retombe sur
// le .env à la racine du projet si le premier est absent (portabilité vers
// une autre machine / un autre déploiement).
$env = load_env('/Applications/MAMP/parc-secrets/.env');
if (empty($env)) {
    $env = load_env(__DIR__ . '/../../.env');
}

// Paramètres de connexion (avec valeurs par défaut de secours si .env absent)
$host     = $env['DB_HOST']     ?? 'localhost';
$port     = $env['DB_PORT']     ?? '5432';
$dbname   = $env['DB_NAME']     ?? 'taches';
$username = $env['DB_USER']     ?? 'saw24';
$password = $env['DB_PASSWORD'] ?? 'saw24';

// Création de la chaîne de connexion (DSN) PostgreSQL
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

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
    echo json_encode(['error' => 'Erreur de connexion à la base de données: ' . $e->getMessage()]);
    error_log('DB Connection Error: ' . $e->getMessage());
    exit(); // Arrête l'exécution du script
}
