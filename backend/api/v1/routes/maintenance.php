<?php
// Maintenance préventive : plans récurrents et génération des tâches.

route('GET', 'maintenance/plans', function (array $c): void {
    require_permission_v1($c['user'], 'maintenance.voir');
    api_data(array_map('maint_json', $c['pdo']->query(MAINT_SELECT . ' ORDER BY p.actif DESC, p.prochaine_echeance, p.id')->fetchAll()));
});

route('GET', 'maintenance/plans/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'maintenance.voir');
    $pdo = $c['pdo'];
    $st = $pdo->prepare("SELECT o.echeance, o.tache_id, t.reference, t.statut, t.titre FROM t_plan_occurrences o
                         LEFT JOIN t_taches t ON t.id = o.tache_id WHERE o.plan_id = ? ORDER BY o.echeance DESC, o.id DESC LIMIT 30");
    $st->execute([$c['params']['id']]);
    api_data(maint_json(maint_trouver($pdo, $c['params']['id'])) + ['occurrences' => array_map(fn($o) => [
        'echeance' => $o['echeance'], 'tache' => $o['tache_id'] !== null ? ['id' => (int) $o['tache_id'], 'reference' => $o['reference'], 'statut' => $o['statut']] : null], $st->fetchAll())]);
});

route('POST', 'maintenance/plans', function (array $c): void {
    require_permission_v1($c['user'], 'maintenance.gerer');
    $id = maint_creer($c['pdo'], $c['user'], $c['body']);
    api_data(maint_json(maint_trouver($c['pdo'], $id)), [], 201);
});

route('PATCH', 'maintenance/plans/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'maintenance.gerer');
    maint_modifier($c['pdo'], $c['params']['id'], $c['body']);
    api_data(maint_json(maint_trouver($c['pdo'], $c['params']['id'])));
});

route('DELETE', 'maintenance/plans/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'maintenance.gerer');
    maint_trouver($c['pdo'], $c['params']['id']);
    $c['pdo']->prepare('DELETE FROM t_plans_maintenance WHERE id = ?')->execute([$c['params']['id']]);
    api_data(['supprime' => true]);
});

// Génère tout de suite les tâches d'un plan (ou de tous) sans attendre le passage du cron.
route('POST', 'maintenance/generer', function (array $c): void {
    require_permission_v1($c['user'], 'maintenance.gerer');
    $planId = api_int($c['body'], 'plan_id');
    if ($planId !== null) { maint_trouver($c['pdo'], $planId); }
    api_data(maint_generer($c['pdo'], $planId));
});
