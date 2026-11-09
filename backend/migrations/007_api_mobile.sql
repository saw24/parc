-- API mobile (v1) : sessions par appareil et idempotence des créations hors ligne.
--
-- Chaque appareil possède une session révocable : un jeton d'accès court (1 h) et un jeton de
-- rafraîchissement long (90 jours) qui tourne à chaque usage. Seuls des hachés SHA-256 sont
-- stockés. refresh_precedent_hash sert à détecter le rejeu d'un ancien jeton (vol possible).
CREATE TABLE t_appareils (
    id                      SERIAL PRIMARY KEY,
    user_id                 INTEGER     NOT NULL REFERENCES t_utilisateurs(id) ON DELETE CASCADE,
    nom                     VARCHAR(100),
    plateforme              VARCHAR(20) NOT NULL DEFAULT 'android',
    version_app             VARCHAR(20),
    access_hash             CHAR(64)    NOT NULL UNIQUE,
    access_expire           TIMESTAMP   NOT NULL,
    refresh_hash            CHAR(64)    NOT NULL UNIQUE,
    refresh_expire          TIMESTAMP   NOT NULL,
    refresh_precedent_hash  CHAR(64),
    refresh_tourne_le       TIMESTAMP,
    fcm_token               VARCHAR(255),
    ip                      VARCHAR(45),
    derniere_activite       TIMESTAMP,
    revoque_at              TIMESTAMP,
    revoque_motif           VARCHAR(40),
    created_at              TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_appareils_user ON t_appareils(user_id) WHERE revoque_at IS NULL;
CREATE INDEX idx_appareils_precedent ON t_appareils(refresh_precedent_hash) WHERE refresh_precedent_hash IS NOT NULL;

-- Identifiant généré par l'application à la saisie : rejouer l'envoi d'une panne créée hors ligne
-- (réponse perdue, nouvelle tentative) ne crée jamais de doublon.
ALTER TABLE t_taches ADD COLUMN client_uuid UUID UNIQUE;
