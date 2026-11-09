# Lot 4 — Notifications (alerte dans l'application, e-mail, SMS, push) et « Je prends »

## Principe
Chaque évènement d'une panne (`t_taches_evenements`) produit des notifications pour les bonnes personnes, **une seule fois** (`notifie_at`). Le traitement est indépendant de qui a créé l'évènement (site web ou application) : `tache_evenement()` le planifie en fin de requête, après la réponse au client. Un canal en panne ne bloque jamais le suivi d'une panne : l'envoi est réessayé (1, 5, 30, 120 min) puis abandonné après 5 essais, et l'alerte dans l'application reste toujours disponible.

| Évènement | Qui est prévenu (jamais l'auteur de l'action) |
|---|---|
| Panne déclarée en file « Service IT » | tous les agents IT (avec **« Je prends »**) ; le déclarant s'il n'est pas l'auteur |
| Prise en charge | le déclarant (nom de l'agent) |
| Affectation | le nouvel assigné ; le déclarant ; si remise en file : tous les agents IT |
| Changement de statut | le déclarant (motif si « En attente ») |
| Commentaire | le déclarant (commentaire visible) ; l'agent assigné (réponse du déclarant ou note interne) |

## Canaux
- **Dans l'application** (toujours actif) : boîte « Alertes » + pop-up quand l'application est ouverte (scrutation toutes les 30 s) + notification système via WorkManager (~15 min en arrière-plan, limite d'Android). Aucun service externe requis.
- **E-mail** : utilise la configuration SMTP existante (`email_notifications_actives`) et l'adresse du profil.
- **SMS** (facultatif) : passerelle HTTP générique. Paramètres `sms_actif`, `sms_url`, `sms_jeton` (Bearer), `sms_expediteur`, `sms_corps` (modèle JSON avec `{to}`, `{message}`, `{from}`). Succès = réponse HTTP 2xx. À adapter au fournisseur retenu en changeant seulement le modèle de corps (un fournisseur exigeant un format non-JSON demandera un petit transport dédié).
- **Push FCM** (facultatif) : côté serveur prêt (`fcm_actif`, `fcm_projet`, `fcm_compte_service` = chemin du JSON du compte de service, hors webroot). **Côté application non branché** : il faut un projet Firebase et son `google-services.json` ; sans cela l'application reste sur la scrutation. L'application enregistre déjà `fcm_token` via `PUT appareils/courant` dès qu'elle en a un.
- Chaque agent coupe l'e-mail ou le SMS depuis l'écran Alertes (`notif_email`, `notif_sms`).

Les paramètres `sms_*` / `fcm_*` n'ont pas encore d'écran (lot 6) : les renseigner dans `t_parametres` (ou via l'API `parametres` en tant qu'administrateur). `sms_jeton` n'est jamais renvoyé en lecture.

## « Je prends »
Bouton dans la notification système, dans le pop-up, dans la liste des alertes et sur le détail d'une panne. Le serveur arbitre (`prendre` atomique) : si un collègue a été plus rapide, un message l'indique. Il n'apparaît que pour les agents ayant `tache.prendre` et tant que la panne est en file.

## Déploiement
1. `php backend/scripts/migrate.php up --backup …` (migration 008 : marque les évènements existants comme traités, aucune notification rétroactive).
2. Planifier `php backend/scripts/notifications.php` chaque minute (filet de sécurité pour les envois en échec ; `--etat` affiche la file).
3. Renseigner SMTP / SMS si souhaité. Rien n'est envoyé tant que les canaux sont désactivés.

## Tests
`php backend/tests/notifications_test.php` — 45 contrôles (destinataires, boîte de réception, préférences, files d'envoi, reprises et abandon, passerelle SMS sur un serveur local jetable). Autres suites inchangées : api_v1 98, authz 77, migrations 29.

## Limites
- Arrière-plan Android : délai pouvant atteindre ~15 min et suspendu par certaines économies d'énergie constructeur ; c'est ce que FCM supprimera.
- Le texte des notifications est en français, non personnalisable pour l'instant.
- Non testé sur appareil (voir lot 3) : notification système, action « Je prends » et demande d'autorisation Android 13 compilent mais n'ont pas été exécutées.
