-- Script pour créer la table t_sessions

CREATE TABLE IF NOT EXISTS t_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    expiry_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES t_utilisateurs(id) ON DELETE CASCADE
);

-- Index pour optimiser les requêtes
CREATE INDEX idx_session_token ON t_sessions(session_token);
CREATE INDEX idx_user_id ON t_sessions(user_id);
CREATE INDEX idx_expiry_time ON t_sessions(expiry_time);

-- Nettoyer les sessions expirées (optionnel - peut être fait par un cron job)
DELETE FROM t_sessions WHERE expiry_time < NOW();
