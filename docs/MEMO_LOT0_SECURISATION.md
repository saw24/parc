# Lot 0 — Sécurisation de l'existant (novembre 2026)

## Ce qui change
| Domaine | Avant | Après |
|---|---|---|
| Droits API | `tâches`, `matériel`, `services`, `types`, `dashboard` : tout utilisateur connecté, quel que soit son rôle | Rôles `Administrateur` et `Technicien` uniquement ; tout autre rôle reçoit 403 |
| Suppressions | Seul le bouton était masqué dans l'interface | Réservées aux administrateurs côté serveur (tâches, matériel, services, types) |
| Modification de tâche | Libre | Admin : tout. Technicien : tâches qui lui sont assignées, ou prise en charge d'une tâche « À faire » → « En cours » |
| Traçabilité | `created_by_login` / `updated_by_login` fournis par le navigateur (falsifiables ; `$_SESSION['login']` inexistant) | Toujours le login de la session |
| Comptes | Lecture de n'importe quelle fiche par id ; rôle libre ; suppression de soi-même ou du dernier admin possible | Fiche d'un autre = admin/technicien seulement ; rôles validés ; garde-fous dernier admin / auto-suppression |
| Jetons de session | Stockés en clair dans `t_sessions` | SHA-256 du jeton stocké |
| Connexion | Tentatives illimitées | 5 échecs / 15 min par login, 30 par IP → HTTP 429 |
| XSS | Données serveur insérées sans échappement dans `script.js` | `escapeHtml()` appliqué (≈ 80 insertions) ; toasts échappés ; paramètres d'URL encodés |
| Scripts `backend/scripts/*.php` | Exécutables via HTTP (dont `update_passwords.php`) | Refusent toute exécution hors ligne de commande |
| Erreur de connexion BD | Détail renvoyé au client | Message générique (détail dans les logs) |

## Étapes de déploiement (ordre obligatoire)
1. Sauvegarde de la base (`pg_dump`).
2. **Appliquer `backend/sql/postgres/add_login_attempts.sql` AVANT de déployer le code** : sans la table `t_login_attempts`, la connexion échoue (HTTP 500).
3. Déployer le code.
4. **Tous les utilisateurs doivent se reconnecter** (les anciens jetons en clair ne correspondent plus aux hash).
5. Serveur web : `.htaccess` ne s'applique pas sous Caddy/nginx. Ajouter un refus d'accès pour `/backend/scripts/`, `/backend/sql/`, `/backend/tests/` (la garde PHP CLI protège déjà les scripts, pas les `.sql`).
6. Vérifier en production que `backend/scripts/update_passwords.php` n'a pas été exécuté et supprimer les scripts de migration devenus inutiles.

## Tests
`php backend/tests/authz_test.php [URL_API]` — 32 contrôles HTTP (authentification, rôle inconnu, technicien, propriété des tâches, jetons hachés, verrouillage). Crée et supprime ses propres données `zz_test_*`. **Ne jamais lancer sur la production.**

## Points restants (lots suivants)
- `t_taches.assigne_a` et les droits reposent encore sur le **nom** d'utilisateur (non unique) → clés étrangères au lot 1.
- Rôles encore codés en dur (`ROLES_VALIDES`) → table `t_roles` au lot 1.
- Un technicien peut encore modifier son propre `nom`/`login` (auto-édition) : à restreindre avec les permissions.
- `update_passwords.php` réinitialise tous les mots de passe sans confirmation : à supprimer du dépôt.
- Les anciens scripts de test (`test_*.php`) affichent des données : à retirer avant la production.
