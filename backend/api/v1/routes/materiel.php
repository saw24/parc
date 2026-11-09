<?php
// Parc matériel : liste, recherche, scan QR, détail, historique, création, modification, suppression.

route('GET', 'materiel', function (array $c): void {
    $pdo = $c['pdo']; $q = $c['query']; $u = $c['user'];
    $params = [];
    $vis = materiel_visibilite($u, $params);
    $where = $vis ? [$vis] : [];

    if (!empty($q['ids_only'])) {
        $st = $pdo->prepare('SELECT m.id FROM t_materiel m' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY m.id');
        $st->execute($params);
        api_data(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), ['server_time' => api_now($pdo)]);
    }
    if (!empty($q['q'])) {
        $motif = '%' . addcslashes(mb_substr((string) $q['q'], 0, 100), '%_\\') . '%';
        $where[] = "(m.type ILIKE ? OR m.marque ILIKE ? OR m.modele ILIKE ? OR m.num_serie ILIKE ? OR m.utilisateur_assigne ILIKE ?)";
        array_push($params, $motif, $motif, $motif, $motif, $motif);
    }
    if (!empty($q['statut'])) { $where[] = 'm.statut = ?'; $params[] = (string) $q['statut']; }
    if (!empty($q['type'])) { $where[] = 'm.type = ?'; $params[] = (string) $q['type']; }
    if (!empty($q['service_id']) && ctype_digit((string) $q['service_id'])) { $where[] = 's.id = ?'; $params[] = (int) $q['service_id']; }
    if (($q['assigne'] ?? '') === 'moi') { $where[] = 'm.utilisateur_assigne = ?'; $params[] = $u['nom']; }
    if (!empty($q['updated_since'])) { $where[] = 'm.date_modification > ?'; $params[] = api_ts_in($pdo, (string) $q['updated_since']); }
    if ($cur = api_cursor_decode($q['cursor'] ?? null)) {
        $where[] = '(m.date_modification, m.id) < (?::timestamp, ?)'; array_push($params, (string) ($cur[0] ?? ''), (int) ($cur[1] ?? 0));
    }
    $limit = api_limit($q);
    $st = $pdo->prepare(MATERIEL_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY m.date_modification DESC, m.id DESC LIMIT ' . ($limit + 1));
    $st->execute($params);
    $rows = $st->fetchAll();
    $suite = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $curseur = $suite && $rows ? api_cursor_encode([end($rows)['dm_raw'], (int) end($rows)['id']]) : null;
    api_data(array_map(fn($m) => materiel_json($pdo, $m), $rows), ['next_cursor' => $curseur, 'server_time' => api_now($pdo)]);
});

// Scan d'une étiquette QR (ou saisie d'un numéro de série) : ?code=<contenu scanné>
route('GET', 'materiel/scan', function (array $c): void {
    $code = (string) ($c['query']['code'] ?? '');
    if (trim($code) === '' || mb_strlen($code) > 500) {
        api_fail(422, 'champ_requis', 'Le paramètre « code » est requis.', ['champ' => 'code']);
    }
    api_data(materiel_json($c['pdo'], materiel_depuis_code($c['pdo'], $c['user'], $code)));
});

route('GET', 'materiel/{id}', fn(array $c) => api_data(
    materiel_json($c['pdo'], materiel_trouver($c['pdo'], $c['user'], $c['params']['id']))));

route('GET', 'materiel/{id}/affectations', function (array $c): void {
    materiel_trouver($c['pdo'], $c['user'], $c['params']['id']);   // contrôle de visibilité
    $st = $c['pdo']->prepare("SELECT id, utilisateur_assigne, service_affecte, date_debut, date_fin, changed_by
                              FROM t_materiel_affectations WHERE materiel_id = ? ORDER BY date_debut DESC, id DESC");
    $st->execute([$c['params']['id']]);
    api_data(array_map(fn($h) => ['id' => (int) $h['id'], 'utilisateur' => $h['utilisateur_assigne'], 'service' => $h['service_affecte'],
        'debut' => api_ts($c['pdo'], $h['date_debut']), 'fin' => api_ts($c['pdo'], $h['date_fin']), 'par' => $h['changed_by']], $st->fetchAll()));
});

route('GET', 'materiel/{id}/pannes', function (array $c): void {
    materiel_trouver($c['pdo'], $c['user'], $c['params']['id']);
    $params = [$c['params']['id']];
    $vis = v1_tache_visibilite($c['user'], $params);
    $st = $c['pdo']->prepare(V1_TACHE_SELECT . ' WHERE t.materiel_id = ?' . ($vis ? " AND $vis" : '') . ' ORDER BY t.date_creation DESC LIMIT 50');
    $st->execute($params);
    api_data(array_map(fn($t) => v1_tache_json($c['pdo'], $c['user'], $t), $st->fetchAll()));
});

route('POST', 'materiel', function (array $c): void {
    require_permission_v1($c['user'], 'materiel.creer');
    $id = materiel_creer($c['pdo'], $c['user'], $c['body']);
    api_data(materiel_json($c['pdo'], materiel_trouver($c['pdo'], $c['user'], $id)), [], 201);
});

route('PATCH', 'materiel/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'materiel.modifier');
    materiel_modifier($c['pdo'], $c['user'], $c['params']['id'], $c['body']);
    api_data(materiel_json($c['pdo'], materiel_trouver($c['pdo'], $c['user'], $c['params']['id'])));
});

route('DELETE', 'materiel/{id}', function (array $c): void {
    require_permission_v1($c['user'], 'materiel.supprimer');
    $st = $c['pdo']->prepare("DELETE FROM t_materiel WHERE id = ?");
    $st->execute([$c['params']['id']]);
    if (!$st->rowCount()) {
        api_fail(404, 'introuvable', 'Matériel introuvable.');
    }
    api_data(['supprime' => true]);
});
