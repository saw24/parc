-- Table de paramètres applicatifs (clé/valeur), pour éviter le codage en dur des seuils
-- métier, des fréquences de rappel et de la configuration SMTP.
-- Gérée depuis l'écran "Paramètres" (frontend), réservé aux comptes Administrateur
-- (voir backend/api/parametres.php). Les clés absentes de cette table retombent sur les
-- valeurs par défaut définies dans backend/config/parametres.php.

CREATE TABLE t_parametres (
    cle                VARCHAR(100) PRIMARY KEY,
    valeur              TEXT,
    date_modification   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by_login    VARCHAR(100)
);
