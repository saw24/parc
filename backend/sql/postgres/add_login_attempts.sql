-- Limitation des tentatives de connexion (anti force brute).
-- Une ligne par tentative ; auth.php::login bloque un login (ou une IP) après trop d'échecs
-- récents et purge les lignes anciennes. Script idempotent.
CREATE TABLE IF NOT EXISTS t_login_attempts (
    id          SERIAL PRIMARY KEY,
    login       VARCHAR(100) NOT NULL,
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    succes      BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_login ON t_login_attempts(login, created_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON t_login_attempts(ip, created_at);
