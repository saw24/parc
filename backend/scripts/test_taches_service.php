<?php
// Script d'administration : exécutable en ligne de commande uniquement, jamais via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
// Script de test pour vérifier les nouvelles fonctionnalités des tâches avec service

echo "<h1>Test des Nouvelles Fonctionnalités des Tâches</h1>";

// Test 1: Vérifier la structure de la table t_taches
echo "<h2>Test 1: Structure de la table t_taches</h2>";
try {
    require_once __DIR__ . '/../config/db.php'; // $pdo fourni par db.php (PostgreSQL, config via .env)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("DESCRIBE t_taches");
    $columns = $stmt->fetchAll();
    
    echo "<p>✅ Structure de la table t_taches :</p>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        $highlight = ($column['Field'] === 'service_concerne') ? 'background-color: #90EE90;' : '';
        echo "<tr style='{$highlight}'>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Vérifier si le champ service_concerne existe
    $hasServiceField = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'service_concerne') {
            $hasServiceField = true;
            break;
        }
    }
    
    if ($hasServiceField) {
        echo "<p>✅ Le champ 'service_concerne' a été ajouté avec succès !</p>";
    } else {
        echo "<p>❌ Le champ 'service_concerne' n'existe pas encore.</p>";
        echo "<p><a href='update_taches_table.php'>Exécuter la mise à jour de la table</a></p>";
    }
    
} catch (PDOException $e) {
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
}

// Test 2: Vérifier les données existantes
echo "<h2>Test 2: Données existantes dans t_taches</h2>";
try {
    $stmt = $pdo->query("SELECT id, titre, service_concerne, materiel_concerne, assigne_a FROM t_taches ORDER BY id");
    $taches = $stmt->fetchAll();
    
    if (count($taches) > 0) {
        echo "<p>✅ Tâches existantes :</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Titre</th><th>Service</th><th>Matériel</th><th>Assigné à</th></tr>";
        foreach ($taches as $tache) {
            echo "<tr>";
            echo "<td>{$tache['id']}</td>";
            echo "<td>{$tache['titre']}</td>";
            echo "<td>" . ($tache['service_concerne'] ?: '-') . "</td>";
            echo "<td>" . ($tache['materiel_concerne'] ?: '-') . "</td>";
            echo "<td>" . ($tache['assigne_a'] ?: '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>ℹ️ Aucune tâche existante dans la base de données.</p>";
    }
    
} catch (PDOException $e) {
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
}

// Test 3: Test de l'API des tâches avec service
echo "<h2>Test 3: Test de l'API des tâches</h2>";

// Test de lecture
$response = @file_get_contents('http://localhost:8888/parc/api_taches.php?action=read');
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && is_array($data)) {
        echo "<p>✅ API de lecture des tâches fonctionnelle - " . count($data) . " tâches</p>";
        if (count($data) > 0) {
            echo "<p>📋 Exemple de structure de données :</p>";
            echo "<pre>" . json_encode($data[0], JSON_PRETTY_PRINT) . "</pre>";
        }
    } else {
        echo "<p>❌ API des tâches - Réponse invalide</p>";
    }
} else {
    echo "<p>❌ API des tâches - Impossible d'accéder</p>";
}

// Test 4: Test de l'API du tableau de bord
echo "<h2>Test 4: Test de l'API du tableau de bord</h2>";

$response = @file_get_contents('http://localhost:8888/parc/api_dashboard.php?action=activites');
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && is_array($data)) {
        echo "<p>✅ API des activités récentes fonctionnelle - " . count($data) . " activités</p>";
        if (count($data) > 0) {
            echo "<p>📋 Exemple d'activité récente :</p>";
            echo "<pre>" . json_encode($data[0], JSON_PRETTY_PRINT) . "</pre>";
        }
    } else {
        echo "<p>❌ API des activités - Réponse invalide</p>";
    }
} else {
    echo "<p>❌ API des activités - Impossible d'accéder</p>";
}

// Test 5: Test de l'API du matériel pour le filtrage
echo "<h2>Test 5: Test de l'API du matériel pour filtrage</h2>";

$response = @file_get_contents('http://localhost:8888/parc/api_materiel.php?action=read');
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && is_array($data)) {
        echo "<p>✅ API du matériel fonctionnelle - " . count($data) . " équipements</p>";
        
        // Analyser les services disponibles
        $services = [];
        foreach ($data as $item) {
            if (!empty($item['service_affecte'])) {
                $services[$item['service_affecte']] = ($services[$item['service_affecte']] ?? 0) + 1;
            }
        }
        
        if (!empty($services)) {
            echo "<p>📊 Répartition du matériel par service :</p>";
            echo "<ul>";
            foreach ($services as $service => $count) {
                echo "<li><strong>{$service}</strong> : {$count} équipement(s)</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>ℹ️ Aucun matériel n'est affecté à un service.</p>";
        }
    } else {
        echo "<p>❌ API du matériel - Réponse invalide</p>";
    }
} else {
    echo "<p>❌ API du matériel - Impossible d'accéder</p>";
}

echo "<h2>Instructions de Test</h2>";
echo "<p>1. <strong>Mise à jour de la base :</strong> Si le champ 'service_concerne' n'existe pas, exécutez d'abord :</p>";
echo "<p><a href='update_taches_table.php'>Mettre à jour la table t_taches</a></p>";

echo "<p>2. <strong>Test des formulaires :</strong></p>";
echo "<ul>";
echo "<li><a href='index.html'>Accéder à l'application</a></li>";
echo "<li>Ouvrir le formulaire d'ajout de tâche</li>";
echo "<li>Vérifier que le champ 'Service concerné' apparaît</li>";
echo "<li>Sélectionner un service et vérifier que le matériel se filtre</li>";
echo "<li>Créer une tâche avec un service</li>";
echo "<li>Vérifier que le service apparaît dans la liste des tâches</li>";
echo "<li>Vérifier que le service apparaît dans les activités récentes du tableau de bord</li>";
echo "</ul>";

echo "<p>3. <strong>Fonctionnalités à tester :</strong></p>";
echo "<ul>";
echo "<li>✅ Filtrage du matériel par service dans le formulaire des tâches</li>";
echo "<li>✅ Affichage du service dans la liste des tâches</li>";
echo "<li>✅ Affichage du service dans les activités récentes</li>";
echo "<li>✅ Sauvegarde et modification des tâches avec service</li>";
echo "</ul>";

echo "<h2>Résumé des Améliorations</h2>";
echo "<p><strong>Nouvelles fonctionnalités ajoutées :</strong></p>";
echo "<ul>";
echo "<li>🔧 Champ 'Service concerné' dans les tâches</li>";
echo "<li>🔍 Filtrage automatique du matériel par service</li>";
echo "<li>📋 Affichage du service dans la liste des tâches</li>";
echo "<li>📊 Affichage du service dans les activités récentes</li>";
echo "<li>💾 Sauvegarde complète avec service dans la base de données</li>";
echo "</ul>";
?>
