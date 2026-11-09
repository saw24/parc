-- Cycle de vie des pannes : identifiants (au lieu de noms), déclarant, matériel lié, dates de
-- prise en charge / résolution, échéances SLA, historique des évènements.
-- Approche « élargir » : t_taches.assigne_a / materiel_concerne / created_by_login restent en place
-- et continuent d'être renseignés ; les nouvelles colonnes s'y ajoutent.

ALTER TABLE t_taches
    ADD COLUMN reference                 VARCHAR(20) UNIQUE,
    ADD COLUMN declarant_id              INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    ADD COLUMN assigne_id                INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    ADD COLUMN materiel_id               INTEGER REFERENCES t_materiel(id)     ON DELETE SET NULL,
    ADD COLUMN source                    VARCHAR(10) NOT NULL DEFAULT 'web'
                                         CHECK (source IN ('web', 'mobile', 'systeme')),
    ADD COLUMN date_prise_en_charge      TIMESTAMP,
    ADD COLUMN date_resolution           TIMESTAMP,
    ADD COLUMN echeance_prise_en_charge  TIMESTAMP,
    ADD COLUMN echeance_resolution       TIMESTAMP,
    ADD COLUMN version                   INTEGER NOT NULL DEFAULT 1;

-- Référence lisible (T-2026-000123), construite à partir de l'id à l'insertion.
CREATE FUNCTION tache_definir_reference() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.reference IS NULL THEN
        NEW.reference := 'T-' || to_char(COALESCE(NEW.date_creation, CURRENT_TIMESTAMP), 'YYYY')
                         || '-' || lpad(NEW.id::text, 6, '0');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_taches_reference BEFORE INSERT ON t_taches
    FOR EACH ROW EXECUTE FUNCTION tache_definir_reference();

-- date_modification était figée à la création (aucun trigger) : les indicateurs « terminées
-- aujourd'hui », « tâche dormante » et le classement des résolveurs s'appuyaient dessus.
CREATE TRIGGER trg_taches_date_modification BEFORE UPDATE ON t_taches
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();

-- Reprise des données existantes.
UPDATE t_taches SET reference = 'T-' || to_char(date_creation, 'YYYY') || '-' || lpad(id::text, 6, '0')
WHERE reference IS NULL;

UPDATE t_taches t SET assigne_id = u.id
FROM t_utilisateurs u WHERE u.nom = t.assigne_a;

UPDATE t_taches t SET declarant_id = u.id
FROM t_utilisateurs u WHERE u.login = t.created_by_login;

-- Matériel : t_taches.materiel_concerne est un texte « type marque modèle (n° série) ».
-- On ne lie que si exactement un matériel correspond au numéro de série cité (jamais de devinette).
UPDATE t_taches t SET materiel_id = m.id
FROM t_materiel m
WHERE t.materiel_id IS NULL
  AND NULLIF(TRIM(m.num_serie), '') IS NOT NULL
  AND t.materiel_concerne LIKE '%(' || m.num_serie || ')%'
  AND (SELECT COUNT(*) FROM t_materiel m2
       WHERE NULLIF(TRIM(m2.num_serie), '') IS NOT NULL
         AND t.materiel_concerne LIKE '%(' || m2.num_serie || ')%') = 1;

ALTER TABLE t_taches ALTER COLUMN reference SET NOT NULL;

CREATE INDEX idx_taches_assigne_id  ON t_taches(assigne_id);
CREATE INDEX idx_taches_declarant_id ON t_taches(declarant_id);
CREATE INDEX idx_taches_materiel_id ON t_taches(materiel_id);
CREATE INDEX idx_taches_statut      ON t_taches(statut);

-- Historique : création, prise en charge, affectation, changement de statut, commentaire.
CREATE TABLE t_taches_evenements (
    id                     SERIAL PRIMARY KEY,
    tache_id               INTEGER NOT NULL REFERENCES t_taches(id) ON DELETE CASCADE,
    type                   VARCHAR(30) NOT NULL
                           CHECK (type IN ('creation', 'prise_en_charge', 'affectation', 'statut',
                                           'commentaire', 'modification', 'relance', 'escalade')),
    acteur_id              INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    acteur_nom             VARCHAR(100),
    ancien_statut          VARCHAR(20),
    nouveau_statut         VARCHAR(20),
    ancien_assigne_id      INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    nouveau_assigne_id     INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    commentaire            TEXT,
    visible_declarant      BOOLEAN NOT NULL DEFAULT TRUE,
    date_creation          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_taches_evenements_tache ON t_taches_evenements(tache_id, date_creation);

-- Évènement de création rétroactif pour les tâches existantes (frise chronologique non vide).
INSERT INTO t_taches_evenements (tache_id, type, acteur_id, acteur_nom, nouveau_statut, nouveau_assigne_id, date_creation)
SELECT t.id, 'creation', t.declarant_id, t.created_by_login, t.statut, t.assigne_id, t.date_creation
FROM t_taches t;
