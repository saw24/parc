-- Renouvellement du matériel : propositions (automatiques ou manuelles) soumises à décision.
--
-- Durée de vie : par type de matériel (NULL = durée par défaut du code, 60 mois).
-- Une proposition suit le cycle Proposé → Validé → Commandé → Remplacé, ou Refusé.
-- Un matériel ne peut avoir qu'une seule proposition « active » à la fois.

ALTER TABLE t_type_materiel ADD COLUMN duree_vie_mois SMALLINT CHECK (duree_vie_mois IS NULL OR duree_vie_mois BETWEEN 6 AND 240);

CREATE TABLE t_renouvellements (
    id                 SERIAL PRIMARY KEY,
    materiel_id        INTEGER      NOT NULL REFERENCES t_materiel(id) ON DELETE CASCADE,
    origine            VARCHAR(10)  NOT NULL DEFAULT 'manuelle' CHECK (origine IN ('auto', 'manuelle')),
    criteres           TEXT         NOT NULL DEFAULT '[]',   -- JSON : raisons détectées (âge, pannes…)
    motif              TEXT,
    cout_estime        NUMERIC(12, 2) CHECK (cout_estime IS NULL OR cout_estime >= 0),
    statut             VARCHAR(10)  NOT NULL DEFAULT 'Proposé' CHECK (statut IN ('Proposé', 'Validé', 'Refusé', 'Commandé', 'Remplacé')),
    propose_par        INTEGER      REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    decide_par         INTEGER      REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    decide_at          TIMESTAMP,
    commentaire        TEXT,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_renouvellement_actif ON t_renouvellements(materiel_id) WHERE statut IN ('Proposé', 'Validé', 'Commandé');
CREATE INDEX idx_renouvellement_statut ON t_renouvellements(statut, created_at DESC);

INSERT INTO t_permissions (code, groupe, libelle, ordre) VALUES
    ('renouvellement.voir',     'Parc', 'Voir les propositions de renouvellement du matériel', 160),
    ('renouvellement.proposer', 'Parc', 'Proposer le renouvellement d''un matériel',           170),
    ('renouvellement.decider',  'Parc', 'Valider ou refuser un renouvellement',                180);

-- L'administrateur (rôle système) reçoit tout ; les techniciens peuvent voir et proposer.
INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r CROSS JOIN t_permissions p
WHERE r.systeme AND p.code LIKE 'renouvellement.%';
INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r CROSS JOIN t_permissions p
WHERE r.nom = 'Technicien' AND p.code IN ('renouvellement.voir', 'renouvellement.proposer');
