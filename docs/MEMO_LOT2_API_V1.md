# Lot 2 — API mobile v1 (novembre 2026)

Contrat détaillé : [API_V1.md](API_V1.md).

## Livré
- **Routeur et conventions** (`backend/api/v1/index.php`, `backend/lib/api.php`) : JSON uniforme, erreurs typées à code stable, dates ISO UTC, pagination par curseur, contrôle de version d'application (`426`).
- **Sessions par appareil** (`backend/lib/device_auth.php`, migration 007) : jeton d'accès 1 h + refresh tournant 90 jours, hachés en base, détection de rejeu avec délai de grâce de 60 s, révocation par appareil, limite de 10 appareils, anti force brute partagé avec le site web.
- **Pannes** : liste filtrable/synchronisable, détail avec historique, déclaration **idempotente** (`client_uuid`), prise en charge atomique, affectation, statut, commentaires internes ou visibles du déclarant, `actions` calculées par utilisateur, compteurs d'accueil.
- **Parc** (`backend/lib/materiel.php`) : liste/recherche, scan QR (étiquettes déjà imprimées lisibles), détail, historique d'affectation, pannes liées, création, modification partielle avec historisation, suppression — toutes soumises aux permissions et à la visibilité (« le sien » / « tout »).
- **Divers** : profil, changement de mot de passe, référentiels, liste des agents IT avec leur charge, enregistrement du jeton FCM (facultatif).

## À savoir
- La logique du matériel est écrite **à côté** de `api/materiel.php` (même règles). Elle sera unifiée quand le site web basculera sur l'API v1 (lot 6) ; d'ici là, corriger une règle des deux côtés. Écart volontaire : le numéro de série vide est enregistré `NULL` (l'ancienne API stockait `''`, ce qui bloquait un second matériel sans série), et l'ancien contrôle de doublon `23000` (MySQL) ne détectait pas l'erreur PostgreSQL `23505`.
- Le déclarant ne peut pas encore « rouvrir » une panne terminée ni confirmer la résolution (prévu avec les notifications, lot 4).
- Pas de suppression de pannes dans l'API mobile (réservé au site web, `tache.supprimer`).
- Pas de verrouillage optimiste sur le matériel (dernière écriture gagnante).
- Les URL contiennent `index.php/` ; une réécriture est décrite dans l'API_V1.md.

## Déploiement
`php backend/scripts/migrate.php up --backup …` (applique 007), puis déployer le code. Aucun changement pour le site web actuel.

## Tests
`php backend/tests/api_v1_test.php` — 98 contrôles (routage, sessions et rotation, rejeu, mot de passe, déclaration idempotente, visibilité, pagination, prise en charge, historique, parc, scan QR, limitation des tentatives). Données `zz_v1_*` supprimées en fin d'exécution. Ne jamais lancer en production.
