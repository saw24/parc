-- Schéma PostgreSQL pour la base "taches" (PARC IT)
-- Équivalent fonctionnel du schéma MySQL d'origine (backend/sql/parc_info.sql),
-- adapté à PostgreSQL : SERIAL au lieu d'AUTO_INCREMENT, CHECK au lieu d'ENUM,
-- trigger pour émuler "ON UPDATE CURRENT_TIMESTAMP" (absent nativement en PostgreSQL).

-- ==========================================================
-- Fonction générique : met à jour date_modification à chaque UPDATE
-- (équivalent de MySQL: ... ON UPDATE CURRENT_TIMESTAMP)
-- ==========================================================
CREATE OR REPLACE FUNCTION set_date_modification()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_modification = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ==========================================================
-- t_utilisateurs
-- ==========================================================
CREATE TABLE t_utilisateurs (
    id                 SERIAL PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL,
    login              VARCHAR(100) NOT NULL UNIQUE,
    role               VARCHAR(50)  NOT NULL,
    mot_de_passe       VARCHAR(255) NOT NULL,
    date_creation      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by_login   VARCHAR(100),
    created_by_login   VARCHAR(100),
    date_modification  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER trg_utilisateurs_date_modification
    BEFORE UPDATE ON t_utilisateurs
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();

-- ==========================================================
-- t_services
-- ==========================================================
CREATE TABLE t_services (
    id                 SERIAL PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL UNIQUE,
    description        TEXT,
    updated_by_login   VARCHAR(100),
    created_by_login   VARCHAR(100),
    date_creation      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_modification  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER trg_services_date_modification
    BEFORE UPDATE ON t_services
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();

-- ==========================================================
-- t_materiel
-- ==========================================================
CREATE TABLE t_materiel (
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
    date_modification     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER trg_materiel_date_modification
    BEFORE UPDATE ON t_materiel
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();

-- ==========================================================
-- t_type_materiel
-- ==========================================================
CREATE TABLE t_type_materiel (
    id    SERIAL PRIMARY KEY,
    type  VARCHAR(100) NOT NULL
);

-- ==========================================================
-- t_taches
-- (date_modification n'a PAS de trigger : dans le schéma MySQL d'origine,
--  cette colonne n'avait pas ON UPDATE CURRENT_TIMESTAMP non plus — le code
--  PHP ne la met jamais à jour explicitement. Comportement préservé à l'identique.)
-- ==========================================================
CREATE TABLE t_taches (
    id                  SERIAL PRIMARY KEY,
    titre               VARCHAR(255) NOT NULL,
    motif_cause         TEXT,
    description         TEXT,
    priorite            VARCHAR(20) CHECK (priorite IN ('Basse','Moyenne','Haute')),
    statut              VARCHAR(20) CHECK (statut IN ('À faire','En cours','Terminé','Annulé')),
    assigne_a           VARCHAR(100),
    materiel_concerne   VARCHAR(255),
    service_concerne    VARCHAR(100),
    date_limite         DATE,
    date_creation       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_modification   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actions             TEXT,
    updated_by_login    VARCHAR(100),
    created_by_login    VARCHAR(100)
);

-- ==========================================================
-- t_sessions
-- ==========================================================
CREATE TABLE t_sessions (
    id             SERIAL PRIMARY KEY,
    user_id        INTEGER NOT NULL REFERENCES t_utilisateurs(id) ON DELETE CASCADE,
    session_token  VARCHAR(64) NOT NULL UNIQUE,
    expiry_time    TIMESTAMP NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_session_token ON t_sessions(session_token);
CREATE INDEX idx_user_id ON t_sessions(user_id);
CREATE INDEX idx_expiry_time ON t_sessions(expiry_time);
