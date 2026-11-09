# Lot 3 — Application Android (socle)

Projet : `android/` (Kotlin, Jetpack Compose, Material 3). Package `org.parcit.mobile`, minSdk 26 (Android 8), targetSdk 35, version 1.0.0.

## Contenu
- **Connexion** : adresse du serveur + identifiant + mot de passe. Jetons stockés chiffrés (AES-GCM, clé non exportable du Keystore). Renouvellement automatique du jeton d'accès (un seul refresh à la fois, nouveau refresh stocké avant tout), retour à l'écran de connexion si la session est révoquée.
- **Mes pannes** : liste de ses déclarations (statut, qui a pris en charge), actualisation au retour sur l'application.
- **Déclarer une panne** : objet, description, priorité, service (pré-rempli), matériel concerné (si le rôle voit le parc). **Hors ligne** : la déclaration est gardée (chiffrée) avec un `client_uuid`, envoyée automatiquement par WorkManager dès que le réseau revient, sans doublon possible ; refus définitif affiché avec « Réessayer / Abandonner ».
- **Détail** : état, historique (déclaration, prise en charge, affectation, statuts, commentaires visibles), commentaire, annulation tant que le droit le permet (`actions` du serveur).
- Contrôle de version : `X-App-Version` envoyé, message clair si le serveur répond `app_obsolete`.

## Construire
```
cd android && ./gradlew :app:assembleDebug     # APK : app/build/outputs/apk/debug/app-debug.apk
```
JDK 17+ et SDK Android 36 requis. L'émulateur joint le Mac via `http://10.0.2.2/parc` (adresse par défaut du build debug, HTTP clair autorisé). Le build release n'autorise que HTTPS et utilise `DEFAULT_SERVER` (`app/build.gradle.kts`, à remplacer par l'adresse de production).

## Signature et distribution (APK direct uniquement)
L'application se distribue en **APK installé directement** (pas de Play Store). Android n'accepte une mise à jour que si l'APK est signé avec **la même clé** que la version installée : créer le keystore une fois, hors dépôt, et le sauvegarder dans un coffre (la perte oblige tous les utilisateurs à désinstaller puis réinstaller).
```
keytool -genkeypair -v -keystore parcit.jks -alias parcit -keyalg RSA -keysize 4096 -validity 9125
```
Créer `android/keystore.properties` (ignoré par git) : `storeFile=…/parcit.jks`, `storePassword=…`, `keyAlias=parcit`, `keyPassword=…`. Puis `./gradlew :app:assembleRelease` → `app/build/outputs/apk/release/`. Voir [MEMO_LOT5](MEMO_LOT5_AGENT_IT_ET_PARC.md) pour la publication d'une version.

## Non fait (volontairement)
- Écrans agent IT, parc, scan QR : lot 5. Notifications (FCM, scrutation, bouton « Je prends ») : lot 4.
- Pas de base locale Room ni d'Hilt à ce stade : liste rechargée à chaque ouverture, injection manuelle. À introduire avec le mode hors ligne complet (lot 5).
- **Non testé sur appareil ou émulateur** : aucune image d'émulateur n'est installée sur ce Mac ; l'APK compile, le contrat serveur est couvert par `api_v1_test.php`. Premier essai à faire avec Android Studio (Device Manager) ou un téléphone en débogage USB.
