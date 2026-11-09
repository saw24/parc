-- Délais cibles par priorité (modifiables par un administrateur) : prise en charge et résolution,
-- en minutes. Servent à calculer les échéances des nouvelles pannes et les relances.
CREATE TABLE t_sla_regles (
    priorite                    VARCHAR(20) PRIMARY KEY CHECK (priorite IN ('Basse', 'Moyenne', 'Haute')),
    delai_prise_en_charge_min   INTEGER NOT NULL CHECK (delai_prise_en_charge_min > 0),
    delai_resolution_min        INTEGER NOT NULL CHECK (delai_resolution_min > 0)
);
INSERT INTO t_sla_regles VALUES
    ('Haute',   15,   240),
    ('Moyenne', 60,  1440),
    ('Basse',  240,  4320);
