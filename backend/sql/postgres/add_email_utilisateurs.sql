-- Ajoute la colonne email à t_utilisateurs.
-- Nécessaire pour les notifications par email (nouvelle tâche assignée, résumé
-- quotidien, escalade — voir backend/lib/notifications.php) : t_utilisateurs.login
-- n'est pas fiable comme adresse email (les comptes existants utilisent des logins
-- courts type "admin", "saw24", pas forcément une adresse valide).

ALTER TABLE t_utilisateurs ADD COLUMN email VARCHAR(255);
