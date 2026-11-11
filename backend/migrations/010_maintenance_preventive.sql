-- Maintenance préventive : plans récurrents qui génèrent automatiquement des tâches avant chaque échéance,
-- et date de fin de garantie du matériel (alerte de renouvellement).
--
-- Un plan vise soit un matériel précis, soit tous les matériels d'un type (hors « Retiré »).
-- Le script backend/scripts/maintenance.php (cron quotidien) crée les tâches à J - anticipation_jours ;
-- t_plan_occurrences garantit qu'une échéance n'est jamais générée deux fois pour un même matériel.

ALTER TABLE t_materiel ADD COLUMN date_fin_garantie DATE;

CREATE TABLE t_plans_maintenance (
    id                   SERIAL PRIMARY KEY,
    titre                VARCHAR(200) NOT NULL,
    description          TEXT,
    materiel_id          INTEGER REFERENCES t_materiel(id) ON DELETE CASCADE,
    type_materiel        VARCHAR(100),
    periodicite_valeur   SMALLINT     NOT NULL CHECK (periodicite_valeur BETWEEN 1 AND 120),
    periodicite_unite    VARCHAR(8)   NOT NULL CHECK (periodicite_unite IN ('jour', 'semaine', 'mois', 'an')),
    prochaine_echeance   DATE         NOT NULL,
    anticipation_jours   SMALLINT     NOT NULL DEFAULT 7 CHECK (anticipation_jours BETWEEN 0 AND 120),
    priorite             VARCHAR(10)  NOT NULL DEFAULT 'Moyenne',
    assigne_id           INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,   -- NULL = file Service IT
    checklist            TEXT,                                                       -- une ligne = un point à vérifier
    actif                BOOLEAN      NOT NULL DEFAULT TRUE,
    derniere_generation  TIMESTAMP,
    created_by           INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((materiel_id IS NOT NULL) <> (type_materiel IS NOT NULL))                 -- une seule cible
);
CREATE INDEX idx_plans_echeance ON t_plans_maintenance(prochaine_echeance) WHERE actif;

CREATE TABLE t_plan_occurrences (
    id           SERIAL PRIMARY KEY,
    plan_id      INTEGER NOT NULL REFERENCES t_plans_maintenance(id) ON DELETE CASCADE,
    materiel_id  INTEGER NOT NULL REFERENCES t_materiel(id) ON DELETE CASCADE,
    echeance     DATE    NOT NULL,
    tache_id     INTEGER REFERENCES t_taches(id) ON DELETE SET NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (plan_id, materiel_id, echeance)
);

INSERT INTO t_permissions (code, groupe, libelle, ordre) VALUES
    ('maintenance.voir',   'Parc', 'Voir les plans de maintenance préventive',          190),
    ('maintenance.gerer',  'Parc', 'Créer / modifier / supprimer des plans de maintenance', 195);

INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r CROSS JOIN t_permissions p
WHERE r.systeme AND p.code LIKE 'maintenance.%';
INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, 'maintenance.voir' FROM t_roles r WHERE r.nom = 'Technicien';
