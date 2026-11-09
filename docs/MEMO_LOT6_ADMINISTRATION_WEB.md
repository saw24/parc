# Lot 6 — Administration sur le site web (état)

**Fait**
- La v1 accepte la session du site web (en-têtes `X-Session-Token` / `X-User-Id`, sans cookie donc sans CSRF) : le site peut migrer écran par écran vers les mêmes routes que le mobile.
- Rôles : `GET/POST/PATCH/DELETE roles`, `GET permissions` (permission `role.gerer`). Nouvel écran **Rôles** (menu du site, administrateur) : création, modification des permissions par groupes, suppression ; rôle système protégé.
- Paramètres : `GET/PATCH parametres` ; nouveau formulaire **Passerelle SMS / push FCM** dans l'écran Paramètres (secrets jamais renvoyés).
- Rôle « Agent » : **pas de migration** (décision : il se crée depuis l'écran Rôles après les migrations). Permissions conseillées : `tache.declarer`, `tache.voir_siennes`, `materiel.voir_siens`.
- Tests : `api_v1_test.php` à 160 contrôles (rôles, permissions, paramètres, pont web).

**Reste**
- Migrer les écrans existants (pannes, matériel, services, utilisateurs, tableau de bord) de `backend/api/*.php` vers la v1 : `script.js` (3 765 lignes, ~48 appels) à reprendre écran par écran, puis retirer les anciens endpoints.
- Sur le site, la visibilité des menus Paramètres/Utilisateurs/Rôles repose encore sur le rôle littéral « Administrateur » ; l'API, elle, applique déjà les permissions.
