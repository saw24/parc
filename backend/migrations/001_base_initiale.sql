-- Schéma de référence au 09/11/2026. Idempotent : crée ce qui manque, complète ce qui est
-- incomplet, et ne touche pas aux données existantes. S'applique sur une base vide comme sur
-- une base PostgreSQL déjà en service.

CREATE OR REPLACE FUNCTION set_date_modification()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_modification = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TABLE IF NOT EXISTS t_utilisateurs (
    id                 SERIAL PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL,
    login              VARCHAR(100) NOT NULL UNIQUE,
    email              VARCHAR(255),
    role               VARCHAR(50)  NOT NULL,
    mot_de_passe       VARCHAR(255) NOT NULL,
    date_creation      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by_login   VARCHAR(100),
    created_by_login   VARCHAR(100),
    date_modification  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE t_utilisateurs ADD COLUMN IF NOT EXISTS email VARCHAR(255);

CREATE TABLE IF NOT EXISTS t_services (
    id                 SERIAL PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL UNIQUE,
    description        TEXT,
    updated_by_login   VARCHAR(100),
    created_by_login   VARCHAR(100),
    date_creation      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_modification  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS t_materiel (
    id                    SERIAL PRIMARY KEY,
    type                  VARCHAR(100) NOT NULL,
    marque                VARCHAR(100),
    modele                VARCHAR(100),
    num_serie             VARCHAR(100) UNIQUE,
    date_achat            DATE,
    statut                VARCHAR(50) NOT NULL,
    service_affecte       VARCHAR(100),
    utilisateur_assigne   VARCHAR(100),
    updated_by_login      VARCHAR(100),
    created_by_login      VARCHAR(100),
    date_creation         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_modification     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_derniere_affect  DATE
);
-- Base existante antérieure à cette colonne : ajout puis reprise de la date d'achat comme
-- meilleure approximation, uniquement si la colonne vient d'être créée.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_name = 't_materiel' AND column_name = 'date_derniere_affect') THEN
        ALTER TABLE t_materiel ADD COLUMN date_derniere_affect DATE;
        UPDATE t_materiel SET date_derniere_affect = date_achat WHERE date_derniere_affect IS NULL;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS t_materiel_affectations (
    id                    SERIAL PRIMARY KEY,
    materiel_id           INTEGER NOT NULL REFERENCES t_materiel(id) ON DELETE CASCADE,
    utilisateur_assigne   VARCHAR(100),
    service_affecte       VARCHAR(100),
    date_debut            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_fin              TIMESTAMP,
    changed_by            VARCHAR(100)
);
CREATE INDEX IF NOT EXISTS idx_materiel_affectations_materiel_id ON t_materiel_affectations(materiel_id);
CREATE INDEX IF NOT EXISTS idx_materiel_affectations_date_fin ON t_materiel_affectations(date_fin);

CREATE TABLE IF NOT EXISTS t_type_materiel (
    id    SERIAL PRIMARY KEY,
    type  VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS t_taches (
    id                  SERIAL PRIMARY KEY,
    titre               VARCHAR(255) NOT NULL,
    motif_cause         TEXT,
    description         TEXT,
    priorite            VARCHAR(20) CHECK (priorite IN ('Basse','Moyenne','Haute')),
    statut              VARCHAR(20),
    assigne_a           VARCHAR(100),
    materiel_concerne   VARCHAR(255),
    service_concerne    VARCHAR(100),
    motif_attente       TEXT,
    date_relance        DATE,
    date_limite         DATE,
    date_creation       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_modification   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actions             TEXT,
    updated_by_login    VARCHAR(100),
    created_by_login    VARCHAR(100)
);
ALTER TABLE t_taches ADD COLUMN IF NOT EXISTS motif_cause   TEXT;
ALTER TABLE t_taches ADD COLUMN IF NOT EXISTS motif_attente TEXT;
ALTER TABLE t_taches ADD COLUMN IF NOT EXISTS date_relance  DATE;

-- Contrainte de statut : on supprime toute CHECK portant sur « statut » (nom variable selon
-- l'historique de la base) puis on pose la version à jour, avec « En attente ».
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT c.conname FROM pg_constraint c
             WHERE c.conrelid = 't_taches'::regclass AND c.contype = 'c'
               AND pg_get_constraintdef(c.oid) ILIKE '%statut%'
    LOOP
        EXECUTE format('ALTER TABLE t_taches DROP CONSTRAINT %I', r.conname);
    END LOOP;
    ALTER TABLE t_taches ADD CONSTRAINT t_taches_statut_check
        CHECK (statut IN ('À faire','En cours','En attente','Terminé','Annulé'));
END $$;

CREATE TABLE IF NOT EXISTS t_sessions (
    id             SERIAL PRIMARY KEY,
    user_id        INTEGER NOT NULL REFERENCES t_utilisateurs(id) ON DELETE CASCADE,
    session_token  VARCHAR(64) NOT NULL UNIQUE,
    expiry_time    TIMESTAMP NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_session_token ON t_sessions(session_token);
CREATE INDEX IF NOT EXISTS idx_user_id ON t_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_expiry_time ON t_sessions(expiry_time);

CREATE TABLE IF NOT EXISTS t_parametres (
    cle                VARCHAR(100) PRIMARY KEY,
    valeur             TEXT,
    date_modification  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by_login   VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS t_login_attempts (
    id          SERIAL PRIMARY KEY,
    login       VARCHAR(100) NOT NULL,
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    succes      BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_login ON t_login_attempts(login, created_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON t_login_attempts(ip, created_at);

-- Triggers « date_modification » (PostgreSQL n'a pas ON UPDATE CURRENT_TIMESTAMP).
DROP TRIGGER IF EXISTS trg_utilisateurs_date_modification ON t_utilisateurs;
CREATE TRIGGER trg_utilisateurs_date_modification BEFORE UPDATE ON t_utilisateurs
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();
DROP TRIGGER IF EXISTS trg_services_date_modification ON t_services;
CREATE TRIGGER trg_services_date_modification BEFORE UPDATE ON t_services
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();
DROP TRIGGER IF EXISTS trg_materiel_date_modification ON t_materiel;
CREATE TRIGGER trg_materiel_date_modification BEFORE UPDATE ON t_materiel
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();

-- Séquences SERIAL parfois restées en retard sur MAX(id) après l'import MySQL → PostgreSQL
-- (erreurs « duplicate key » intermittentes). On les aligne, sans jamais les faire reculer.
DO $$
DECLARE t TEXT; seq TEXT; maxid BIGINT; courant BIGINT;
BEGIN
    FOREACH t IN ARRAY ARRAY['t_utilisateurs','t_services','t_materiel','t_type_materiel',
                             't_taches','t_sessions','t_materiel_affectations','t_login_attempts']
    LOOP
        seq := pg_get_serial_sequence(t, 'id');
        EXECUTE format('SELECT COALESCE(MAX(id), 0) FROM %I', t) INTO maxid;
        EXECUTE format('SELECT last_value FROM %s', seq) INTO courant;
        IF maxid > courant THEN
            PERFORM setval(seq, maxid, true);
        END IF;
    END LOOP;
END $$;
