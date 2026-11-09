-- File commune « Service IT » : compte technique auquel sont affectées toutes les pannes
-- déclarées par les agents hors IT, en attendant qu'un agent IT la prenne. Aucune connexion
-- possible (connexion_autorisee = FALSE, mot de passe non vérifiable) et aucun droit.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM t_utilisateurs WHERE nom = 'Service IT' AND NOT est_file_commune)
       OR EXISTS (SELECT 1 FROM t_utilisateurs WHERE login = 'service_it' AND NOT est_file_commune) THEN
        RAISE EXCEPTION 'Un compte « Service IT » / service_it existe déjà et n''est pas la file commune : renommez-le avant de migrer.';
    END IF;
END $$;

INSERT INTO t_roles (nom, description)
VALUES ('Service IT', 'Compte technique de la file commune (aucune connexion, aucun droit).')
ON CONFLICT (nom) DO NOTHING;

INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe, connexion_autorisee, est_file_commune, created_by_login)
VALUES ('Service IT', 'service_it', 'Service IT', '!compte-sans-connexion', FALSE, TRUE, 'migration')
ON CONFLICT (login) DO NOTHING;

-- Une seule file commune.
CREATE UNIQUE INDEX ux_utilisateurs_file_commune ON t_utilisateurs(est_file_commune) WHERE est_file_commune;
