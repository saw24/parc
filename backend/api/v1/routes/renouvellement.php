<?php
// Renouvellement du matériel : suggestions automatiques, propositions et décisions.

route('GET', 'renouvellements/suggestions', function (array $c): void {
    require_permission_v1($c['user'], 'renouvellement.voir');
    api_data(renouv_suggestions($c['pdo']), ['regles' => [
        'duree_vie_defaut_mois' => RENOUV_DUREE_VIE_DEFAUT, 'pannes_seuil' => RENOUV_PANNES_SEUIL, 'pannes_fenetre_mois' => RENOUV_PANNES_FENETRE_MOIS]]);
});

route('GET', 'renouvellements', function (array $c): void {
    require_permission_v1($c['user'], 'renouvellement.voir');
    $where = []; $params = [];
    if (!empty($c['query']['statut']) && in_array($c['query']['statut'], RENOUV_STATUTS, true)) { $where[] = 'r.statut = ?'; $params[] = $c['query']['statut']; }
    if (!empty($c['query']['actives'])) { $where[] = "r.statut IN ('Proposé', 'Validé', 'Commandé')"; }
    $st = $c['pdo']->prepare(RENOUV_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.updated_at DESC, r.id DESC LIMIT 200');
    $st->execute($params);
    api_data(array_map(fn($r) => renouv_json($c['pdo'], $r), $st->fetchAll()));
});

route('POST', 'renouvellements', function (array $c): void {
    require_permission_v1($c['user'], 'renouvellement.proposer');
    $cout = $c['body']['cout_estime'] ?? null;
    if ($cout !== null && (!is_numeric($cout) || $cout < 0)) { api_fail(422, 'valeur_invalide', 'Coût estimé invalide.', ['champ' => 'cout_estime']); }
    $id = renouv_proposer($c['pdo'], $c['user'], api_int($c['body'], 'materiel_id', true),
        api_str($c['body'], 'motif', false, 2000), $cout !== null ? (float) $cout : null);
    api_data(renouv_json($c['pdo'], renouv_trouver($c['pdo'], $id)), [], 201);
});

route('POST', 'renouvellements/{id}/decision', function (array $c): void {
    require_permission_v1($c['user'], 'renouvellement.decider');
    renouv_decider($c['pdo'], $c['user'], $c['params']['id'],
        api_enum($c['body'], 'decision', ['Validé', 'Refusé', 'Commandé', 'Remplacé']) ?? api_fail(422, 'champ_requis', 'Le champ « decision » est requis.', ['champ' => 'decision']), api_str($c['body'], 'commentaire', false, 2000));
    api_data(renouv_json($c['pdo'], renouv_trouver($c['pdo'], $c['params']['id'])));
});
