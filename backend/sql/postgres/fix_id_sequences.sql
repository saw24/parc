-- Corrige un désalignement des séquences SERIAL découvert le 25/08/2026 : plusieurs
-- tables avaient leur séquence bien en retard sur leur MAX(id) réel (ex: t_materiel
-- avait 220 lignes mais une séquence encore à 2), probablement hérité de la migration
-- MySQL -> PostgreSQL (données importées avec ids explicites, séquences jamais
-- resynchronisées après import). Conséquence : toute création (utilisateur, service,
-- matériel, type de matériel, tâche, session) pouvait échouer par intermittence avec une
-- erreur "duplicate key value violates unique constraint ..._pkey", jusqu'à ce que la
-- séquence rattrape le MAX(id) au fil des tentatives (PostgreSQL avance nextval() même
-- sur un INSERT qui échoue ensuite).
--
-- Sûr à ré-exécuter : ne fait qu'avancer une séquence déjà à jour ou en avance, jamais la
-- reculer (GREATEST(MAX(id), 1) borne la valeur basse).

SELECT setval(pg_get_serial_sequence('t_utilisateurs', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_utilisateurs), 1), (SELECT COUNT(*) > 0 FROM t_utilisateurs));
SELECT setval(pg_get_serial_sequence('t_services', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_services), 1), (SELECT COUNT(*) > 0 FROM t_services));
SELECT setval(pg_get_serial_sequence('t_materiel', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_materiel), 1), (SELECT COUNT(*) > 0 FROM t_materiel));
SELECT setval(pg_get_serial_sequence('t_type_materiel', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_type_materiel), 1), (SELECT COUNT(*) > 0 FROM t_type_materiel));
SELECT setval(pg_get_serial_sequence('t_taches', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_taches), 1), (SELECT COUNT(*) > 0 FROM t_taches));
SELECT setval(pg_get_serial_sequence('t_sessions', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_sessions), 1), (SELECT COUNT(*) > 0 FROM t_sessions));
SELECT setval(pg_get_serial_sequence('t_materiel_affectations', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM t_materiel_affectations), 1), (SELECT COUNT(*) > 0 FROM t_materiel_affectations));
