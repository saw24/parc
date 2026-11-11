<?php
// Campagnes d'inventaire : création, consultation, pointage (scan QR) et clôture.

route('GET', 'inventaires', function (array $c): void {
    require_permission_v1($c['user'], 'inventaire.voir');
    $st = $c['pdo']->query("SELECT i.*, u.nom AS cree_par_nom FROM t_inventaires i LEFT JOIN t_utilisateurs u ON u.id = i.created_by
                            ORDER BY (i.statut = 'Ouvert') DESC, i.created_at DESC, i.id DESC LIMIT 100");
    api_data(array_map(fn($i) => inv_json($c['pdo'], $i), $st->fetchAll()));
});

route('POST', 'inventaires', function (array $c): void {
    require_permission_v1($c['user'], 'inventaire.gerer');
    $id = inv_creer($c['pdo'], $c['user'], $c['body']);
    api_data(inv_json($c['pdo'], inv_trouver($c['pdo'], $id)), [], 201);
});

route('GET', 'inventaires/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'inventaire.voir');
    $pdo = $c['pdo']; $i = inv_trouver($pdo, $c['params']['id']);
    $st = $pdo->prepare(INV_LIGNES_SELECT . " WHERE l.inventaire_id = ? ORDER BY (l.statut = 'À pointer') DESC, l.hors_perimetre, m.type, m.marque, l.id LIMIT 3000");
    $st->execute([$c['params']['id']]);
    api_data(inv_json($pdo, $i) + ['lignes' => array_map('inv_ligne_json', $st->fetchAll())]);
});

route('POST', 'inventaires/{id}/pointer', function (array $c): void {
    require_permission_v1($c['user'], 'inventaire.pointer');
    $ligne = inv_pointer($c['pdo'], $c['user'], $c['params']['id'], $c['body']);
    api_data($ligne + ['compteurs' => inv_compteurs($c['pdo'], $c['params']['id'])]);
});

route('POST', 'inventaires/{id}/cloture', function (array $c): void {
    require_permission_v1($c['user'], 'inventaire.gerer');
    $n = inv_cloturer($c['pdo'], $c['user'], $c['params']['id'], (bool) ($c['body']['marquer_manquants'] ?? true));
    api_data(inv_json($c['pdo'], inv_trouver($c['pdo'], $c['params']['id'])), ['marques_manquants' => $n]);
});

route('DELETE', 'inventaires/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'inventaire.gerer');
    inv_trouver($c['pdo'], $c['params']['id']);
    $c['pdo']->prepare('DELETE FROM t_inventaires WHERE id = ?')->execute([$c['params']['id']]);
    api_data(['supprime' => true]);
});
