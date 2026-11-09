# Migrations de base de données

Source de vérité du schéma PostgreSQL (remplace les scripts épars de `backend/sql/`, conservés
pour l'historique).

```bash
php backend/scripts/migrate.php status                       # état de chaque migration
php backend/scripts/migrate.php preflight                    # problèmes de données à corriger avant
php backend/scripts/migrate.php up --dry-run                 # valide à blanc sur les vraies données
php backend/scripts/migrate.php up --backup --backup-dir=/chemin/hors/webroot
```

Règles :
- un fichier = `NNN_description_en_minuscules.sql`, exécuté dans une transaction (pas de `BEGIN`/`COMMIT`) ;
- **ne jamais modifier une migration déjà livrée** (l'empreinte est contrôlée) : en ajouter une nouvelle ;
- écrire en mode « élargir puis contracter » : ajouter des colonnes/tables sans supprimer les anciennes,
  pour que l'ancienne version du code continue de fonctionner pendant le déploiement ;
- `001_base_initiale.sql` est idempotent : il s'applique aussi bien sur une base vide que sur une
  base PostgreSQL existante (production comprise).
