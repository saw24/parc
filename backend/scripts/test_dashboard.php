<?php
// Script de test pour l'API du tableau de bord

echo "<h1>Test de l'API du Tableau de Bord</h1>";

// Test 1: Statistiques
echo "<h2>Test 1: Statistiques</h2>";
$url = 'http://localhost:8888/parc/api_dashboard.php?action=stats';
$response = file_get_contents($url);
echo "<pre>Réponse: " . htmlspecialchars($response) . "</pre>";

// Test 2: Tâches récentes
echo "<h2>Test 2: Tâches récentes</h2>";
$url = 'http://localhost:8888/parc/api_dashboard.php?action=activites';
$response = file_get_contents($url);
echo "<pre>Réponse: " . htmlspecialchars($response) . "</pre>";

echo "<h2>Test terminé</h2>";
echo "<p><a href='index.html'>Retour à l'application</a></p>";
?>
