-- Ajoute la colonne motif_cause à la table t_taches
-- Nécessaire car le code (backend/api/taches.php) et le formulaire frontend
-- (champ "Motif/Cause") utilisent déjà ce champ, mais il manquait au schéma
-- versionné (parc_info.sql / base.sql étaient désynchronisés du code).

ALTER TABLE t_taches ADD COLUMN motif_cause TEXT AFTER titre;
