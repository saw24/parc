-- Campagnes d'inventaire : on fige la liste du matériel attendu dans un périmètre (service et/ou type),
-- puis on pointe chaque matériel (scan QR sur mobile) : présent, déplacé ou manquant.
-- Le matériel scanné mais absent de la liste est conservé (hors périmètre), de même qu'un code inconnu (non référencé).

CREATE TABLE t_inventaires (
    id             SERIAL PRIMARY KEY,
    titre          VARCHAR(200) NOT NULL,
    description    TEXT,
    service_nom    VARCHAR(100),            -- périmètre : matériel affecté à ce service (NULL = tous)
    type_materiel  VARCHAR(100),            -- périmètre : ce type de matériel (NULL = tous)
    date_limite    DATE,
    statut         VARCHAR(8) NOT NULL DEFAULT 'Ouvert' CHECK (statut IN ('Ouvert', 'Clos')),
    created_by     INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clos_par       INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    clos_at        TIMESTAMP
);

CREATE TABLE t_inventaire_lignes (
    id              SERIAL PRIMARY KEY,
    inventaire_id   INTEGER NOT NULL REFERENCES t_inventaires(id) ON DELETE CASCADE,
    materiel_id     INTEGER REFERENCES t_materiel(id) ON DELETE CASCADE,   -- NULL = code non référencé
    code_scanne     VARCHAR(500),
    statut          VARCHAR(14) NOT NULL DEFAULT 'À pointer'
                    CHECK (statut IN ('À pointer', 'Présent', 'Déplacé', 'Manquant', 'Non référencé')),
    hors_perimetre  BOOLEAN NOT NULL DEFAULT FALSE,
    observation     TEXT,
    pointe_par      INTEGER REFERENCES t_utilisateurs(id) ON DELETE SET NULL,
    pointe_at       TIMESTAMP,
    CHECK (materiel_id IS NOT NULL OR code_scanne IS NOT NULL)
);
CREATE UNIQUE INDEX idx_inv_ligne_materiel ON t_inventaire_lignes(inventaire_id, materiel_id) WHERE materiel_id IS NOT NULL;
CREATE UNIQUE INDEX idx_inv_ligne_code ON t_inventaire_lignes(inventaire_id, code_scanne) WHERE materiel_id IS NULL;
CREATE INDEX idx_inv_lignes_statut ON t_inventaire_lignes(inventaire_id, statut);

INSERT INTO t_permissions (code, groupe, libelle, ordre) VALUES
    ('inventaire.voir',    'Parc', 'Voir les campagnes d''inventaire',                       200),
    ('inventaire.pointer', 'Parc', 'Pointer le matériel lors d''un inventaire (scan QR)',    205),
    ('inventaire.gerer',   'Parc', 'Créer, clore et supprimer des campagnes d''inventaire',  210);

INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r CROSS JOIN t_permissions p WHERE r.systeme AND p.code LIKE 'inventaire.%';
INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r CROSS JOIN t_permissions p
WHERE r.nom = 'Technicien' AND p.code IN ('inventaire.voir', 'inventaire.pointer');
