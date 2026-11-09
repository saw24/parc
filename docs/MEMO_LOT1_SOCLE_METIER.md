# Lot 1 — Socle métier (novembre 2026)

## Livré
| Élément | Détail |
|---|---|
| Exécuteur de migrations | `backend/lib/Migrator.php` + `php backend/scripts/migrate.php status \| preflight \| up [--dry-run] [--backup] [--backup-dir=…]`. Transaction par fichier, empreinte SHA-256 (une migration livrée ne peut plus être modifiée), verrou anti-exécution simultanée, validation à blanc sur les vraies données. |
| Migrations 001–006 | 001 schéma de référence (idempotent : base vide **ou** base PostgreSQL existante) · 002 rôles/permissions · 003 colonnes utilisateur (téléphone, service, actif, connexion autorisée, file commune) + unicité des noms · 004 cycle de vie des pannes · 005 règles SLA · 006 compte « Service IT » |
| Rôles | Seul **Administrateur** est un rôle système (toutes les permissions, y compris futures ; non supprimable ni renommable, garde-fou en base). **Technicien** est repris comme rôle ordinaire avec exactement ses droits actuels. **Agent n'est pas créé** : à créer depuis l'écran des rôles (lot 6) ou en SQL après migration. |
| Permissions | 22 codes (pannes, parc, référentiels, administration). `require_permission()` remplace `require_role()` dans toutes les API ; `permissions` est renvoyé au login et à `check_session`. |
| Pannes | `reference` (T-AAAA-NNNNNN), `declarant_id`, `assigne_id`, `materiel_id`, `source`, `date_prise_en_charge`, `date_resolution`, échéances SLA, `version` ; historique `t_taches_evenements` (création, prise en charge, affectation, statut, commentaire). |
| File « Service IT » | Compte technique sans connexion. Un utilisateur qui peut seulement **déclarer** (sans `tache.creer`) voit sa panne affectée d'office à la file, statut « À faire » ; assigné et statut envoyés par le client sont ignorés. |
| `tache_transition()` | `backend/lib/taches.php` : prendre / affecter / statut / commenter, en une transaction avec verrou de ligne, contrôle de version (409) et historique. Testé avec 6 prises simultanées : un seul gagnant. |
| Visibilité | Sans `tache.voir_toutes`, on ne voit que les pannes dont on est déclarant ou assigné (404 sinon, pour ne pas révéler leur existence). |

## Effets de bord à connaître
- `t_taches.date_modification` est maintenant mise à jour automatiquement (elle restait figée à la création). Les indicateurs « terminées aujourd'hui », « tâche dormante » et le classement des résolveurs deviennent corrects, **mais l'historique antérieur reste faux** (les dates de résolution passées sont inconnues : `date_resolution` est vide pour les anciennes tâches).
- Un compte dont le rôle n'a aucune ligne de permission n'a accès à rien.
- Le formulaire utilisateur du site web propose encore seulement Technicien/Administrateur ; la liste dynamique des rôles arrive avec l'écran des rôles (lot 6).

## Déploiement en production
```bash
php backend/scripts/migrate.php preflight          # corriger ce qui est signalé AVANT
php backend/scripts/migrate.php up --dry-run       # validation à blanc sur les vraies données
php backend/scripts/migrate.php up --backup --backup-dir=/chemin/hors/webroot
```
1. `preflight` : homonymes (**bloquants** : la migration 003 les refuse), assignés sans compte, rôles inconnus (créés sans droit).
2. Après migration : créer le rôle « Agent » (permissions suggérées : `tache.declarer`, `tache.voir_siennes`, `materiel.voir_siens`) et vérifier les permissions des rôles repris.
3. Si la production n'a jamais eu `t_parametres` (cas de la base locale), la migration 001 la crée ; les paramètres retombent sur leurs valeurs par défaut.
4. Retour arrière : restaurer la sauvegarde `--backup` (`pg_restore`) et redéployer le code précédent ; les migrations n'ont pas de « down » (élargissement seul, l'ancien code continue de fonctionner sur le nouveau schéma).

## Tests
- `php backend/tests/authz_test.php` — 77 contrôles HTTP/transitions/concurrence (données `zz_test_*` supprimées en fin d'exécution).
- `php backend/tests/migrations_test.php` — 29 contrôles sur bases jetables : base vide, base historique avec données, homonymes, migration en échec/modifiée.
- Ne jamais lancer ces tests sur la production.
