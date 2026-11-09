<?php
// Boîte de réception de l'utilisateur. L'application l'interroge régulièrement (scrutation) : c'est le
// canal de base, qui fonctionne sans FCM. Les envois e-mail/SMS/push sont gérés par lib/notif_pannes.php.

/** GET notifications?apres_id=N&non_lues=1&limit=50 — les plus récentes d'abord ; apres_id ne renvoie que les plus récentes que N. */
route('GET', 'notifications', function (array $c): void {
    $pdo = $c['pdo'];
    try {
        notif_traiter_evenements($pdo);   // rattrape les évènements pas encore traités (la requête d'origine peut avoir échoué)
    } catch (Throwable $e) {
        error_log('Notifications : ' . $e->getMessage());
    }
    $q = $_GET;
    $limit = api_limit($q);
    $where = ['n.user_id = ?'];
    $args = [$c['user']['id']];
    if (isset($q['apres_id'])) {
        $where[] = 'n.id > ?'; $args[] = api_int($q, 'apres_id');
    }
    if (!empty($q['non_lues'])) {
        $where[] = 'n.lu_at IS NULL';
    }
    $peutPrendre = has_permission($c['user'], 'tache.prendre') ? 'true' : 'false';
    $st = $pdo->prepare("
        SELECT n.*, t.reference, t.statut AS tache_statut,
               ($peutPrendre AND n.prenable AND t.statut = 'À faire'
                AND (t.assigne_id IS NULL OR t.assigne_id = (SELECT id FROM t_utilisateurs WHERE est_file_commune LIMIT 1))) AS peut_prendre
        FROM t_notifications n JOIN t_taches t ON t.id = n.tache_id
        WHERE " . implode(' AND ', $where) . " ORDER BY n.id DESC LIMIT $limit");
    $st->execute($args);
    $liste = array_map(fn($n) => [
        'id' => (int) $n['id'], 'type' => $n['type'], 'titre' => $n['titre'], 'message' => $n['message'],
        'tache' => ['id' => (int) $n['tache_id'], 'reference' => $n['reference'], 'statut' => $n['tache_statut']],
        'peut_prendre' => (bool) $n['peut_prendre'], 'lue' => $n['lu_at'] !== null,
        'created_at' => api_ts($pdo, $n['created_at']),
    ], $st->fetchAll());
    $nb = $pdo->prepare("SELECT COUNT(*) FROM t_notifications WHERE user_id = ? AND lu_at IS NULL");
    $nb->execute([$c['user']['id']]);
    api_data($liste, ['non_lues' => (int) $nb->fetchColumn(), 'server_time' => api_now($pdo)]);
});

/** POST notifications/lues {ids:[…]} ou {toutes:true} */
route('POST', 'notifications/lues', function (array $c): void {
    $pdo = $c['pdo'];
    if (!empty($c['body']['toutes'])) {
        $n = $pdo->prepare("UPDATE t_notifications SET lu_at = CURRENT_TIMESTAMP WHERE user_id = ? AND lu_at IS NULL");
        $n->execute([$c['user']['id']]);
    } else {
        $ids = $c['body']['ids'] ?? null;
        if (!is_array($ids) || !$ids || count($ids) > 200 || array_filter($ids, fn($i) => !is_int($i))) {
            api_fail(422, 'champ_invalide', 'ids : liste d\'entiers attendue (200 maximum).', ['champ' => 'ids']);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = $pdo->prepare("UPDATE t_notifications SET lu_at = CURRENT_TIMESTAMP WHERE user_id = ? AND lu_at IS NULL AND id IN ($in)");
        $n->execute(array_merge([$c['user']['id']], $ids));
    }
    api_data(['marquees' => $n->rowCount()]);
});
