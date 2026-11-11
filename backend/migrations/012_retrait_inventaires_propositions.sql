-- Retrait des inventaires et des propositions de renouvellement (les suggestions automatiques restent).
-- Les migrations 009 et 011 restent dans l'historique ; celle-ci supprime ce qu'elles avaient créé en trop.

DROP TABLE IF EXISTS t_inventaire_lignes;
DROP TABLE IF EXISTS t_inventaires;
DROP TABLE IF EXISTS t_renouvellements;

DELETE FROM t_permissions
WHERE code LIKE 'inventaire.%' OR code IN ('renouvellement.proposer', 'renouvellement.decider');   -- cascade sur les rôles
