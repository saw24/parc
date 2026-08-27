-- Script pour créer la table t_taches

CREATE TABLE IF NOT EXISTS t_taches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    description TEXT,
    priorite ENUM('Basse', 'Moyenne', 'Haute') NOT NULL DEFAULT 'Moyenne',
    statut ENUM('À faire', 'En cours', 'En attente', 'Terminé', 'Annulé') NOT NULL DEFAULT 'À faire',
    assigne_a VARCHAR(100),
    materiel_concerne VARCHAR(255),
    motif_attente TEXT,
    date_relance DATE,
    date_limite DATE,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insérer quelques tâches d'exemple si la table est vide
INSERT INTO t_taches (titre, description, priorite, statut, assigne_a, materiel_concerne, date_limite)
SELECT 'Réparation serveur web principal', 'Le serveur web principal est inaccessible depuis ce matin. Vérifier les connexions réseau et redémarrer si nécessaire.', 'Haute', 'En cours', 'Admin Principal', 'HPE ProLiant DL380', '2025-08-13'
WHERE NOT EXISTS (SELECT 1 FROM t_taches WHERE titre = 'Réparation serveur web principal');

INSERT INTO t_taches (titre, description, priorite, statut, assigne_a, materiel_concerne, date_limite)
SELECT 'Installation nouvelle imprimante', 'Installer la nouvelle imprimante HP LaserJet Pro dans le bureau 101 et configurer les drivers.', 'Moyenne', 'À faire', 'Jean Technicien', 'HP LaserJet Pro M404n', '2025-08-15'
WHERE NOT EXISTS (SELECT 1 FROM t_taches WHERE titre = 'Installation nouvelle imprimante');

INSERT INTO t_taches (titre, description, priorite, statut, assigne_a, materiel_concerne, date_limite)
SELECT 'Mise à jour suite bureautique', 'Mettre à jour Microsoft Office sur tous les postes du service Marketing.', 'Basse', 'À faire', 'Marie Helpdesk', 'Dell Latitude 7420', '2025-08-20'
WHERE NOT EXISTS (SELECT 1 FROM t_taches WHERE titre = 'Mise à jour suite bureautique');

INSERT INTO t_taches (titre, description, priorite, statut, assigne_a, materiel_concerne, date_limite)
SELECT 'Remplacement disque dur PC Compta', 'Le disque dur du PC de la comptabilité fait des bruits suspects. Remplacer par un SSD.', 'Moyenne', 'Terminé', 'Jean Technicien', 'Lenovo ThinkCentre M90', '2025-08-12'
WHERE NOT EXISTS (SELECT 1 FROM t_taches WHERE titre = 'Remplacement disque dur PC Compta');
