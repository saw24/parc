<?php
// Script de test pour vérifier le chargement des données dans les selects

echo "<h1>Test du Chargement des Données pour les Selects</h1>";

// Test 1: Vérifier les utilisateurs
echo "<h2>Test 1: Données des utilisateurs</h2>";
try {
    require_once __DIR__ . '/../config/db.php'; // $pdo fourni par db.php (PostgreSQL, config via .env)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT nom, login, role FROM t_utilisateurs ORDER BY nom");
    $users = $stmt->fetchAll();
    
    echo "<p>✅ Utilisateurs disponibles pour les selects :</p>";
    echo "<ul>";
    foreach ($users as $user) {
        echo "<li><strong>{$user['nom']}</strong> ({$user['login']}) - {$user['role']}</li>";
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
}

// Test 2: Vérifier les services
echo "<h2>Test 2: Données des services</h2>";
try {
    $stmt = $pdo->query("SELECT nom, description FROM t_services ORDER BY nom");
    $services = $stmt->fetchAll();
    
    echo "<p>✅ Services disponibles pour les selects :</p>";
    echo "<ul>";
    foreach ($services as $service) {
        echo "<li><strong>{$service['nom']}</strong> - {$service['description']}</li>";
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
}

// Test 3: Vérifier le matériel
echo "<h2>Test 3: Données du matériel</h2>";
try {
    $stmt = $pdo->query("SELECT type, marque, modele, num_serie FROM t_materiel ORDER BY type, marque");
    $materiel = $stmt->fetchAll();
    
    echo "<p>✅ Matériel disponible pour les selects :</p>";
    echo "<ul>";
    foreach ($materiel as $item) {
        $displayText = "{$item['type']} {$item['marque']} {$item['modele']} ({$item['num_serie']})";
        echo "<li><strong>{$displayText}</strong></li>";
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
}

// Test 4: Test des APIs
echo "<h2>Test 4: Test des APIs pour les selects</h2>";

// Test API utilisateurs
$response = @file_get_contents('http://localhost:8888/parc/api_utilisateurs.php?action=read');
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && is_array($data)) {
        echo "<p>✅ API utilisateurs fonctionnelle - " . count($data) . " utilisateurs</p>";
    } else {
        echo "<p>❌ API utilisateurs - Réponse invalide</p>";
    }
} else {
    echo "<p>❌ API utilisateurs - Impossible d'accéder</p>";
}

// Test API services
$response = @file_get_contents('http://localhost:8888/parc/api_services.php?action=read');
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && is_array($data)) {
        echo "<p>✅ API services fonctionnelle - " . count($data) . " services</p>";
    } else {
        echo "<p>❌ API services - Réponse invalide</p>";
    }
} else {
    echo "<p>❌ API services - Impossible d'accéder</p>";
}

// Test API matériel
$response = @file_get_contents('http://localhost:8888/parc/api_materiel.php?action=read');
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && is_array($data)) {
        echo "<p>✅ API matériel fonctionnelle - " . count($data) . " équipements</p>";
    } else {
        echo "<p>❌ API matériel - Réponse invalide</p>";
    }
} else {
    echo "<p>❌ API matériel - Impossible d'accéder</p>";
}

echo "<h2>Instructions</h2>";
echo "<p>1. Vérifiez que toutes les APIs retournent des données valides</p>";
echo "<p>2. Testez les formulaires dans l'application :</p>";
echo "<ul>";
echo "<li><a href='index.html'>Accéder à l'application</a></li>";
echo "<li>Ouvrir le formulaire d'ajout de matériel</li>";
echo "<li>Ouvrir le formulaire d'ajout de tâche</li>";
echo "<li>Vérifier que les selects sont remplis avec les vraies données</li>";
echo "</ul>";

echo "<h2>Fonctionnalités des Selects</h2>";
echo "<p><strong>Formulaire Matériel :</strong></p>";
echo "<ul>";
echo "<li>Service affecté : Liste des services depuis la base</li>";
echo "<li>Utilisateur assigné : Liste des utilisateurs avec leur rôle</li>";
echo "</ul>";

echo "<p><strong>Formulaire Tâches :</strong></p>";
echo "<ul>";
echo "<li>Assigné à : Liste des utilisateurs avec leur rôle</li>";
echo "<li>Matériel concerné : Liste du matériel avec type, marque, modèle et n° série</li>";
echo "</ul>";
?>
