<?php
// Script de test pour l'API des tâches

echo "<h1>Test de l'API des Tâches</h1>";

// Test 1: Lecture des tâches
echo "<h2>Test 1: Lecture des tâches</h2>";
$url = 'http://localhost:8888/parc/api_taches.php?action=read';
$response = file_get_contents($url);
echo "<pre>Réponse: " . htmlspecialchars($response) . "</pre>";

// Test 2: Création d'une tâche
echo "<h2>Test 2: Création d'une tâche</h2>";
$postData = http_build_query([
    'titre' => 'Tâche de test',
    'description' => 'Description de la tâche de test',
    'priorite' => 'Moyenne',
    'statut' => 'À faire',
    'assigne_a' => 'Test Technicien',
    'service_concerne' => 'Informatique',
    'materiel_concerne' => 'PC Test',
    'date_limite' => '2025-12-31'
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $postData
    ]
]);

$response = file_get_contents('http://localhost:8888/parc/api_taches.php?action=create', false, $context);
echo "<pre>Réponse: " . htmlspecialchars($response) . "</pre>";

echo "<h2>Test terminé</h2>";
echo "<p><a href='index.html'>Retour à l'application</a></p>";
?>
