<?php
// Script d'administration : exécutable en ligne de commande uniquement, jamais via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
// Script pour mettre à jour les mots de passe avec le bon hachage

// Inclusion du fichier de connexion à la base de données
require_once __DIR__ . '/../config/db.php';

// Activation du rapport d'erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Mot de passe à utiliser pour tous les utilisateurs
    $password = 'password123';
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    echo "<h2>Mise à jour des mots de passe</h2>";
    echo "<p>Mise à jour des mots de passe pour tous les utilisateurs...</p>";
    
    // Mettre à jour tous les utilisateurs avec le nouveau mot de passe haché
    $sql = "UPDATE t_utilisateurs SET mot_de_passe = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$hashedPassword]);
    
    if ($result) {
        $rowCount = $stmt->rowCount();
        echo "<p>✅ Mise à jour réussie ! $rowCount utilisateur(s) mis à jour.</p>";
        echo "<p>📝 <strong>Mot de passe pour tous les utilisateurs :</strong> $password</p>";
        echo "<p>👥 <strong>Utilisateurs disponibles :</strong></p>";
        
        // Afficher la liste des utilisateurs
        $usersSql = "SELECT nom, login, role FROM t_utilisateurs";
        $usersStmt = $pdo->query($usersSql);
        $users = $usersStmt->fetchAll();
        
        echo "<ul>";
        foreach ($users as $user) {
            echo "<li><strong>{$user['nom']}</strong> ({$user['login']}) - {$user['role']}</li>";
        }
        echo "</ul>";
        
        echo "<p><a href='login.html'>Aller à la page de connexion</a></p>";
    } else {
        echo "<p>❌ Erreur lors de la mise à jour des mots de passe.</p>";
    }
    
} catch (PDOException $e) {
    echo "<h2>Erreur lors de la mise à jour</h2>";
    echo "<p>Erreur lors de la mise à jour des mots de passe: " . $e->getMessage() . "</p>";
}
?>
