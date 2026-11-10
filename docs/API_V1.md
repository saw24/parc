# API mobile v1

Contrat de l'API utilisée par l'application Android (et, au lot 6, par le site web).
Code : `backend/api/v1/` · logique partagée : `backend/lib/` · tests : `backend/tests/api_v1_test.php`.

## Base
- **URL** : `<hôte>/parc/backend/api/v1/index.php/<route>` (ex. `…/index.php/taches/12/prendre`).
  Repli sans PATH_INFO : `…/index.php?r=taches/12/prendre`. Des URL sans `index.php` sont possibles avec une règle de réécriture (voir « Serveur »).
- **Format** : JSON UTF-8 (`Content-Type: application/json`).
- **Authentification** : `Authorization: Bearer <access_token>` (64 caractères hexadécimaux).
- **Version de l'application** : envoyer `X-App-Version: 1.0.3` à chaque requête ; en dessous de `app_version_minimale` (Paramètres) → `426 app_obsolete`.
- **HTTPS obligatoire** en production.

## Conventions
| Sujet | Règle |
|---|---|
| Succès | `{"data": …, "meta": {…}}` |
| Erreur | `{"error": {"code": "…", "message": "…", "details": {…}}}` — se fier au `code` (stable), pas au message |
| Dates | ISO 8601 UTC (`2026-11-09T12:30:00.123456Z`) ; dates seules `AAAA-MM-JJ` |
| Pagination | `?limit=` (≤ 100, défaut 50) + `meta.next_cursor` à renvoyer dans `?cursor=` ; `null` = dernière page. Tri : plus récemment modifié d'abord |
| Synchronisation | voir « Hors ligne » |
| Concurrence | pannes : champ `version` ; envoyer `version` dans le corps → `409 conflit` si la panne a changé entre-temps |

### Codes d'erreur
`json_invalide`(400) · `token_manquant`/`token_invalide`/`token_expire`(401) · `refresh_invalide`/`refresh_expire`/`refresh_rejoue`(401) · `identifiants_invalides`(401) · `interdit`(403) · `mot_de_passe_incorrect`(403) · `introuvable`(404) · `route_inconnue`(404) · `methode_non_autorisee`(405) · `conflit`/`num_serie_existant`/`uuid_deja_utilise`(409) · `champ_requis`/`champ_invalide`/`donnees_invalides`/`mot_de_passe_faible`/`curseur_invalide`(422) · `app_obsolete`(426) · `trop_de_tentatives`(429, en-tête `Retry-After`) · `erreur_interne`(500).

## Session
Le **site web** appelle aussi la v1 : les en-têtes `X-Session-Token` + `X-User-Id` de sa session sont acceptés à la place du jeton Bearer (mêmes permissions). Les routes `auth/*` et `appareils` restent propres à l'application mobile.

1. `POST auth/login` `{login, password, appareil: {nom, plateforme: "android", version_app}}` → `{utilisateur, access_token, refresh_token, expires_in: 3600, appareil_id}`.
2. Sur `401 token_expire` : `POST auth/refresh` `{refresh_token}` → nouveaux `access_token` **et** `refresh_token` (le précédent n'est plus valable : toujours stocker le nouveau, de façon atomique). Réponse perdue ? Rejouer le même refresh dans les 60 s réémet des jetons.
3. `401 refresh_rejoue` : un ancien refresh a été présenté (vol possible) → la session est révoquée, reconnexion complète.
4. Les jetons se stockent dans le Keystore Android. Durées : accès 1 h, refresh 90 jours (glissant). 10 appareils actifs maximum par compte.

## Routes
**Publiques** : `GET config` (versions, `server_time`, `apk_url`) · `POST auth/login` · `POST auth/refresh`.

| Route | Droit requis | Notes |
|---|---|---|
| `POST auth/logout` | — | révoque l'appareil courant |
| `GET me` · `PATCH me` `{email, telephone, notif_email, notif_sms}` | — | profil, rôle, `permissions[]`, `agent_it` |
| `POST me/mot_de_passe` `{actuel, nouveau}` | — | ≥ 10 caractères ; déconnecte les autres appareils et sessions web |
| `GET appareils` · `DELETE appareils/{id}` · `PUT appareils/courant` `{fcm_token?, version_app?, nom?}` | — | `fcm_token` : notifications push (optionnelles) |
| `GET notifications` | — | boîte de réception (50 max, plus récentes d'abord) : `apres_id=N`, `non_lues=1` ; `meta.non_lues` = compteur. Chaque entrée : `type`, `titre`, `message`, `tache{id,reference,statut}`, `peut_prendre` (afficher « Je prends » : appeler ensuite `POST taches/{id}/prendre`), `lue` |
| `POST notifications/lues` `{ids:[…]}` ou `{toutes:true}` | — | marque comme lues (uniquement les siennes) |
| `GET referentiels` | — | services, types de matériel, priorités, statuts, délais SLA |
| `GET utilisateurs` | `utilisateur.lister`, `materiel.creer` ou `materiel.modifier` | agents actifs (id, nom, service) pour affecter un matériel ; `?detail=1` (exige `utilisateur.lister`/`gerer`) ajoute login, rôle, état ; `inclure_inactifs=1`, `q=` |
| `GET utilisateurs/{id}` | `utilisateur.lister` ou `gerer` | |
| `POST utilisateurs` | `utilisateur.gerer` | `{nom*, login*, mot_de_passe* (≥ 10), role*, email, telephone, service_id, actif, connexion_autorisee}` ; `409 login_existant` ; le rôle Administrateur n'est attribuable que par un administrateur |
| `PATCH utilisateurs/{id}` | `utilisateur.gerer` | modification partielle ; un renommage suit dans les pannes/matériel ; rôle, mot de passe ou désactivation coupent toutes les sessions ; refus : soi-même (`auto_modification`), dernier administrateur (`dernier_admin`), compte « Service IT » (`compte_technique`) |
| `DELETE utilisateurs/{id}` | `utilisateur.gerer` | mêmes garde-fous ; pannes et historique conservés |
| `GET roles` | `utilisateur.lister`, `utilisateur.gerer` ou `role.gerer` | rôles attribuables ; avec `role.gerer`, chacun porte ses `permissions[]` |
| `GET permissions` | `role.gerer` | catalogue groupé `[{groupe, permissions:[{code, libelle}]}]` |
| `POST roles` · `PATCH roles/{id}` | `role.gerer` | `{nom*, description, permissions[]}` ; rôle système intouchable (`403 role_systeme`) ; modifier un rôle coupe les sessions des comptes concernés (sauf la sienne) |
| `DELETE roles/{id}` | `role.gerer` | `409 role_utilise` s'il est attribué |
| `GET parametres` · `PATCH parametres` | `parametre.gerer` | PATCH partiel (SMTP, seuils, `sms_*`, `fcm_*`…) ; les secrets (`smtp_password`, `sms_jeton`, `fcm_compte_service`) ne sortent jamais, seulement `<clé>_configure` ; secret `""` = conserver, `null` = effacer |
| `GET services` | `referentiel.voir` | avec `nb_utilisateurs`, `nb_materiels` |
| `POST services` · `PATCH services/{id}` | `referentiel.gerer` | `{nom*, description}` ; un renommage est répercuté sur pannes et matériel ; `409 nom_existant` |
| `DELETE services/{id}` | `referentiel.supprimer` | `409 service_utilise` tant que des utilisateurs ou du matériel y sont rattachés |
| `PATCH taches/{id}` | `tache.modifier_toutes` / `modifier_siennes`, ou le déclarant tant que la panne est « À faire » et non prise | partiel : `titre, description, priorite, service_id, materiel_id` ; `motif_cause`, `date_limite` réservés à l'équipe (403) ; `version` optionnelle (`409 conflit`) ; changer la priorité recalcule les échéances SLA |
| `DELETE taches/{id}` | `tache.supprimer` | |
| `GET agents` | `tache.affecter` | agents IT + file « Service IT », avec leur charge |
| `GET accueil` | accès aux pannes | compteurs : mes pannes ouvertes, mes assignées, file, retards SLA, relances dues |
| `GET taches` | `tache.voir_toutes` ou `tache.voir_siennes` | filtres : `statut=A,B` · `ouvertes=1` · `assigne=moi\|file\|<id>` · `declarant=moi` · `materiel_id` · `q` · `depuis=AAAA-MM-JJ` · `jusqu_a=AAAA-MM-JJ` (période, sur la date de déclaration, bornes incluses) · `updated_since` · `ids_only=1` ; tri : plus récent d'abord |
| `GET taches/{id}` | idem (404 si invisible) | inclut `evenements[]` (historique) ; les notes internes sont masquées au déclarant |
| `POST taches` | `tache.declarer` ou `tache.creer` | `{titre*, description, priorite, service_id, materiel_id, motif_cause, client_uuid}` ; `assigne_id`, `date_limite` réservés à `tache.creer`. Sans `assigne_id` : file « Service IT ». `201` (créée) ou `200` + `meta.deja_existante` (rejeu du même `client_uuid`) |
| `POST taches/{id}/prendre` | `tache.prendre` | atomique : un seul agent gagne, les autres reçoivent `409` avec le nom du preneur |
| `POST taches/{id}/affecter` `{assigne_id, commentaire?}` | `tache.affecter` | l'id de la file « Service IT » remet la panne en file |
| `POST taches/{id}/statut` `{statut, motif_attente?, date_relance?, commentaire?}` | `tache.modifier_toutes`, ou `tache.modifier_siennes` si assignée à soi ; le déclarant peut **annuler** tant que personne n'a pris la panne | « En attente » exige motif + relance |
| `POST taches/{id}/commentaires` `{commentaire, visible_declarant?}` | voir la panne | `visible_declarant:false` réservé à l'équipe IT |
| `GET materiel` | `materiel.voir_tous` (tout) ou `materiel.voir_siens` (le sien) | filtres : `q` · `statut` · `type` · `service_id` · `assigne=moi` · `updated_since` · `ids_only=1` |
| `GET materiel/scan?code=` | idem | contenu d'une étiquette QR (`… \| SN:… \| ID:12`) ou numéro de série |
| `GET materiel/{id}` · `…/affectations` · `…/pannes` | idem | historique d'affectation ; pannes liées |
| `POST materiel` | `materiel.creer` | `{type*, statut*, marque, modele, num_serie, date_achat, service_id, utilisateur_id, date_derniere_affect}` ; un service sans date d'affectation prend la date du jour |
| `PATCH materiel/{id}` | `materiel.modifier` | modification partielle ; une réaffectation clôture l'ancienne ligne d'historique |
| `DELETE materiel/{id}` | `materiel.supprimer` | |

Chaque panne porte `actions` (`prendre`, `affecter`, `changer_statut`, `rouvrir`, `annuler`, `commenter`, `modifier`, `supprimer`) : booléens calculés pour l'utilisateur courant, à utiliser pour afficher ou masquer les boutons. Le serveur revérifie toujours les droits.

## Hors ligne
- **Création** : générer un UUID v4 (`client_uuid`) à la saisie, mettre l'envoi en file (WorkManager) et le rejouer jusqu'à obtenir `200`/`201` : jamais de doublon. Un `4xx` (hors 401/429) est définitif : le signaler à l'utilisateur au lieu de réessayer.
- **Rafraîchir les listes** : mémoriser `meta.server_time` de la dernière réponse complète, puis demander `?updated_since=<server_time moins 5 s>` en suivant `next_cursor` jusqu'au bout.
- **Suppressions** : `GET taches?ids_only=1` (et `materiel?ids_only=1`) renvoie les ids visibles ; ceux qui manquent localement doivent être purgés (supprimés ou devenus invisibles).
- **Conflits** : toujours renvoyer `version` avec prendre/affecter/statut ; sur `409`, recharger la panne et laisser l'utilisateur décider.

## Serveur
- **Apache** : l'en-tête `Authorization` n'est pas transmis à PHP par défaut ; ajouter `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` (ou `CGIPassAuth On`). Caddy et nginx le transmettent.
- **URL sans `index.php`** (facultatif) — Caddy : `rewrite /parc/backend/api/v1/* /parc/backend/api/v1/index.php{path}` ; nginx : `try_files $uri /backend/api/v1/index.php$is_args$args;` avec `fastcgi_split_path_info`.
- **Migration** : `007_api_mobile.sql` (table `t_appareils`, colonne `t_taches.client_uuid`) avant de déployer le code.
- **Mise à jour de l'application** : renseigner `app_version_courante`, `app_version_minimale`, `app_apk_url` dans la table `t_parametres` (écran Paramètres au lot 6).
- **Notifications** : voir `docs/MEMO_LOT4_NOTIFICATIONS.md` (migration 008, script `scripts/notifications.php`, paramètres `sms_*` / `fcm_*`).
