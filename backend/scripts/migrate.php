<?php
// Migrations de base de données. Usage (ligne de commande uniquement) :
//   php backend/scripts/migrate.php status
//   php backend/scripts/migrate.php preflight
//   php backend/scripts/migrate.php up [--dry-run] [--backup] [--backup-dir=/chemin]
//
// --dry-run : exécute les migrations en attente dans une transaction puis l'annule.
// --backup  : fait un pg_dump (format custom) AVANT d'appliquer quoi que ce soit.
// Code de sortie : 0 = OK, 1 = erreur ou problèmes bloquants.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Migrator.php';

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'status';
$flags = array_slice($args, 1);
$dryRun = in_array('--dry-run', $flags, true);
$backup = in_array('--backup', $flags, true);
$backupDir = null;
foreach ($flags as $f) {
    if (str_starts_with($f, '--backup-dir=')) {
        $backupDir = substr($f, strlen('--backup-dir='));
        $backup = true;
    }
}

$m = new Migrator($pdo, __DIR__ . '/../migrations');

try {
    switch ($cmd) {
        case 'status':
            foreach ($m->statut() as $e) {
                $libelle = ['appliquee' => 'appliquée ', 'en_attente' => 'EN ATTENTE', 'modifiee' => 'MODIFIÉE !', 'manquante' => 'MANQUANTE!'][$e['etat']];
                echo sprintf("  [%s] %s%s\n", $libelle, $e['version'], $e['applied_at'] ? "  ({$e['applied_at']})" : '');
            }
            break;

        case 'preflight':
            $p = $m->preflight();
            if (!$p) {
                echo "Aucun problème détecté.\n";
            }
            foreach ($p as $ligne) {
                echo "  ! $ligne\n";
            }
            break;

        case 'up':
            $en_attente = array_filter($m->statut(), fn($e) => $e['etat'] === 'en_attente');
            if (!$en_attente) {
                echo "Base à jour, rien à faire.\n";
                break;
            }
            if ($backup && !$dryRun) {
                $dir = $backupDir ?: (__DIR__ . '/../backups');
                if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                    throw new RuntimeException("Impossible de créer le dossier de sauvegarde : $dir");
                }
                $fichier = rtrim($dir, '/') . '/avant_migration_' . date('Ymd_His') . '.dump';
                $cmdDump = sprintf(
                    'pg_dump -Fc -h %s -p %s -U %s -f %s %s',
                    escapeshellarg($host), escapeshellarg($port), escapeshellarg($username), escapeshellarg($fichier), escapeshellarg($dbname)
                );
                $proc = proc_open($cmdDump, [2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['PGPASSWORD' => $password]));
                $err = stream_get_contents($pipes[2]);
                if (proc_close($proc) !== 0 || !is_file($fichier) || filesize($fichier) === 0) {
                    throw new RuntimeException("Sauvegarde échouée, migration annulée : $err");
                }
                chmod($fichier, 0600);
                echo "Sauvegarde : $fichier\n";
            } elseif (!$dryRun) {
                echo "Attention : aucune sauvegarde demandée (--backup).\n";
            }
            $faites = $m->up($dryRun, fn($l) => print("$l\n"));
            echo $dryRun
                ? count($faites) . " migration(s) validée(s) à blanc, aucune modification conservée.\n"
                : count($faites) . " migration(s) appliquée(s).\n";
            break;

        default:
            fwrite(STDERR, "Commande inconnue : $cmd (status | preflight | up)\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERREUR : " . $e->getMessage() . "\n");
    exit(1);
}
