<?php
// Exécuteur de migrations SQL versionnées pour PostgreSQL.
//
// - Les migrations sont des fichiers backend/migrations/NNN_description.sql, appliqués dans
//   l'ordre du nom. Chaque fichier s'exécute dans UNE transaction (PostgreSQL gère les
//   modifications de structure de façon transactionnelle) : en cas d'erreur, rien n'est
//   appliqué pour ce fichier. Les fichiers ne doivent donc PAS contenir BEGIN/COMMIT.
// - L'état est mémorisé dans t_schema_migrations (version, empreinte SHA-256, date, durée).
//   Une migration déjà appliquée dont le fichier a changé est signalée et bloque l'exécution :
//   on n'édite jamais une migration livrée, on en ajoute une nouvelle.
// - Un verrou consultatif (advisory lock) empêche deux exécutions simultanées.
// - Le mode « à blanc » (dry-run) exécute réellement les migrations en attente dans une
//   transaction puis l'annule : il valide le SQL contre les vraies données sans rien modifier.

class MigrationException extends RuntimeException
{
}

class Migrator
{
    private const LOCK_KEY = 7420001;

    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return array<string,array{version:string,file:string,checksum:string}> */
    public function fichiers(): array
    {
        $out = [];
        foreach (glob(rtrim($this->dir, '/') . '/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            if (!preg_match('/^\d{3,}_[a-z0-9_]+$/', $version)) {
                throw new MigrationException("Nom de migration invalide : $version (attendu : 001_description_en_minuscules.sql)");
            }
            $out[$version] = ['version' => $version, 'file' => $file, 'checksum' => hash_file('sha256', $file)];
        }
        ksort($out);
        return $out;
    }

    private function assurerTableSuivi(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS t_schema_migrations (
                version      VARCHAR(100) PRIMARY KEY,
                checksum     CHAR(64)     NOT NULL,
                applied_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_ms  INTEGER      NOT NULL DEFAULT 0
            )
        ");
    }

    /** @return array<string,array{checksum:string,applied_at:string}> */
    private function appliquees(): array
    {
        $this->assurerTableSuivi();
        $out = [];
        foreach ($this->pdo->query("SELECT version, checksum, applied_at FROM t_schema_migrations ORDER BY version") as $r) {
            $out[$r['version']] = ['checksum' => trim($r['checksum']), 'applied_at' => $r['applied_at']];
        }
        return $out;
    }

    /**
     * État de chaque migration : 'appliquee' | 'en_attente' | 'modifiee' (fichier changé
     * depuis son application) | 'manquante' (appliquée mais fichier absent).
     * @return list<array{version:string,etat:string,applied_at:?string}>
     */
    public function statut(): array
    {
        $fichiers = $this->fichiers();
        $appliquees = $this->appliquees();
        $out = [];
        foreach ($fichiers as $v => $f) {
            if (!isset($appliquees[$v])) {
                $out[] = ['version' => $v, 'etat' => 'en_attente', 'applied_at' => null];
            } elseif ($appliquees[$v]['checksum'] !== $f['checksum']) {
                $out[] = ['version' => $v, 'etat' => 'modifiee', 'applied_at' => $appliquees[$v]['applied_at']];
            } else {
                $out[] = ['version' => $v, 'etat' => 'appliquee', 'applied_at' => $appliquees[$v]['applied_at']];
            }
        }
        foreach ($appliquees as $v => $a) {
            if (!isset($fichiers[$v])) {
                $out[] = ['version' => $v, 'etat' => 'manquante', 'applied_at' => $a['applied_at']];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['version'], $b['version']));
        return $out;
    }

    /**
     * Applique les migrations en attente. Retourne la liste des versions traitées.
     * @param callable(string):void|null $log
     * @return list<string>
     */
    public function up(bool $dryRun = false, ?callable $log = null): array
    {
        $log ??= static function (string $m): void {
        };
        $etat = $this->statut();
        foreach ($etat as $e) {
            if ($e['etat'] === 'modifiee') {
                throw new MigrationException("La migration {$e['version']} a été modifiée après son application. Ne jamais éditer une migration livrée : ajoutez-en une nouvelle.");
            }
            if ($e['etat'] === 'manquante') {
                throw new MigrationException("La migration {$e['version']} est enregistrée en base mais son fichier est introuvable (déploiement incomplet ?).");
            }
        }
        $enAttente = array_values(array_filter($etat, fn($e) => $e['etat'] === 'en_attente'));
        if (!$enAttente) {
            return [];
        }

        if (!$this->pdo->query("SELECT pg_try_advisory_lock(" . self::LOCK_KEY . ")")->fetchColumn()) {
            throw new MigrationException("Une autre exécution de migration est en cours.");
        }
        $fichiers = $this->fichiers();
        $faites = [];
        try {
            if ($dryRun) {
                $this->pdo->beginTransaction();
            }
            foreach ($enAttente as $e) {
                $f = $fichiers[$e['version']];
                $sql = file_get_contents($f['file']);
                if (preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK|START\s+TRANSACTION)\s*;/mi', $sql)) {
                    throw new MigrationException("{$e['version']} : les migrations ne doivent pas contenir de BEGIN;/COMMIT;/ROLLBACK; (la transaction est gérée par l'exécuteur).");
                }
                $debut = microtime(true);
                $log("→ {$e['version']}" . ($dryRun ? ' (à blanc)' : ''));
                if (!$dryRun) {
                    $this->pdo->beginTransaction();
                }
                try {
                    $this->pdo->exec($sql);
                    $ms = (int) round((microtime(true) - $debut) * 1000);
                    $this->pdo->prepare("INSERT INTO t_schema_migrations (version, checksum, duration_ms) VALUES (?, ?, ?)")
                        ->execute([$f['version'], $f['checksum'], $ms]);
                    if (!$dryRun) {
                        $this->pdo->commit();
                    }
                } catch (Throwable $t) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw new MigrationException("Échec de {$e['version']} (aucune modification conservée pour ce fichier) : " . $t->getMessage(), 0, $t);
                }
                $faites[] = $e['version'];
            }
            if ($dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->query("SELECT pg_advisory_unlock(" . self::LOCK_KEY . ")");
        }
        return $faites;
    }

    /**
     * Contrôles avant migration : problèmes de données qui feraient échouer ou fausser une
     * migration (ou qu'il faut corriger à la main). Retourne une liste de messages.
     * @return list<string>
     */
    public function preflight(): array
    {
        $problemes = [];
        $existe = fn(string $t) => (bool) $this->pdo->query("SELECT to_regclass('public.$t')")->fetchColumn();
        if (!$existe('t_utilisateurs')) {
            return [];
        }
        foreach ($this->pdo->query("SELECT nom, COUNT(*) c FROM t_utilisateurs GROUP BY nom HAVING COUNT(*) > 1") as $r) {
            $problemes[] = "Utilisateurs homonymes : « {$r['nom']} » ({$r['c']} comptes). Les noms doivent être uniques : renommez avant de migrer.";
        }
        if ($existe('t_taches')) {
            $q = "SELECT DISTINCT assigne_a FROM t_taches t WHERE COALESCE(TRIM(assigne_a),'') <> ''
                  AND NOT EXISTS (SELECT 1 FROM t_utilisateurs u WHERE u.nom = t.assigne_a)";
            foreach ($this->pdo->query($q) as $r) {
                $problemes[] = "Tâches assignées à « {$r['assigne_a']} », qui n'existe pas dans les utilisateurs : l'identifiant d'assigné restera vide pour ces tâches.";
            }
        }
        $q = "SELECT COUNT(*) FROM t_utilisateurs WHERE role = 'Administrateur'";
        if ((int) $this->pdo->query($q)->fetchColumn() === 0) {
            $problemes[] = "Aucun compte Administrateur : personne ne pourra gérer les rôles après migration.";
        }
        foreach ($this->pdo->query("SELECT DISTINCT role FROM t_utilisateurs WHERE role NOT IN ('Administrateur','Technicien')") as $r) {
            $problemes[] = "Rôle « {$r['role']} » inconnu : il sera créé sans aucun droit (à configurer ensuite).";
        }
        return $problemes;
    }
}
