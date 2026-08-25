-- Historique des affectations de matériel (utilisateur / service assignés dans le temps).
-- Une ligne avec date_fin = NULL représente l'affectation actuellement en cours.
-- t_materiel garde son comportement actuel inchangé (utilisateur_assigne/service_affecte
-- restent la source de vérité de l'affectation "courante") ; cette table ne fait
-- qu'enregistrer l'historique en plus, sans rien modifier à l'existant.

CREATE TABLE t_materiel_affectations (
    id                    SERIAL PRIMARY KEY,
    materiel_id           INTEGER NOT NULL REFERENCES t_materiel(id) ON DELETE CASCADE,
    utilisateur_assigne   VARCHAR(100),
    service_affecte       VARCHAR(100),
    date_debut            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_fin              TIMESTAMP,
    changed_by            VARCHAR(100)
);

CREATE INDEX idx_materiel_affectations_materiel_id ON t_materiel_affectations(materiel_id);
CREATE INDEX idx_materiel_affectations_date_fin ON t_materiel_affectations(date_fin);
