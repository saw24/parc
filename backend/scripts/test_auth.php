<?php
// Script d'administration : exécutable en ligne de commande uniquement, jamais via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
// Script de test pour l'API d'authentification

echo "<h1>Test de l'API d'Authentification</h1>";

// Test 1: Vérifier la structure de la table utilisateurs
echo "<h2>Test 1: Structure de la table utilisateurs</h2>";
try {
    require_once __DIR__ . '/../config/db.php'; // $pdo fourni par db.php (PostgreSQL, config via .env)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM t_utilisateurs");
    $result = $stmt->fetch();
    echo "<p>✅ Nombre d'utilisateurs dans la base : " . $result['count'] . "</p>";
    
    $stmt = $pdo->query("SELECT nom, login, role FROM t_utilisateurs LIMIT 5");
    $users = $stmt->fetchAll();
    echo "<p>👥 Utilisateurs disponibles :</p><ul>";
    foreach ($users as $user) {
        echo "<li><strong>{$user['nom']}</strong> ({$user['login']}) - {$user['role']}</li>";
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p>❌ Erreur de connexion à la base de données : " . $e->getMessage() . "</p>";
}

// Test 2: Vérifier la table des sessions
echo "<h2>Test 2: Table des sessions</h2>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 't_sessions'");
    if ($stmt->rowCount() > 0) {
        echo "<p>✅ Table t_sessions existe</p>";
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM t_sessions");
        $result = $stmt->fetch();
        echo "<p>📊 Sessions actives : " . $result['count'] . "</p>";
    } else {
        echo "<p>❌ Table t_sessions n'existe pas</p>";
        echo "<p><a href='create_sessions_table.php'>Créer la table des sessions</a></p>";
    }
} catch (PDOException $e) {
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
}

// Test 3: Test de l'API de connexion
echo "<h2>Test 3: Test de l'API de connexion</h2>";
$testData = [
    'login' => 'admin',
    'password' => 'password123'
];

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($testData)
    ]
]);

$response = @file_get_contents('http://localhost:8888/parc/api_auth.php?action=login', false, $context);

if ($response !== false) {
    echo "<p>✅ API de connexion accessible</p>";
    $data = json_decode($response, true);
    if ($data && isset($data['success'])) {
        if ($data['success']) {
            echo "<p>✅ Connexion réussie avec les identifiants de test</p>";
            echo "<p>👤 Utilisateur connecté : " . $data['user']['nom'] . "</p>";
        } else {
            echo "<p>❌ Échec de connexion : " . ($data['error'] ?? 'Erreur inconnue') . "</p>";
        }
    } else {
        echo "<p>⚠️ Réponse API invalide</p>";
    }
} else {
    echo "<p>❌ Impossible d'accéder à l'API de connexion</p>";
}

echo "<h2>Instructions de test</h2>";
echo "<p>1. <a href='update_passwords.php'>Mettre à jour les mots de passe</a></p>";
echo "<p>2. <a href='create_sessions_table.php'>Créer la table des sessions</a></p>";
echo "<p>3. <a href='login.html'>Tester la page de connexion</a></p>";
echo "<p>4. <a href='index.html'>Accéder à l'application</a></p>";

echo "<h2>Identifiants de test</h2>";
echo "<p><strong>Login :</strong> admin</p>";
echo "<p><strong>Mot de passe :</strong> password123</p>";
?>
