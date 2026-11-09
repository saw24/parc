# Lot 6 — Administration sur le site web (état)

**Fait**
- La v1 accepte la session du site web (en-têtes `X-Session-Token` / `X-User-Id`, sans cookie donc sans CSRF) : le site peut migrer écran par écran vers les mêmes routes que le mobile.
- Rôles : `GET/POST/PATCH/DELETE roles`, `GET permissions` (permission `role.gerer`). Nouvel écran **Rôles** (menu du site, administrateur) : création, modification des permissions par groupes, suppression ; rôle système protégé.
- Paramètres : `GET/PATCH parametres` ; nouveau formulaire **Passerelle SMS / push FCM** dans l'écran Paramètres (secrets jamais renvoyés).
- Rôle « Agent » : **pas de migration** (décision : il se crée depuis l'écran Rôles après les migrations). Permissions conseillées : `tache.declarer`, `tache.voir_siennes`, `materiel.voir_siens`.
- Tests : `api_v1_test.php` à 160 contrôles (rôles, permissions, paramètres, pont web).

**Migration du site vers la v1 (fait)**
- `frontend/assets/js/legacy-bridge.js` (chargé avant `script.js`) intercepte les appels jQuery vers `backend/api/{taches,materiel,services,utilisateurs,type_materiel}.php` et les exécute sur la v1 ; il renvoie la réponse au format que `script.js` attend. Les noms en texte du formulaire sont traduits en identifiants ; l'ancien « enregistrement complet » d'une tâche devient, selon ce qui a changé, `PATCH` (fiche) + `affecter`/`prendre` + `statut` — donc workflow, verrou de version, évènements et notifications s'appliquent aussi depuis le site.
- Nouvelles routes v1 : `types_materiel` (CRUD), `GET utilisateurs?detail=1&inclure_file=1&efficacite=1`, champ `actions_texte` des pannes (actions menées). Une panne créée depuis le site porte `source = web`.
- Les anciennes actions migrées (lecture, création, modification, suppression, historique d'affectation) répondent **410** : plus aucun contournement possible des règles. Reste sur les anciens endpoints : `auth.php` (session du site), `dashboard.php` (analyses en lecture seule), `parametres.php` (SMTP), `taches.php?action=user_task_stats`, et `utilisateurs.php?action=update` réservé à « modifier sa propre fiche » par un compte sans droit de gestion.
- Changements visibles : mot de passe ≥ 10 caractères à la création/modification d'un compte ; un service ou un type encore utilisé ne peut plus être supprimé ; renommer un service/type/utilisateur met à jour pannes et matériel.
- Tests : `authz_test.php` réécrit sur la v1 (97 contrôles) ; `api_v1_test.php` à 171.

**Reste**
- Le tableau de bord (9 requêtes `dashboard.php`) et l'authentification web gardent leurs endpoints dédiés ; à rapprocher de la v1 si on veut un jour retirer entièrement `backend/api/*.php`.
- Sur le site, la visibilité des menus Paramètres/Utilisateurs/Rôles repose encore sur le rôle littéral « Administrateur » ; l'API applique déjà les permissions.
