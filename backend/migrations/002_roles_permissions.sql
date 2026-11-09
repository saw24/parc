-- Rôles et permissions. Seul « Administrateur » est un rôle système (non supprimable, non
-- renommable, tous les droits). Les autres rôles déjà portés par des comptes (ex: « Technicien »)
-- sont repris comme rôles ordinaires, modifiables. Le rôle « Agent » n'est PAS créé ici : il
-- est créé ensuite par un administrateur, depuis l'écran des rôles.
-- t_utilisateurs.role reste la clé texte historique (compatibilité avec l'existant).

CREATE TABLE t_roles (
    id                 SERIAL PRIMARY KEY,
    nom                VARCHAR(50) NOT NULL UNIQUE,
    description        TEXT,
    systeme            BOOLEAN NOT NULL DEFAULT FALSE,
    date_creation      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_modification  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TRIGGER trg_roles_date_modification BEFORE UPDATE ON t_roles
    FOR EACH ROW EXECUTE FUNCTION set_date_modification();

CREATE TABLE t_permissions (
    code     VARCHAR(60) PRIMARY KEY,
    groupe   VARCHAR(40) NOT NULL,
    libelle  VARCHAR(160) NOT NULL,
    ordre    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE t_roles_permissions (
    role_id          INTEGER     NOT NULL REFERENCES t_roles(id) ON DELETE CASCADE,
    permission_code  VARCHAR(60) NOT NULL REFERENCES t_permissions(code) ON DELETE CASCADE ON UPDATE CASCADE,
    PRIMARY KEY (role_id, permission_code)
);

INSERT INTO t_permissions (code, groupe, libelle, ordre) VALUES
    ('tache.declarer',          'Pannes',         'Déclarer une panne (affectée à la file Service IT)', 10),
    ('tache.voir_siennes',      'Pannes',         'Voir les pannes dont on est déclarant ou assigné',   20),
    ('tache.voir_toutes',       'Pannes',         'Voir toutes les pannes',                             30),
    ('tache.creer',             'Pannes',         'Créer une tâche avec assignation libre',             40),
    ('tache.modifier_siennes',  'Pannes',         'Modifier les tâches qui nous sont assignées',        50),
    ('tache.modifier_toutes',   'Pannes',         'Modifier toutes les tâches',                         60),
    ('tache.prendre',           'Pannes',         'Prendre en charge une panne (agent IT)',             70),
    ('tache.affecter',          'Pannes',         'Affecter / réaffecter une panne à un agent',         80),
    ('tache.supprimer',         'Pannes',         'Supprimer une tâche',                                90),
    ('materiel.voir_siens',     'Parc',           'Voir le matériel qui nous est affecté',             110),
    ('materiel.voir_tous',      'Parc',           'Voir tout le matériel',                             120),
    ('materiel.creer',          'Parc',           'Ajouter du matériel',                               130),
    ('materiel.modifier',       'Parc',           'Modifier / affecter du matériel',                   140),
    ('materiel.supprimer',      'Parc',           'Supprimer du matériel',                             150),
    ('referentiel.voir',        'Référentiels',   'Voir les services et types de matériel',            210),
    ('referentiel.gerer',       'Référentiels',   'Créer / modifier services et types de matériel',    220),
    ('referentiel.supprimer',   'Référentiels',   'Supprimer services et types de matériel',           230),
    ('utilisateur.lister',      'Administration', 'Lister les utilisateurs (sélection d''un assigné)', 310),
    ('utilisateur.gerer',       'Administration', 'Créer / modifier / supprimer des utilisateurs',     320),
    ('role.gerer',              'Administration', 'Gérer les rôles et leurs permissions',              330),
    ('dashboard.voir',          'Administration', 'Voir le tableau de bord',                           340),
    ('parametre.gerer',         'Administration', 'Gérer les paramètres (SMTP, seuils, SMS…)',         350);

-- Rôle système + rôles déjà utilisés par des comptes.
INSERT INTO t_roles (nom, description, systeme)
VALUES ('Administrateur', 'Accès complet (rôle système).', TRUE);
INSERT INTO t_roles (nom, description)
SELECT DISTINCT role, 'Rôle repris à la migration : vérifier ses permissions.'
FROM t_utilisateurs WHERE role <> 'Administrateur';

INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r CROSS JOIN t_permissions p WHERE r.systeme;

-- « Technicien » : reproduit exactement ce que l'application autorisait déjà à ce rôle.
INSERT INTO t_roles_permissions (role_id, permission_code)
SELECT r.id, p.code FROM t_roles r
JOIN t_permissions p ON p.code IN (
    'tache.voir_toutes', 'tache.creer', 'tache.modifier_siennes', 'tache.prendre', 'tache.affecter',
    'materiel.voir_tous', 'materiel.creer', 'materiel.modifier',
    'referentiel.voir', 'referentiel.gerer',
    'utilisateur.lister', 'dashboard.voir')
WHERE r.nom = 'Technicien';

-- Intégrité : un compte ne peut référencer qu'un rôle existant ; renommer un rôle se répercute.
ALTER TABLE t_utilisateurs
    ADD CONSTRAINT fk_utilisateurs_role FOREIGN KEY (role) REFERENCES t_roles(nom) ON UPDATE CASCADE;

-- Un rôle système ne peut être ni supprimé ni renommé (garde-fou en base, en plus de l'API).
CREATE FUNCTION proteger_role_systeme() RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' AND OLD.systeme THEN
        RAISE EXCEPTION 'Le rôle système % ne peut pas être supprimé.', OLD.nom;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.systeme AND (NEW.nom <> OLD.nom OR NOT NEW.systeme) THEN
        RAISE EXCEPTION 'Le rôle système % ne peut pas être modifié.', OLD.nom;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_roles_protege_systeme BEFORE UPDATE OR DELETE ON t_roles
    FOR EACH ROW EXECUTE FUNCTION proteger_role_systeme();
