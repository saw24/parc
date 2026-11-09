<?php
// Teste l'exécuteur de migrations et les migrations elles-mêmes sur des bases jetables
// (zz_migtest_*), créées puis supprimées. Ne touche pas à la base configurée.
//   php backend/tests/migrations_test.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI uniquement.'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Migrator.php';

$ok = 0; $ko = 0;
function check(string $nom, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ✓ $nom\n"; } else { $ko++; echo "  ✗ $nom $detail\n"; }
}
function base_jetable(PDO $admin, string $nom): PDO {
    global $host, $port, $username, $password;
    $admin->exec("DROP DATABASE IF EXISTS $nom");
    $admin->exec("CREATE DATABASE $nom");
    return new PDO("pgsql:host=$host;port=$port;dbname=$nom;gssencmode=disable", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false]);
}
$bases = [];
$mdir = realpath(__DIR__ . '/../migrations');

try {
    echo "Base vide\n";
    $bases[] = 'zz_migtest_a';
    $a = base_jetable($pdo, 'zz_migtest_a');
    $m = new Migrator($a, $mdir);
    check('preflight sur base vide : rien à signaler', $m->preflight() === []);
    $faites = $m->up(true);
    check('à blanc : ' . count($faites) . ' migrations validées', count($faites) >= 6);
    check('à blanc : rien de conservé', !$a->query("SELECT to_regclass('t_roles')")->fetchColumn());
    $m->up();
    check('toutes les migrations appliquées', array_unique(array_column($m->statut(), 'etat')) === ['appliquee']);
    check('2e exécution : rien à faire', $m->up() === []);
    check('seul Administrateur est rôle système, aucun « Agent » créé',
        $a->query("SELECT string_agg(nom, ',' ORDER BY nom) FROM t_roles WHERE systeme")->fetchColumn() === 'Administrateur'
        && !$a->query("SELECT 1 FROM t_roles WHERE nom = 'Agent'")->fetchColumn());
    check('file commune « Service IT » créée, sans connexion',
        (bool) $a->query("SELECT 1 FROM t_utilisateurs WHERE est_file_commune AND NOT connexion_autorisee AND nom = 'Service IT'")->fetchColumn());
    check('Administrateur possède toutes les permissions',
        $a->query("SELECT COUNT(*) FROM t_roles_permissions rp JOIN t_roles r ON r.id = rp.role_id WHERE r.systeme")->fetchColumn()
        == $a->query("SELECT COUNT(*) FROM t_permissions")->fetchColumn());
    try { $a->exec("DELETE FROM t_roles WHERE nom = 'Administrateur'"); check('rôle système protégé contre la suppression', false); }
    catch (PDOException $e) { check('rôle système protégé contre la suppression', str_contains($e->getMessage(), 'ne peut pas être supprimé')); }
    $a->exec("INSERT INTO t_taches (titre, priorite, statut) VALUES ('x', 'Haute', 'À faire')");
    check('référence générée à l\'insertion', str_starts_with((string) $a->query("SELECT reference FROM t_taches")->fetchColumn(), 'T-'));
    $a->exec("UPDATE t_taches SET date_modification = '2000-01-01'");
    $a->exec("UPDATE t_taches SET titre = 'y'");
    check('date_modification mise à jour automatiquement', $a->query("SELECT date_modification > '2000-01-01' FROM t_taches")->fetchColumn() === true);

    echo "Garde-fous de l'exécuteur\n";
    $tmp = sys_get_temp_dir() . '/zz_mig_' . bin2hex(random_bytes(4));
    mkdir($tmp);
    foreach (glob("$mdir/*.sql") as $f) { copy($f, "$tmp/" . basename($f)); }
    file_put_contents("$tmp/007_ok.sql", "CREATE TABLE zz_ok (id INT);");
    file_put_contents("$tmp/008_ko.sql", "CREATE TABLE zz_ko (id INT);\nINSERT INTO table_inexistante VALUES (1);");
    $m2 = new Migrator($a, $tmp);
    try { $m2->up(); check('migration en échec → exception', false); }
    catch (MigrationException $e) { check('migration en échec → exception', str_contains($e->getMessage(), '008_ko')); }
    check('007 conservée, 008 entièrement annulée (aucune table zz_ko)',
        (bool) $a->query("SELECT to_regclass('zz_ok')")->fetchColumn() && !$a->query("SELECT to_regclass('zz_ko')")->fetchColumn());
    file_put_contents("$tmp/001_base_initiale.sql", file_get_contents("$tmp/001_base_initiale.sql") . "\n-- modifiée");
    try { $m2->up(); check('migration modifiée après application → refus', false); }
    catch (MigrationException $e) { check('migration modifiée après application → refus', str_contains($e->getMessage(), 'modifiée')); }
    file_put_contents("$tmp/009_tx.sql", "BEGIN;\nSELECT 1;\nCOMMIT;");
    copy("$mdir/001_base_initiale.sql", "$tmp/001_base_initiale.sql");
    unlink("$tmp/008_ko.sql");
    try { $m2->up(); check('BEGIN/COMMIT dans une migration → refus', false); }
    catch (MigrationException $e) { check('BEGIN/COMMIT dans une migration → refus', str_contains($e->getMessage(), 'BEGIN')); }
    array_map('unlink', glob("$tmp/*")); rmdir($tmp);

    echo "Base existante (schéma historique + données)\n";
    $bases[] = 'zz_migtest_b';
    $b = base_jetable($pdo, 'zz_migtest_b');
    $b->exec(file_get_contents(__DIR__ . '/../sql/postgres/schema.sql'));
    $b->exec("INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe) VALUES
        ('Admin Un', 'admin', 'Administrateur', 'x'), ('Tech Un', 'tech1', 'Technicien', 'x'), ('Chef', 'chef', 'Superviseur', 'x')");
    $b->exec("INSERT INTO t_materiel (type, marque, modele, num_serie, statut) VALUES ('PC', 'Dell', 'X1', 'SN-42', 'Actif')");
    $b->exec("INSERT INTO t_taches (titre, priorite, statut, assigne_a, materiel_concerne, created_by_login) VALUES
        ('T1', 'Haute', 'En cours', 'Tech Un', 'PC Dell X1 (SN-42)', 'admin'),
        ('T2', 'Basse', 'À faire', 'Fantôme', 'Autre chose', 'inconnu')");
    $mb = new Migrator($b, $mdir);
    $pf = $mb->preflight();
    check('preflight signale l\'assigné inexistant', (bool) array_filter($pf, fn($l) => str_contains($l, 'Fantôme')));
    check('preflight signale le rôle inconnu', (bool) array_filter($pf, fn($l) => str_contains($l, 'Superviseur')));
    $mb->up();
    check('toutes les migrations appliquées sur base existante', array_unique(array_column($mb->statut(), 'etat')) === ['appliquee']);
    $r = $b->query("SELECT t.titre, t.assigne_id, u.nom, t.declarant_id, t.materiel_id FROM t_taches t LEFT JOIN t_utilisateurs u ON u.id = t.assigne_id ORDER BY t.id")->fetchAll();
    check('assigné rattaché par identifiant', $r[0]['nom'] === 'Tech Un');
    check('assigné inexistant : identifiant vide, nom conservé', $r[1]['assigne_id'] === null
        && $b->query("SELECT assigne_a FROM t_taches WHERE titre = 'T2'")->fetchColumn() === 'Fantôme');
    check('déclarant déduit du login de création', $r[0]['declarant_id'] !== null && $r[1]['declarant_id'] === null);
    check('matériel lié par numéro de série (correspondance unique)', $r[0]['materiel_id'] !== null && $r[1]['materiel_id'] === null);
    check('rôle « Technicien » repris comme rôle ordinaire avec ses droits',
        $b->query("SELECT systeme FROM t_roles WHERE nom = 'Technicien'")->fetchColumn() === false
        && (int) $b->query("SELECT COUNT(*) FROM t_roles_permissions rp JOIN t_roles r ON r.id = rp.role_id WHERE r.nom = 'Technicien'")->fetchColumn() === 12);
    check('rôle inconnu repris sans aucun droit',
        (int) $b->query("SELECT COUNT(*) FROM t_roles_permissions rp JOIN t_roles r ON r.id = rp.role_id WHERE r.nom = 'Superviseur'")->fetchColumn() === 0);
    check('évènement de création rétroactif pour chaque tâche', (int) $b->query("SELECT COUNT(*) FROM t_taches_evenements WHERE type = 'creation'")->fetchColumn() === 2);
    check('aucun « Agent » créé par la migration', !$b->query("SELECT 1 FROM t_roles WHERE nom = 'Agent'")->fetchColumn());

    echo "Homonymes : migration bloquée proprement\n";
    $bases[] = 'zz_migtest_c';
    $c = base_jetable($pdo, 'zz_migtest_c');
    $c->exec(file_get_contents(__DIR__ . '/../sql/postgres/schema.sql'));
    $c->exec("INSERT INTO t_utilisateurs (nom, login, role, mot_de_passe) VALUES ('Doublon', 'd1', 'Administrateur', 'x'), ('Doublon', 'd2', 'Administrateur', 'x')");
    $mc = new Migrator($c, $mdir);
    check('preflight signale les homonymes', (bool) array_filter($mc->preflight(), fn($l) => str_contains($l, 'Doublon')));
    try { $mc->up(); check('homonymes → migration refusée', false); }
    catch (MigrationException $e) { check('homonymes → migration refusée', str_contains($e->getMessage(), 'Doublon')); }
    check('base laissée intacte après l\'échec de 003 (001 et 002 conservées, pas de colonne téléphone)',
        !$c->query("SELECT 1 FROM information_schema.columns WHERE table_name = 't_utilisateurs' AND column_name = 'telephone'")->fetchColumn()
        && (bool) $c->query("SELECT to_regclass('t_roles')")->fetchColumn());
} finally {
    foreach ($bases as $nom) { $pdo->exec("DROP DATABASE IF EXISTS $nom WITH (FORCE)"); }
}
echo "\n$ok réussi(s), $ko échec(s)\n";
exit($ko === 0 ? 0 : 1);
