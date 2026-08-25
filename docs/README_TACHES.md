# Gestion des Tâches - Parc Informatique

## Implémentation Complète

### Fichiers Créés/Modifiés

#### 1. API Backend
- **`api_taches.php`** - API complète pour les opérations CRUD des tâches
- **`create_taches_table.sql`** - Script SQL pour créer la table des tâches
- **`create_taches_table.php`** - Script PHP pour exécuter la création de la table

#### 2. Frontend
- **`script.js`** - Ajout des fonctions de gestion des tâches
- **`index.html`** - Mise à jour de l'interface des tâches
- **`base.sql`** - Ajout de la structure de la table des tâches

### Installation

#### Étape 1 : Créer la table des tâches
1. Ouvrez votre navigateur et accédez à : `http://localhost:8888/parc/backend/scripts/create_taches_table.php`
2. Ou exécutez via la ligne de commande MAMP :
   ```bash
   /Applications/MAMP/bin/php/php8.2.0/bin/php create_taches_table.php
   ```

#### Étape 2 : Vérifier la base de données
La table `t_taches` sera créée avec la structure suivante :
- `id` - Identifiant unique
- `titre` - Titre de la tâche
- `description` - Description détaillée
- `priorite` - Priorité (Basse, Moyenne, Haute)
- `statut` - Statut (À faire, En cours, Terminé, Annulé)
- `assigne_a` - Technicien assigné
- `materiel_concerne` - Matériel concerné
- `date_limite` - Date limite
- `date_creation` - Date de création
- `date_modification` - Date de modification

### Fonctionnalités Implémentées

#### 1. CRUD Complet
- **Créer** : Ajouter une nouvelle tâche
- **Lire** : Afficher toutes les tâches
- **Modifier** : Éditer une tâche existante
- **Supprimer** : Supprimer une tâche

#### 2. Interface Utilisateur
- **Tableau dynamique** : Chargement automatique des données
- **Recherche** : Recherche en temps réel par titre
- **Filtres** : Filtrage par priorité et statut
- **Modale** : Formulaire d'ajout/modification
- **Badges** : Indicateurs visuels pour priorité et statut

#### 3. Validation
- Champs obligatoires : titre, priorité, statut
- Validation côté serveur
- Messages d'erreur explicites

#### 4. Données d'Exemple
4 tâches d'exemple sont automatiquement créées :
1. Réparation serveur web principal (Haute priorité, En cours)
2. Installation nouvelle imprimante (Moyenne priorité, À faire)
3. Mise à jour suite bureautique (Basse priorité, À faire)
4. Remplacement disque dur PC Compta (Moyenne priorité, Terminé)

### Utilisation

#### Ajouter une Tâche
1. Cliquez sur le bouton "+" flottant depuis la page Tâches
2. Remplissez le formulaire
3. Cliquez sur "Enregistrer"

#### Modifier une Tâche
1. Cliquez sur l'icône crayon (✏️) dans la ligne de la tâche
2. Modifiez les champs souhaités
3. Cliquez sur "Enregistrer"

#### Supprimer une Tâche
1. Cliquez sur l'icône poubelle (🗑️) dans la ligne de la tâche
2. Confirmez la suppression

#### Filtrer les Tâches
- Utilisez la barre de recherche pour filtrer par titre
- Utilisez les menus déroulants pour filtrer par priorité ou statut

### API Endpoints

#### GET `/api_taches.php?action=read`
Récupère toutes les tâches

#### POST `/api_taches.php?action=create`
Crée une nouvelle tâche
```json
{
  "titre": "Titre de la tâche",
  "description": "Description",
  "priorite": "Haute|Moyenne|Basse",
  "statut": "À faire|En cours|Terminé|Annulé",
  "assigne_a": "Nom du technicien",
  "materiel_concerne": "Matériel concerné",
  "date_limite": "2025-08-20"
}
```

#### POST `/api_taches.php?action=update`
Met à jour une tâche existante
```json
{
  "id": 1,
  "titre": "Nouveau titre",
  // ... autres champs
}
```

#### POST `/api_taches.php?action=delete`
Supprime une tâche
```json
{
  "id": 1
}
```

### Sécurité
- Requêtes préparées pour éviter les injections SQL
- Validation des données côté serveur
- Gestion des erreurs avec messages appropriés

### Compatibilité
- Compatible avec le système existant
- Utilise les mêmes conventions de nommage
- Intégré avec l'interface utilisateur existante
