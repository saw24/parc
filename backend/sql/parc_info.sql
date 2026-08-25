-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost:8889
-- Généré le : mer. 13 août 2025 à 14:07
-- Version du serveur : 5.7.39
-- Version de PHP : 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `parc_info`
--

-- --------------------------------------------------------

--
-- Structure de la table `t_materiel`
--

CREATE TABLE `t_materiel` (
  `id` int(11) NOT NULL,
  `type` varchar(100) NOT NULL,
  `marque` varchar(100) DEFAULT NULL,
  `modele` varchar(100) DEFAULT NULL,
  `num_serie` varchar(100) DEFAULT NULL,
  `date_achat` date DEFAULT NULL,
  `statut` varchar(50) NOT NULL,
  `service_affecte` varchar(100) DEFAULT NULL,
  `utilisateur_assigne` varchar(100) DEFAULT NULL,
  `updated_by_login` varchar(100) DEFAULT NULL,
  `created_by_login` varchar(100) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `t_materiel`
--

INSERT INTO `t_materiel` (`id`, `type`, `marque`, `modele`, `num_serie`, `date_achat`, `statut`, `service_affecte`, `utilisateur_assigne`, `updated_by_login`, `created_by_login`, `date_creation`, `date_modification`) VALUES
(1, 'PC Portable', 'Dell', 'Latitude 74201', 'ABC123XYZ', '2023-05-10', 'En service', 'Marketing', 'Jean Technicien', 'admin', NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(2, 'Imprimante', 'HP', 'LaserJet Pro', 'DEF456ABC', '2022-11-20', 'En panne', 'Comptabilité', '', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(3, 'Serveur', 'HPE', 'ProLiant', 'GHI789DEF', '2021-02-15', 'En service', 'IT', '', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(4, 'PC Fixe', 'dell', 'mo', '1222', '2025-08-12', 'En service', '', '', 'admin', NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(16, 'PC Portable', 'toshi', '', '12222999', NULL, 'En service', '', '', 'admin', NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(17, 'PC Portable', 'Dell', 'mo', 'ABC123XYZo', NULL, 'En panne', '', '', 'admin', NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(18, 'Imprimante', 'HP LASERT', 'P2035', '9632', '2025-08-01', 'En panne', 'Direction', 'Marie Helpdesk', 'admin', 'admin', '2025-08-13 13:16:48', '2025-08-13 13:17:25');

-- --------------------------------------------------------

--
-- Structure de la table `t_services`
--

CREATE TABLE `t_services` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `description` text,
  `updated_by_login` varchar(100) DEFAULT NULL,
  `created_by_login` varchar(100) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `t_services`
--

INSERT INTO `t_services` (`id`, `nom`, `description`, `updated_by_login`, `created_by_login`, `date_creation`, `date_modification`) VALUES
(2, 'Comptabilité', 'Service responsable des finances, de la facturation et des budgets.', 'admin', NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(3, 'Informatique (IT)', 'Gestion, maintenance et sécurité de l\'ensemble du parc informatique.', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(4, 'Marketing', 'Service en charge de la communication, de la publicité et de la stratégie commerccccccccc', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(5, 'Direction', 'Pôle de direction et de prise de décision stratégique.', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(7, 'Comptabilité5', 'ras', 'admin', NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(10, 'Service Test Modifié par l\'IA', 'dcxXwxw5555', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(12, 'Comptabilité321', 'mammmaaa', NULL, NULL, '2025-08-13 13:16:48', '2025-08-13 13:17:25'),
(13, 'BLOC OP 1', '', NULL, 'admin', '2025-08-13 13:16:48', '2025-08-13 13:17:25');

-- --------------------------------------------------------

--
-- Structure de la table `t_sessions`
--

CREATE TABLE `t_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `expiry_time` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `t_sessions`
--

INSERT INTO `t_sessions` (`id`, `user_id`, `session_token`, `expiry_time`, `created_at`) VALUES
(3, 1, 'e8ef9132f4e991f8ad063f6adca49c1a6cfa071215197e92e9cc12344dfc0381', '2025-08-13 23:15:32', '2025-08-12 23:15:32'),
(4, 1, '409ffb1c89146ce144e9a578f1a476f9ad1b126befa28a89a6a2dd17e1f89b5d', '2025-08-14 08:11:07', '2025-08-13 08:11:07'),
(6, 1, 'ea5106c1ba6950194ce2c38dca44fb7980d54ce4114f8c97d8b67de24ae51263', '2025-08-14 12:32:18', '2025-08-13 12:32:18');

-- --------------------------------------------------------

--
-- Structure de la table `t_taches`
--

CREATE TABLE `t_taches` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text,
  `priorite` enum('Basse','Moyenne','Haute') DEFAULT NULL,
  `statut` enum('À faire','En cours','Terminé','Annulé') DEFAULT NULL,
  `assigne_a` varchar(100) DEFAULT NULL,
  `materiel_concerne` varchar(255) DEFAULT NULL,
  `service_concerne` varchar(100) DEFAULT NULL,
  `date_limite` date DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actions` text,
  `updated_by_login` varchar(100) DEFAULT NULL,
  `created_by_login` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `t_taches`
--

INSERT INTO `t_taches` (`id`, `titre`, `description`, `priorite`, `statut`, `assigne_a`, `materiel_concerne`, `service_concerne`, `date_limite`, `date_creation`, `date_modification`, `actions`, `updated_by_login`, `created_by_login`) VALUES
(2, 'tache', 'comment', 'Moyenne', 'En cours', '', '', '', '2025-08-19', '2025-08-12 21:49:13', '2025-08-12 21:49:13', 'actions 1', 'admin', NULL),
(3, 'remplace le disque', 'auvoir', 'Moyenne', 'En cours', 'Admin Principal', 'Imprimante HP LaserJet Pro (DEF456ABC)', 'Comptabilité', '2025-08-13', '2025-08-12 22:17:50', '2025-08-12 22:17:50', 'actions 1 et 2 et 3 aussi', NULL, NULL),
(4, 'imprimante', 'non', 'Moyenne', 'À faire', 'Admin Principal', 'PC Portable Dell Latitude 74201 (ABC123XYZ)', 'Marketing', '2025-08-13', '2025-08-13 13:02:12', '2025-08-13 13:02:12', '', 'admin', 'admin');

-- --------------------------------------------------------

--
-- Structure de la table `t_type_materiel`
--

CREATE TABLE `t_type_materiel` (
  `id` int(11) NOT NULL,
  `type` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `t_utilisateurs`
--

CREATE TABLE `t_utilisateurs` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `login` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by_login` varchar(100) DEFAULT NULL,
  `created_by_login` varchar(100) DEFAULT NULL,
  `date_modification` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `t_utilisateurs`
--

INSERT INTO `t_utilisateurs` (`id`, `nom`, `login`, `role`, `mot_de_passe`, `date_creation`, `updated_by_login`, `created_by_login`, `date_modification`) VALUES
(1, 'Admin Principal', 'admin', 'Administrateur', '$2y$10$GQRfOWOIUNZHxl.X51NmieEV.ux81OY5mBBN81wjN789H6yD0FYKK', '2025-08-12 18:19:21', NULL, NULL, '2025-08-13 13:17:56'),
(2, 'Jean Technicien', 'jean', 'Technicien', '$2y$10$GQRfOWOIUNZHxl.X51NmieEV.ux81OY5mBBN81wjN789H6yD0FYKK', '2025-08-12 18:19:21', NULL, NULL, '2025-08-13 13:17:56'),
(5, 'Marie Helpdesk', 'test', 'Technicien', '$2y$10$GQRfOWOIUNZHxl.X51NmieEV.ux81OY5mBBN81wjN789H6yD0FYKK', '2025-08-12 21:29:55', 'admin', NULL, '2025-08-13 13:17:56'),
(6, 'NANA Aichatou', 'nana', 'Technicien', '1234', '2025-08-13 13:14:10', 'admin', 'admin', '2025-08-13 13:17:56');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `t_materiel`
--
ALTER TABLE `t_materiel`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `num_serie` (`num_serie`);

--
-- Index pour la table `t_services`
--
ALTER TABLE `t_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom` (`nom`);

--
-- Index pour la table `t_sessions`
--
ALTER TABLE `t_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expiry_time` (`expiry_time`);

--
-- Index pour la table `t_taches`
--
ALTER TABLE `t_taches`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `t_type_materiel`
--
ALTER TABLE `t_type_materiel`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `t_utilisateurs`
--
ALTER TABLE `t_utilisateurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login` (`login`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `t_materiel`
--
ALTER TABLE `t_materiel`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT pour la table `t_services`
--
ALTER TABLE `t_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT pour la table `t_sessions`
--
ALTER TABLE `t_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `t_taches`
--
ALTER TABLE `t_taches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `t_type_materiel`
--
ALTER TABLE `t_type_materiel`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `t_utilisateurs`
--
ALTER TABLE `t_utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `t_sessions`
--
ALTER TABLE `t_sessions`
  ADD CONSTRAINT `t_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `t_utilisateurs` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
