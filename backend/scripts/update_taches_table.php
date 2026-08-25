<?php
// ⚠️ LEGACY (MySQL) : script historique pour l'ancienne base MySQL "parc_info".
// Depuis la migration vers PostgreSQL (base "taches"), le schéma est créé par
// backend/sql/postgres/schema.sql. Ne pas ré-exécuter ce script tel quel.
require_once __DIR__ . '/../config/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo "<h2>Mise à jour de la table t_taches</h2>";
    
    // Lire et exécuter le script SQL
    $sql = file_get_contents('update_taches_table.sql');
    $result = $pdo->exec($sql);
    
    echo "<p>✅ La table t_taches a été mise à jour avec succès.</p>";
    echo "<p>📝 Le champ 'service_concerne' a été ajouté.</p>";
    
    // Afficher la structure mise à jour
    echo "<h3>Structure mise à jour de la table t_taches :</h3>";
    $stmt = $pdo->query("DESCRIBE t_taches");
    $columns = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<p><a href='index.html'>Retour à l'application</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>Erreur lors de la mise à jour</h2>";
    echo "<p>❌ Erreur : " . $e->getMessage() . "</p>";
    echo "<p><a href='index.html'>Retour à l'application</a></p>";
}
?>
