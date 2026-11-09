# Lot 5 — Vue agent IT, parc sur mobile, mise à jour de l'APK

Distribution : **APK direct uniquement** (le Play Store n'est plus envisagé).

## Barre de navigation (selon les droits)
| Onglet | Visible si | Contenu |
|---|---|---|
| Mes pannes | toujours | déclarations de l'utilisateur (lot 3) |
| Interventions | `tache.voir_toutes` | **À prendre** (file Service IT), **Mes tâches**, **Toutes** (ouvertes) ; recherche ; badges priorité / retard SLA ; « Je prends » directement dans la liste |
| Parc | `materiel.voir_tous` ou `materiel.voir_siens` | recherche, filtre statut, fiche, scan, ajout |
| Alertes | toujours | lot 4 |

## Agent IT — détail d'une panne
Selon `actions` renvoyées par le serveur : **Je prends**, **Affecter** (liste des agents IT avec leur charge, ou remise en file), **Changer le statut** / **Rouvrir** (motif et date de relance obligatoires pour « En attente »), **commentaire** avec case « note interne » (invisible du déclarant). Les conflits (panne modifiée entre-temps, prise par un collègue) sont signalés par le message du serveur.

## Parc
- **Liste / recherche / filtre** par statut ; **fiche** (caractéristiques, utilisateur, service, pannes liées, historique d'affectation).
- **Scan d'étiquette QR** (CameraX + ML Kit, modèle embarqué : fonctionne sans services Google Play) lisant les étiquettes déjà imprimées (`… | SN:xxx | ID:12`), codes-barres courants, ou saisie du numéro de série si la caméra est refusée.
- **Création / modification** (`materiel.creer` / `materiel.modifier`) : l'affectation n'est envoyée que si l'utilisateur l'a changée, pour ne pas effacer l'affectation des anciennes fiches saisies en texte libre.
- **Déclarer une panne depuis la fiche** (matériel présélectionné).
- Nouvelle route serveur `GET utilisateurs` (liste des agents actifs, pour affecter un matériel) : droits `utilisateur.lister`, `materiel.creer` ou `materiel.modifier`.

## Publier une nouvelle version de l'APK
1. Augmenter `versionCode` et `versionName` dans `android/app/build.gradle.kts`, compiler `assembleRelease` avec la **même clé**.
2. Copier l'APK sur le serveur dans un dossier servi en HTTPS (ex. `/parc/downloads/parcit-1.1.0.apk`).
3. Renseigner dans `t_parametres` : `app_apk_url` (lien), `app_version_courante` (`1.1.0`), `app_notes_version` (nouveautés). Les applications plus anciennes affichent alors un bandeau « Nouvelle version » avec le lien de téléchargement ; à l'ouverture du lien, l'utilisateur installe l'APK (Android demande d'autoriser l'installation depuis le navigateur la première fois).
4. Pour **forcer** la mise à jour (changement d'API incompatible) : relever `app_version_minimale` ; les anciennes versions reçoivent `426 app_obsolete` et un message les invitant à se mettre à jour.

## Limites
- Pas encore de cache hors ligne (Room) : les listes se rechargent à chaque ouverture ; seules les **déclarations** de panne sont mises en file hors ligne. À traiter si le réseau des agents est peu fiable.
- L'installation de la mise à jour passe par le navigateur (pas de téléchargement/installation silencieux dans l'application).
- Non testé sur appareil : la caméra, les écrans agent et le parc compilent (APK debug et release) mais n'ont pas été exécutés.
- La suppression de matériel n'est pas proposée sur mobile (usage rare, reste sur le site).

## Refonte de l'interface (novembre 2026)
- **Thème** (`ui/Theme.kt`) : palette bleue sobre, mode clair **et sombre** automatique, coins arrondis, typographie hiérarchisée, plein écran (barres système transparentes). Couleurs des statuts/priorités centralisées dans `ui/Composants.kt`.
- **Composants communs** (`ui/Composants.kt`) : cartes, puces de statut, avatars, champs arrondis, frise chronologique de l'historique, états vides, en-têtes avec retour.
- **Écrans** : connexion épurée (serveur replié), accueil « Bonjour … » avec compteurs et filtres, liseré de priorité sur les cartes, tirer pour actualiser, barre du bas avec icônes et pastille d'alertes, déclaration avec choix d'urgence en boutons segmentés et bouton d'envoi fixe, détail en cartes (état, informations, historique), alertes avec icônes par type, parc avec icône par type d'équipement, scanner avec cadre de visée.
- **Icône** de l'application redessinée (écran avec coche).
- Aucun changement de comportement ni d'API. Rendu **non vérifié visuellement** par moi (pas d'appareil) : à valider sur le téléphone, les retouches (espacements, couleurs) sont rapides.
