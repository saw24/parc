<?php
// Pannes : liste, détail, déclaration, prise en charge, affectation, statut, commentaires.

const V1_TACHE_SELECT = "
    SELECT t.id, t.reference, t.titre, t.description, t.motif_cause, t.priorite, t.statut, t.service_concerne,
           t.materiel_concerne, t.materiel_id, t.motif_attente, t.date_relance, t.date_limite, t.source, t.version, t.client_uuid,
           t.assigne_id, COALESCE(ua.nom, t.assigne_a) AS assigne_nom, COALESCE(ua.est_file_commune, FALSE) AS assigne_file,
           t.declarant_id, ud.nom AS declarant_nom,
           t.date_creation, t.date_modification, t.date_modification::text AS dm_raw,
           t.date_prise_en_charge, t.date_resolution, t.echeance_prise_en_charge, t.echeance_resolution,
           (t.statut NOT IN ('Terminé', 'Annulé') AND t.date_prise_en_charge IS NULL
                AND t.echeance_prise_en_charge IS NOT NULL AND t.echeance_prise_en_charge < NOW()) AS retard_prise_en_charge,
           (t.statut NOT IN ('Terminé', 'Annulé', 'En attente')
                AND t.echeance_resolution IS NOT NULL AND t.echeance_resolution < NOW()) AS retard_resolution
    FROM t_taches t
    LEFT JOIN t_utilisateurs ua ON ua.id = t.assigne_id
    LEFT JOIN t_utilisateurs ud ON ud.id = t.declarant_id
";

/** Clause SQL de visibilité des pannes pour cet utilisateur (ajoute ses paramètres). */
function v1_tache_visibilite(array $user, array &$params): string
{
    if (has_permission($user, 'tache.voir_toutes')) {
        return '';
    }
    if (has_permission($user, 'tache.voir_siennes')) {
        array_push($params, $user['id'], $user['id']);
        return '(t.declarant_id = ? OR t.assigne_id = ?)';
    }
    api_fail(403, 'interdit', 'Vous n\'avez pas accès aux pannes.');
}

/** Actions que l'utilisateur peut tenter sur cette panne (pour griser/masquer les boutons). */
/**
 * Niveau de modification des champs d'une panne : 'equipe' (modifier_toutes, ou modifier_siennes sur sa panne),
 * 'declarant' (le déclarant tant que personne n'a pris la panne) ou null.
 */
function v1_tache_niveau_modif(array $user, array $t): ?string
{
    $moi = (int) $t['assigne_id'] === (int) $user['id'];
    if (has_permission($user, 'tache.modifier_toutes') || (has_permission($user, 'tache.modifier_siennes') && $moi)) {
        return 'equipe';
    }
    $final = in_array($t['statut'], TACHE_STATUTS_FINAUX, true);
    if (!$final && (int) $t['declarant_id'] === (int) $user['id'] && $t['date_prise_en_charge'] === null
        && $t['statut'] === 'À faire' && has_permission($user, 'tache.declarer')) {
        return 'declarant';
    }
    return null;
}

function v1_tache_actions(array $user, array $t): array
{
    $final = in_array($t['statut'], TACHE_STATUTS_FINAUX, true);
    $moi = (int) $t['assigne_id'] === (int) $user['id'];
    $enFile = $t['assigne_id'] === null || $t['assigne_file'];
    $declarant = (int) $t['declarant_id'] === (int) $user['id'];
    return [
        'prendre' => !$final && has_permission($user, 'tache.prendre') && ($enFile || $moi && $t['statut'] === 'À faire'),
        'affecter' => !$final && has_permission($user, 'tache.affecter'),
        'changer_statut' => !$final && (has_permission($user, 'tache.modifier_toutes') || (has_permission($user, 'tache.modifier_siennes') && $moi)),
        'rouvrir' => $final && (has_permission($user, 'tache.modifier_toutes') || (has_permission($user, 'tache.modifier_siennes') && $moi)),
        'annuler' => !$final && $declarant && $t['date_prise_en_charge'] === null && $t['statut'] === 'À faire' && has_permission($user, 'tache.declarer'),
        'commenter' => true,
        'modifier' => v1_tache_niveau_modif($user, $t) !== null,
        'supprimer' => has_permission($user, 'tache.supprimer'),
    ];
}

function v1_tache_json(PDO $pdo, array $user, array $t): array
{
    return [
        'id' => (int) $t['id'], 'reference' => $t['reference'], 'titre' => $t['titre'], 'description' => $t['description'],
        'motif_cause' => $t['motif_cause'], 'priorite' => $t['priorite'], 'statut' => $t['statut'],
        'service' => $t['service_concerne'],
        'materiel' => $t['materiel_id'] !== null ? ['id' => (int) $t['materiel_id'], 'libelle' => $t['materiel_concerne']]
            : ($t['materiel_concerne'] ? ['id' => null, 'libelle' => $t['materiel_concerne']] : null),
        'assigne' => $t['assigne_nom'] === null ? null : ['id' => $t['assigne_id'] !== null ? (int) $t['assigne_id'] : null, 'nom' => $t['assigne_nom']],
        'en_file' => $t['assigne_id'] === null || (bool) $t['assigne_file'],
        'declarant' => $t['declarant_id'] === null ? null : ['id' => (int) $t['declarant_id'], 'nom' => $t['declarant_nom']],
        'motif_attente' => $t['motif_attente'], 'date_relance' => $t['date_relance'], 'date_limite' => $t['date_limite'],
        'dates' => [
            'creation' => api_ts($pdo, $t['date_creation']), 'prise_en_charge' => api_ts($pdo, $t['date_prise_en_charge']),
            'resolution' => api_ts($pdo, $t['date_resolution']),
            'echeance_prise_en_charge' => api_ts($pdo, $t['echeance_prise_en_charge']),
            'echeance_resolution' => api_ts($pdo, $t['echeance_resolution']),
        ],
        'sla' => ['retard_prise_en_charge' => (bool) $t['retard_prise_en_charge'], 'retard_resolution' => (bool) $t['retard_resolution']],
        'source' => $t['source'], 'version' => (int) $t['version'], 'client_uuid' => $t['client_uuid'],
        'updated_at' => api_ts($pdo, $t['date_modification']),
        'actions' => v1_tache_actions($user, $t),
    ];
}

function v1_tache_charger(PDO $pdo, array $user, int $id): array
{
    $params = [$id];
    $vis = v1_tache_visibilite($user, $params);
    $st = $pdo->prepare(V1_TACHE_SELECT . ' WHERE t.id = ?' . ($vis ? " AND $vis" : ''));
    $st->execute($params);
    $t = $st->fetch();
    if (!$t) {
        api_fail(404, 'introuvable', 'Panne introuvable.');
    }
    return $t;
}

/** Convertit l'échec d'une transition métier en erreur d'API. */
function v1_transition(array $c, int $id, string $action, array $p): never
{
    $r = tache_transition($c['pdo'], $id, $c['user'], $action, $p);
    if (!$r['ok']) {
        $codes = [400 => 'requete_invalide', 403 => 'interdit', 404 => 'introuvable', 409 => 'conflit', 422 => 'donnees_invalides'];
        api_fail($r['code'], $codes[$r['code']] ?? 'erreur', $r['error']);
    }
    $t = v1_tache_charger($c['pdo'], $c['user'], $id);
    api_data(v1_tache_json($c['pdo'], $c['user'], $t), ['evenement' => $r['evenement']]);
}

route('GET', 'taches', function (array $c): void {
    $pdo = $c['pdo']; $q = $c['query']; $u = $c['user'];
    $params = [];
    $vis = v1_tache_visibilite($u, $params);
    $where = $vis ? [$vis] : [];

    if (!empty($q['ids_only'])) {   // pour purger localement les pannes supprimées/devenues invisibles
        $st = $pdo->prepare('SELECT t.id FROM t_taches t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.id');
        $st->execute($params);
        api_data(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), ['server_time' => api_now($pdo)]);
    }
    if (!empty($q['statut'])) {
        $statuts = array_filter(array_map('trim', explode(',', (string) $q['statut'])));
        $where[] = 't.statut IN (' . implode(',', array_fill(0, count($statuts), '?')) . ')';
        array_push($params, ...$statuts);
    }
    if (!empty($q['ouvertes'])) {
        $where[] = "t.statut NOT IN ('Terminé', 'Annulé')";
    }
    if (isset($q['assigne'])) {
        if ($q['assigne'] === 'moi') { $where[] = 't.assigne_id = ?'; $params[] = $u['id']; }
        elseif ($q['assigne'] === 'file') {
            $where[] = '(t.assigne_id IS NULL OR t.assigne_id = ?)'; $params[] = (tache_file_commune($pdo)['id'] ?? 0);
        } elseif (ctype_digit((string) $q['assigne'])) { $where[] = 't.assigne_id = ?'; $params[] = (int) $q['assigne']; }
    }
    if (($q['declarant'] ?? '') === 'moi') { $where[] = 't.declarant_id = ?'; $params[] = $u['id']; }
    if (!empty($q['materiel_id']) && ctype_digit((string) $q['materiel_id'])) { $where[] = 't.materiel_id = ?'; $params[] = (int) $q['materiel_id']; }
    if (!empty($q['q'])) {
        $motif = '%' . addcslashes(mb_substr((string) $q['q'], 0, 100), '%_\\') . '%';
        $where[] = '(t.titre ILIKE ? OR t.reference ILIKE ? OR t.description ILIKE ?)';
        array_push($params, $motif, $motif, $motif);
    }
    if (!empty($q['updated_since'])) {
        $where[] = 't.date_modification > ?'; $params[] = api_ts_in($pdo, (string) $q['updated_since']);
    }
    if ($cur = api_cursor_decode($q['cursor'] ?? null)) {
        $where[] = '(t.date_modification, t.id) < (?::timestamp, ?)'; array_push($params, (string) ($cur[0] ?? ''), (int) ($cur[1] ?? 0));
    }
    $limit = api_limit($q);
    $st = $pdo->prepare(V1_TACHE_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.date_modification DESC, t.id DESC LIMIT ' . ($limit + 1));
    $st->execute($params);
    $rows = $st->fetchAll();
    $suite = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $curseur = $suite && $rows ? api_cursor_encode([end($rows)['dm_raw'], (int) end($rows)['id']]) : null;
    api_data(array_map(fn($t) => v1_tache_json($pdo, $u, $t), $rows), ['next_cursor' => $curseur, 'server_time' => api_now($pdo)]);
});

route('GET', 'taches/{id}', function (array $c): void {
    $pdo = $c['pdo']; $u = $c['user'];
    $t = v1_tache_charger($pdo, $u, $c['params']['id']);
    $json = v1_tache_json($pdo, $u, $t);
    // Les commentaires « internes » (non visibles du déclarant) ne sont montrés qu'à l'équipe IT.
    $equipe = has_permission($u, 'tache.voir_toutes') || (int) $t['assigne_id'] === (int) $u['id'];
    $st = $pdo->prepare("
        SELECT e.id, e.type, e.acteur_id, e.acteur_nom, e.ancien_statut, e.nouveau_statut, e.commentaire, e.visible_declarant, e.date_creation,
               e.ancien_assigne_id, ao.nom AS ancien_assigne_nom, e.nouveau_assigne_id, an.nom AS nouveau_assigne_nom
        FROM t_taches_evenements e
        LEFT JOIN t_utilisateurs ao ON ao.id = e.ancien_assigne_id
        LEFT JOIN t_utilisateurs an ON an.id = e.nouveau_assigne_id
        WHERE e.tache_id = ?" . ($equipe ? '' : ' AND e.visible_declarant') . "
        ORDER BY e.date_creation, e.id
    ");
    $st->execute([$t['id']]);
    $json['evenements'] = array_map(fn($e) => [
        'id' => (int) $e['id'], 'type' => $e['type'], 'created_at' => api_ts($pdo, $e['date_creation']),
        'acteur' => $e['acteur_nom'] === null && $e['acteur_id'] === null ? null : ['id' => $e['acteur_id'] !== null ? (int) $e['acteur_id'] : null, 'nom' => $e['acteur_nom']],
        'ancien_statut' => $e['ancien_statut'], 'nouveau_statut' => $e['nouveau_statut'],
        'ancien_assigne' => $e['ancien_assigne_id'] ? ['id' => (int) $e['ancien_assigne_id'], 'nom' => $e['ancien_assigne_nom']] : null,
        'nouveau_assigne' => $e['nouveau_assigne_id'] ? ['id' => (int) $e['nouveau_assigne_id'], 'nom' => $e['nouveau_assigne_nom']] : null,
        'commentaire' => $e['commentaire'], 'visible_declarant' => (bool) $e['visible_declarant'],
    ], $st->fetchAll());
    api_data($json);
});

route('POST', 'taches', function (array $c): void {
    $pdo = $c['pdo']; $u = $c['user']; $b = $c['body'];
    if (!has_any_permission($u, ['tache.declarer', 'tache.creer'])) {
        api_fail(403, 'interdit', 'Vous ne pouvez pas déclarer de panne.');
    }
    $peutCreer = has_permission($u, 'tache.creer');
    foreach (['assigne_id', 'date_limite'] as $reserve) {
        if (isset($b[$reserve]) && !$peutCreer) {
            api_fail(403, 'interdit', "Le champ « $reserve » est réservé aux agents IT.", ['champ' => $reserve]);
        }
    }
    $d = [
        'titre' => api_str($b, 'titre', true, 255, 3),
        'description' => api_str($b, 'description', false, 4000),
        'motif_cause' => api_str($b, 'motif_cause', false, 500),
        'priorite' => api_enum($b, 'priorite', ['Basse', 'Moyenne', 'Haute'], 'Moyenne'),
        'client_uuid' => api_uuid($b, 'client_uuid'),
        'assigne_id' => api_int($b, 'assigne_id'),
        'date_limite' => api_date($b, 'date_limite'),
        'source' => 'mobile',
        'materiel_id' => null,
    ];
    $serviceId = api_int($b, 'service_id') ?? $u['service_id'];
    if ($serviceId === null) {
        api_fail(422, 'champ_requis', 'Indiquez le service concerné (« service_id »).', ['champ' => 'service_id']);
    }
    $st = $pdo->prepare("SELECT nom FROM t_services WHERE id = ?");
    $st->execute([$serviceId]);
    $d['service_nom'] = $st->fetchColumn() ?: api_fail(422, 'champ_invalide', 'Service inconnu.', ['champ' => 'service_id']);

    if (($mid = api_int($b, 'materiel_id')) !== null) {
        try {
            materiel_trouver($pdo, $u, $mid);   // doit exister ET être visible par ce compte
        } catch (ApiException) {
            api_fail(422, 'champ_invalide', 'Matériel inconnu ou inaccessible.', ['champ' => 'materiel_id']);
        }
        $d['materiel_id'] = $mid;
    }
    $pdo->beginTransaction();
    try {
        $r = tache_declarer($pdo, $u, $d);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $t = v1_tache_charger($pdo, $u, $r['id']);
    api_data(v1_tache_json($pdo, $u, $t), ['deja_existante' => !$r['cree']], $r['cree'] ? 201 : 200);
});

route('POST', 'taches/{id}/prendre', fn(array $c) => v1_transition($c, $c['params']['id'], 'prendre',
    ['version' => api_int($c['body'], 'version')]));

route('POST', 'taches/{id}/affecter', function (array $c): void {
    v1_transition($c, $c['params']['id'], 'affecter', ['assigne_id' => api_int($c['body'], 'assigne_id', true),
        'commentaire' => api_str($c['body'], 'commentaire', false, 2000), 'version' => api_int($c['body'], 'version')]);
});

route('POST', 'taches/{id}/statut', function (array $c): void {
    v1_transition($c, $c['params']['id'], 'statut', [
        'statut' => api_enum($c['body'], 'statut', ['À faire', 'En cours', 'En attente', 'Terminé', 'Annulé']),
        'motif_attente' => api_str($c['body'], 'motif_attente', false, 1000), 'date_relance' => api_date($c['body'], 'date_relance'),
        'commentaire' => api_str($c['body'], 'commentaire', false, 2000), 'version' => api_int($c['body'], 'version')]);
});

route('POST', 'taches/{id}/commentaires', function (array $c): void {
    v1_transition($c, $c['params']['id'], 'commenter', [
        'commentaire' => api_str($c['body'], 'commentaire', true, 2000),
        // Un déclarant ne peut pas écrire de note interne ; l'équipe IT peut masquer un commentaire au déclarant.
        'visible_declarant' => has_permission($c['user'], 'tache.voir_toutes') ? (bool) ($c['body']['visible_declarant'] ?? true) : true]);
});

// Compteurs de l'écran d'accueil.
route('GET', 'accueil', function (array $c): void {
    $pdo = $c['pdo']; $u = $c['user'];
    $params = [];
    $vis = v1_tache_visibilite($u, $params);
    $base = $vis ? "WHERE $vis AND " : 'WHERE ';
    $ouvert = "t.statut NOT IN ('Terminé', 'Annulé')";
    $file = tache_file_commune($pdo)['id'] ?? 0;
    $st = $pdo->prepare("
        SELECT
          COUNT(*) FILTER (WHERE $ouvert AND t.declarant_id = ?) AS mes_pannes_ouvertes,
          COUNT(*) FILTER (WHERE $ouvert AND t.assigne_id = ?) AS mes_assignees,
          COUNT(*) FILTER (WHERE $ouvert AND (t.assigne_id IS NULL OR t.assigne_id = ?)) AS en_file,
          COUNT(*) FILTER (WHERE $ouvert AND t.date_prise_en_charge IS NULL AND t.echeance_prise_en_charge < NOW()) AS retard_prise_en_charge,
          COUNT(*) FILTER (WHERE $ouvert AND t.statut <> 'En attente' AND t.echeance_resolution < NOW()) AS retard_resolution,
          COUNT(*) FILTER (WHERE t.statut = 'En attente' AND t.date_relance IS NOT NULL AND t.date_relance <= CURRENT_DATE) AS relances_dues
        FROM t_taches t $base TRUE
    ");
    $st->execute(array_merge([$u['id'], $u['id'], $file], $params));
    $r = array_map('intval', $st->fetch());
    if (!has_any_permission($u, ['tache.prendre', 'tache.voir_toutes'])) {
        $r['en_file'] = 0;
    }
    api_data($r, ['server_time' => api_now($pdo)]);
});


// Modification des champs d'une panne (partielle). L'équipe peut tout changer ; le déclarant seulement titre,
// description, priorité, service et matériel, tant que personne n'a pris la panne.
route('PATCH', 'taches/{id}', function (array $c): void {
    $pdo = $c['pdo']; $u = $c['user']; $b = $c['body'];
    $t = v1_tache_charger($pdo, $u, $c['params']['id']);
    $niveau = v1_tache_niveau_modif($u, $t);
    if ($niveau === null) {
        api_fail(403, 'interdit', 'Vous ne pouvez pas modifier cette panne.');
    }
    $champs = []; $lib = [];
    if (array_key_exists('titre', $b)) { $champs['titre'] = api_str($b, 'titre', true, 200); $lib['titre'] = 'titre'; }
    if (array_key_exists('description', $b)) { $champs['description'] = api_str($b, 'description', false, 5000); $lib['description'] = 'description'; }
    if (array_key_exists('priorite', $b)) { $champs['priorite'] = api_enum($b, 'priorite', ['Basse', 'Moyenne', 'Haute']); $lib['priorite'] = 'priorité'; }
    if (array_key_exists('service_id', $b)) {
        $sid = api_int($b, 'service_id', true);
        $sv = $pdo->prepare("SELECT nom FROM t_services WHERE id = ?");
        $sv->execute([$sid]);
        $champs['service_concerne'] = $sv->fetchColumn() ?: api_fail(422, 'champ_invalide', 'Service inconnu.', ['champ' => 'service_id']);
        $lib['service_concerne'] = 'service';
    }
    if (array_key_exists('materiel_id', $b)) {
        $mid = api_int($b, 'materiel_id');
        if ($mid === null) {
            $champs['materiel_id'] = null; $champs['materiel_concerne'] = null;
        } else {
            try { materiel_trouver($pdo, $u, $mid); } catch (ApiException) {
                api_fail(422, 'champ_invalide', 'Matériel inconnu ou inaccessible.', ['champ' => 'materiel_id']);
            }
            $m = $pdo->prepare("SELECT type, marque, modele, num_serie FROM t_materiel WHERE id = ?");
            $m->execute([$mid]);
            $m = $m->fetch();
            $champs['materiel_id'] = $mid;
            $champs['materiel_concerne'] = trim($m['type'] . ' ' . $m['marque'] . ' ' . $m['modele']) . ' (' . $m['num_serie'] . ')';
        }
        $lib['materiel_id'] = 'matériel'; $lib['materiel_concerne'] = 'matériel';
    }
    foreach (['motif_cause' => 'cause', 'date_limite' => 'date limite'] as $col => $nom) {
        if (array_key_exists($col, $b)) {
            if ($niveau !== 'equipe') {
                api_fail(403, 'interdit', "Le champ « $col » est réservé à l'équipe IT.", ['champ' => $col]);
            }
            $champs[$col] = $col === 'date_limite' ? api_date($b, $col) : api_str($b, $col, false, 500);
            $lib[$col] = $nom;
        }
    }
    if (!$champs) {
        api_fail(422, 'donnees_invalides', 'Aucun champ à modifier.');
    }
    $maj = tache_modifier($pdo, $c['params']['id'], $u, $champs, api_int($b, 'version'), $lib);
    api_data(v1_tache_json($pdo, $u, v1_tache_charger($pdo, $u, (int) $maj['id'])));
});

// Suppression définitive (historique et notifications compris) : réservée à tache.supprimer.
route('DELETE', 'taches/{id}', function (array $c): void {
    if (!has_permission($c['user'], 'tache.supprimer')) {
        api_fail(403, 'interdit', 'Permission manquante : tache.supprimer', ['permission' => 'tache.supprimer']);
    }
    v1_tache_charger($c['pdo'], $c['user'], $c['params']['id']);
    $c['pdo']->prepare("DELETE FROM t_taches WHERE id = ?")->execute([$c['params']['id']]);
    api_data(['supprimee' => true]);
});
