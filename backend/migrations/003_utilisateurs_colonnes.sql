-- Colonnes utilisateur nécessaires au mobile et à la file « Service IT ».
-- Les noms d'utilisateurs deviennent uniques : t_taches.assigne_a les désigne encore par leur
-- nom. S'il existe des homonymes, la migration s'arrête avec la liste (voir `migrate.php preflight`).

DO $$
DECLARE doublons TEXT;
BEGIN
    SELECT string_agg(nom || ' (' || c || ')', ', ') INTO doublons
    FROM (SELECT nom, COUNT(*) c FROM t_utilisateurs GROUP BY nom HAVING COUNT(*) > 1) d;
    IF doublons IS NOT NULL THEN
        RAISE EXCEPTION 'Utilisateurs homonymes à renommer avant migration : %', doublons;
    END IF;
END $$;

CREATE UNIQUE INDEX ux_utilisateurs_nom ON t_utilisateurs(nom);

ALTER TABLE t_utilisateurs
    ADD COLUMN telephone           VARCHAR(30),
    ADD COLUMN service_id          INTEGER REFERENCES t_services(id) ON DELETE SET NULL,
    ADD COLUMN actif               BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN connexion_autorisee BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN est_file_commune    BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX idx_utilisateurs_service_id ON t_utilisateurs(service_id);
