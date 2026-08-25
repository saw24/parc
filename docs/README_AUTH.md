# Système d'Authentification - Parc Informatique

## 🔐 **Système d'Authentification Complet**

### 🎯 **Objectif**
Implémenter un système d'authentification sécurisé avec gestion des sessions pour protéger l'accès à l'application de gestion de parc informatique.

### 📁 **Fichiers Créés**

#### **Pages et Interface**
- **`login.html`** - Page de connexion moderne et responsive
- **`login.js`** - JavaScript pour la gestion de la connexion

#### **Backend et API**
- **`api_auth.php`** - API complète pour l'authentification
- **`create_sessions_table.sql`** - Structure de la table des sessions
- **`create_sessions_table.php`** - Script d'installation de la table
- **`update_passwords.php`** - Script pour mettre à jour les mots de passe

#### **Tests et Documentation**
- **`test_auth.php`** - Script de test pour l'authentification
- **`README_AUTH.md`** - Cette documentation

#### **Fichiers Modifiés**
- **`index.html`** - Ajout de l'interface utilisateur connecté
- **`script.js`** - Ajout de la logique d'authentification
- **`style.css`** - Styles pour l'interface utilisateur

### 🗄️ **Structure de la Base de Données**

#### **Table `t_sessions`**
```sql
CREATE TABLE t_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    expiry_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES t_utilisateurs(id) ON DELETE CASCADE
);
```

### 🔧 **Installation**

#### **Étape 1 : Créer la table des sessions**
```bash
# Accéder à l'URL
http://localhost:8888/parc/backend/scripts/create_sessions_table.php
```

#### **Étape 2 : Mettre à jour les mots de passe**
```bash
# Accéder à l'URL
http://localhost:8888/parc/backend/scripts/update_passwords.php
```

#### **Étape 3 : Tester l'authentification**
```bash
# Accéder à l'URL
http://localhost:8888/parc/backend/scripts/test_auth.php
```

### 🔑 **Identifiants de Test**

| Utilisateur | Login | Mot de passe | Rôle |
|-------------|-------|--------------|------|
| Admin Principal | admin | password123 | Administrateur |
| Jean Technicien | jean | password123 | Technicien |
| Marie Helpdesk | marie | password123 | Technicien |

### 🚀 **Fonctionnalités Implémentées**

#### **1. Page de Connexion**
- ✅ **Interface moderne** : Design responsive avec gradient
- ✅ **Validation en temps réel** : Vérification des champs
- ✅ **Messages d'erreur** : Affichage des erreurs de connexion
- ✅ **Loading state** : Indicateur de chargement
- ✅ **Redirection automatique** : Si déjà connecté

#### **2. Authentification Sécurisée**
- ✅ **Hachage des mots de passe** : Utilisation de `password_hash()`
- ✅ **Vérification sécurisée** : Utilisation de `password_verify()`
- ✅ **Tokens de session** : Génération de tokens uniques
- ✅ **Expiration automatique** : Sessions de 24 heures
- ✅ **Nettoyage automatique** : Suppression des sessions expirées

#### **3. Gestion des Sessions**
- ✅ **Stockage en base** : Sessions persistantes
- ✅ **Vérification côté serveur** : Validation des tokens
- ✅ **Sécurité renforcée** : Protection contre les attaques
- ✅ **Déconnexion sécurisée** : Nettoyage complet des sessions

#### **4. Interface Utilisateur**
- ✅ **Affichage utilisateur** : Nom et rôle dans le header
- ✅ **Menu utilisateur** : Dropdown avec informations
- ✅ **Bouton déconnexion** : Déconnexion sécurisée
- ✅ **Protection des pages** : Redirection si non connecté

### 📡 **API Endpoints**

#### **POST `/api_auth.php?action=login`**
Connexion utilisateur
```json
{
  "login": "admin",
  "password": "password123"
}
```
Réponse :
```json
{
  "success": true,
  "message": "Connexion réussie.",
  "user": {
    "id": 1,
    "nom": "Admin Principal",
    "login": "admin",
    "role": "Administrateur"
  },
  "session_token": "abc123..."
}
```

#### **POST `/api_auth.php?action=check_session`**
Vérifier la validité d'une session
```json
{
  "session_token": "abc123...",
  "user_id": 1
}
```

#### **POST `/api_auth.php?action=logout`**
Déconnexion utilisateur
```json
{
  "session_token": "abc123..."
}
```

#### **POST `/api_auth.php?action=get_user_info`**
Récupérer les informations utilisateur
```json
{
  "session_token": "abc123...",
  "user_id": 1
}
```

### 🔒 **Sécurité**

#### **Protection des Mots de Passe**
- **Hachage bcrypt** : Utilisation de `password_hash()`
- **Salt automatique** : Génération automatique du salt
- **Vérification sécurisée** : Utilisation de `password_verify()`

#### **Gestion des Sessions**
- **Tokens uniques** : Génération avec `random_bytes()`
- **Expiration automatique** : Sessions de 24 heures
- **Nettoyage automatique** : Suppression des sessions expirées
- **Protection CSRF** : Tokens de session uniques

#### **Validation et Sanitisation**
- **Validation côté serveur** : Vérification des données
- **Requêtes préparées** : Protection contre les injections SQL
- **Gestion d'erreurs** : Messages d'erreur sécurisés

### 🎨 **Interface Utilisateur**

#### **Page de Connexion**
- **Design moderne** : Gradient de fond et cartes blanches
- **Icônes Font Awesome** : Interface intuitive
- **Responsive design** : Adapté à tous les écrans
- **Animations** : Transitions fluides

#### **Header Utilisateur**
- **Affichage du nom** : Nom de l'utilisateur connecté
- **Menu dropdown** : Informations détaillées
- **Bouton déconnexion** : Déconnexion facile
- **Indicateur de rôle** : Affichage du rôle utilisateur

### 🔄 **Flux d'Authentification**

#### **1. Connexion**
1. Utilisateur accède à `login.html`
2. Saisit ses identifiants
3. Validation côté client
4. Envoi à l'API d'authentification
5. Vérification des identifiants
6. Génération du token de session
7. Stockage en base de données
8. Redirection vers l'application

#### **2. Vérification de Session**
1. Chargement de l'application
2. Vérification du token en localStorage
3. Validation côté serveur
4. Chargement des informations utilisateur
5. Affichage de l'interface

#### **3. Déconnexion**
1. Clic sur "Se déconnecter"
2. Suppression du token en base
3. Nettoyage du localStorage
4. Redirection vers la page de connexion

### 🧪 **Tests**

#### **Script de Test Complet**
Accédez à `http://localhost:8888/parc/backend/scripts/test_auth.php` pour :
- Vérifier la structure de la base de données
- Tester l'API de connexion
- Valider les identifiants de test
- Vérifier la création des sessions

### 📱 **Responsive Design**
- **Mobile** : Interface adaptée aux petits écrans
- **Tablette** : Optimisation pour les écrans moyens
- **Desktop** : Interface complète avec toutes les fonctionnalités

### 🎉 **Résultat Final**
Un système d'authentification **complet et sécurisé** qui protège l'accès à l'application tout en offrant une expérience utilisateur moderne et intuitive !
