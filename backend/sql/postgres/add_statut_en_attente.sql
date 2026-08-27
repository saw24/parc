-- ==========================================================
-- Ajoute le statut "En attente" aux tâches, avec le motif d'attente
-- et la date de relance.
-- PostgreSQL : la contrainte CHECK sur `statut` est une contrainte nommée
-- et ne peut pas être altérée — on la supprime puis on la recrée élargie.
-- Le script est idempotent (rejouable sans erreur).
-- ==========================================================

-- 1. Nouvelles colonnes (idempotent)
ALTER TABLE t_taches ADD COLUMN IF NOT EXISTS motif_attente TEXT;
ALTER TABLE t_taches ADD COLUMN IF NOT EXISTS date_relance    DATE;

-- 2. Supprime TOUTES les contraintes CHECK qui portent sur la colonne `statut`
--    (le nom auto-généré peut varier selon la version) puis recrée une CHECK élargie.
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT c.conname
        FROM pg_constraint c
        WHERE c.conrelid = 't_taches'::regclass
          AND c.contype = 'c'
          AND pg_get_constraintdef(c.oid) ILIKE '%statut%'
    LOOP
        EXECUTE format('ALTER TABLE t_taches DROP CONSTRAINT %I', r.conname);
    END LOOP;
END $$;

-- Vérifie que la contrainte n'existe pas déjà avant de la créer (idempotence)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 't_taches'::regclass
          AND conname = 't_taches_statut_check'
    ) THEN
        ALTER TABLE t_taches
            ADD CONSTRAINT t_taches_statut_check
            CHECK (statut IN ('À faire', 'En cours', 'En attente', 'Terminé', 'Annulé'));
    END IF;
END $$;
