<?php
// ⚠️ LEGACY (MySQL) : script historique pour l'ancienne base MySQL "parc_info".
// Depuis la migration vers PostgreSQL (base "taches"), le schéma est créé par
// backend/sql/postgres/schema.sql. Ne pas ré-exécuter ce script tel quel.
// Script pour créer la table t_utilisateurs

// Inclusion du fichier de connexion à la base de données
require_once __DIR__ . '/../config/db.php';

// Activation du rapport d'erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Lecture du contenu du fichier SQL
    $sql = file_get_contents('create_utilisateurs_table.sql');
    
    // Exécution du script SQL
    $result = $pdo->exec($sql);
    
    echo "<p>La table t_utilisateurs a été créée avec succès.</p>";
    echo "<p><a href='index.html'>Retour à l'application</a></p>";
    
} catch (PDOException $e) {
    // En cas d'erreur, on affiche un message d'erreur
    echo "<p>Erreur lors de la création de la table t_utilisateurs: " . $e->getMessage() . "</p>";
}
?>