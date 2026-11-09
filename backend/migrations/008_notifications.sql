-- Notifications : boîte de réception dans l'application + file d'envoi par canal (e-mail, SMS, push).
--
-- Un évènement de panne (t_taches_evenements) produit des notifications pour les personnes
-- concernées. notifie_at marque les évènements déjà traités : le traitement est donc rejouable
-- sans doublon, et indépendant du code qui a créé l'évènement (site web ou API mobile).

ALTER TABLE t_taches_evenements ADD COLUMN notifie_at TIMESTAMP;
-- Les évènements existants ne doivent pas déclencher de notifications rétroactives.
UPDATE t_taches_evenements SET notifie_at = CURRENT_TIMESTAMP;
CREATE INDEX idx_evenements_a_notifier ON t_taches_evenements(id) WHERE notifie_at IS NULL;

CREATE TABLE t_notifications (
    id            BIGSERIAL PRIMARY KEY,
    user_id       INTEGER     NOT NULL REFERENCES t_utilisateurs(id) ON DELETE CASCADE,
    tache_id      INTEGER     NOT NULL REFERENCES t_taches(id) ON DELETE CASCADE,
    evenement_id  INTEGER     NOT NULL REFERENCES t_taches_evenements(id) ON DELETE CASCADE,
    type          VARCHAR(30) NOT NULL,
    titre         VARCHAR(200) NOT NULL,
    message       TEXT        NOT NULL,
    prenable      BOOLEAN     NOT NULL DEFAULT FALSE,   -- propose « Je prends » (nouvelle panne en file)
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lu_at         TIMESTAMP,
    UNIQUE (user_id, evenement_id)
);
CREATE INDEX idx_notifications_user ON t_notifications(user_id, id DESC);
CREATE INDEX idx_notifications_non_lues ON t_notifications(user_id) WHERE lu_at IS NULL;

-- Une ligne par canal et par destinataire ; reprise automatique avec délai croissant.
CREATE TABLE t_notifications_envois (
    id               BIGSERIAL PRIMARY KEY,
    notification_id  BIGINT      NOT NULL REFERENCES t_notifications(id) ON DELETE CASCADE,
    canal            VARCHAR(10) NOT NULL CHECK (canal IN ('email', 'sms', 'push')),
    destinataire     VARCHAR(255) NOT NULL,              -- adresse, numéro ou jeton FCM
    statut           VARCHAR(12) NOT NULL DEFAULT 'en_attente' CHECK (statut IN ('en_attente', 'envoye', 'echec')),
    tentatives       SMALLINT    NOT NULL DEFAULT 0,
    erreur           TEXT,
    prochain_essai   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    envoye_at        TIMESTAMP,
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_envois_a_faire ON t_notifications_envois(prochain_essai) WHERE statut = 'en_attente';

-- Préférences de chaque agent (le consentement SMS/e-mail se règle depuis son profil).
ALTER TABLE t_utilisateurs ADD COLUMN notif_email BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE t_utilisateurs ADD COLUMN notif_sms   BOOLEAN NOT NULL DEFAULT TRUE;
