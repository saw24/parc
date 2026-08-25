-- Script pour ajouter le champ service_concerne à la table t_taches

-- Ajouter la colonne service_concerne
ALTER TABLE t_taches 
ADD COLUMN service_concerne VARCHAR(100) AFTER materiel_concerne;

-- Mettre à jour les tâches existantes avec un service par défaut (optionnel)
-- UPDATE t_taches SET service_concerne = 'IT' WHERE service_concerne IS NULL;

-- Afficher la structure mise à jour
DESCRIBE t_taches;
