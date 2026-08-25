-- Ajoute le suivi de la date de dernière affectation d'un matériel
-- (utilisateur/service). Colonne éditable manuellement par l'utilisateur lors
-- de la confirmation d'affectation (voir backend/api/materiel.php), donc
-- distincte de l'horodatage automatique de t_materiel_affectations.

ALTER TABLE t_materiel ADD COLUMN date_derniere_affect DATE;

-- Migration des données existantes : à défaut de mieux, on part de la date
-- d'achat comme date de dernière affectation connue (uniquement pour les
-- lignes qui n'ont pas encore de valeur, migration idempotente).
UPDATE t_materiel
SET date_derniere_affect = date_achat
WHERE date_derniere_affect IS NULL;
