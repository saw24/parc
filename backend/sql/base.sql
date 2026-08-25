-- Création de la table pour les services/départements
CREATE TABLE t_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
);

-- Insertion de quelques données d'exemple pour les tests
INSERT INTO t_services (nom, description) VALUES
('Ressources Humaines', 'Service en charge de la gestion du personnel, des paies et du recrutement.'),
('Comptabilité', 'Service responsable des finances, de la facturation et des budgets.'),
('Informatique (IT)', 'Gestion, maintenance et sécurité de l\'ensemble du parc informatique.'),
('Marketing', 'Service en charge de la communication, de la publicité et de la stratégie commerciale.'),
('Direction', 'Pôle de direction et de prise de décision stratégique.');




-- Création de la table pour les utilisateurs (agents du service IT)
CREATE TABLE t_utilisateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_complet VARCHAR(100) NOT NULL,
    login VARCHAR(100) NOT NULL UNIQUE,
    role VARCHAR(50) NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- Insertion de quelques utilisateurs d'exemple
-- IMPORTANT : En pratique, les mots de passe doivent TOUJOURS être hachés.
-- L'exemple ci-dessous utilise la fonction password_hash() de PHP.
-- Le mot de passe pour tous ces utilisateurs est 'password123'.
INSERT INTO t_utilisateurs (nom_complet, login, role, mot_de_passe) VALUES
('Admin Principal', 'admin', 'Administrateur', '$2y$10$E.qZ3s8s7G9f3aP5fH/y.u2fJg5kL9w3jK4nO1bC8xR2gI6oE7p/q'),
('Jean Technicien', 'jean', 'Technicien', '$2y$10$E.qZ3s8s7G9f3aP5fH/y.u2fJg5kL9w3jK4nO1bC8xR2gI6oE7p/q'),
('Marie Helpdesk', 'marie', 'Technicien', '$2y$10$E.qZ3s8s7G9f3aP5fH/y.u2fJg5kL9w3jK4nO1bC8xR2gI6oE7p/q');

-- Création de la table pour les tâches
CREATE TABLE IF NOT EXISTS t_taches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    description TEXT,
    priorite ENUM('Basse', 'Moyenne', 'Haute') NOT NULL DEFAULT 'Moyenne',
    statut ENUM('À faire', 'En cours', 'Terminé', 'Annulé') NOT NULL DEFAULT 'À faire',
    assigne_a VARCHAR(100),
    materiel_concerne VARCHAR(255),
    date_limite DATE,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Création de la table pour le matériel
CREATE TABLE IF NOT EXISTS t_materiel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    marque VARCHAR(100) NOT NULL,
    modele VARCHAR(100),
    num_serie VARCHAR(100) UNIQUE,
    date_achat DATE,
    statut ENUM('En service', 'En panne', 'En attente', 'Retiré') NOT NULL DEFAULT 'En service',
    service_affecte VARCHAR(100),
    utilisateur_assigne VARCHAR(100),
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);



ALTER TABLE `t_materiel` ADD `created_by_login` VARCHAR(100) NULL ;
ALTER TABLE `t_services` ADD `created_by_login` VARCHAR(100) NULL ;
ALTER TABLE `t_taches` ADD `created_by_login` VARCHAR(100) NULL ;
ALTER TABLE `t_utilisateurs` ADD `created_by_login` VARCHAR(100) NULL ;

ALTER TABLE `t_materiel` ADD `updated_by_login` VARCHAR(100) NULL ;
ALTER TABLE `t_services` ADD `updated_by_login` VARCHAR(100) NULL ;
ALTER TABLE `t_taches` ADD `updated_by_login` VARCHAR(100) NULL ;
ALTER TABLE `t_utilisateurs` ADD `updated_by_login` VARCHAR(100) NULL ;


ALTER TABLE `t_materiel` ADD `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ;
ALTER TABLE `t_services` ADD `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ;
ALTER TABLE `t_taches` ADD `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ;
ALTER TABLE `t_utilisateurs` ADD `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ;

ALTER TABLE `t_materiel` ADD `date_modification` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ;
ALTER TABLE `t_services` ADD `date_modification` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ;
ALTER TABLE `t_taches` ADD `date_modification` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ;
ALTER TABLE `t_utilisateurs` ADD `date_modification` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ;

CREATE TABLE t_type_materiel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(100) NOT NULL
);

ALTER TABLE `t_taches` CHANGE `priorite` `priorite` VARCHAR(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL;
ALTER TABLE `t_taches` CHANGE `statut` `statut` VARCHAR(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL;
UPDATE t_taches SET statut = 'A faire' WHERE statut = 'À faire';


