<?php
// Logique métier du parc matériel pour l'API mobile v1 (lecture, scan QR, création, modification
// avec historique d'affectation, suppression). Les règles reprennent celles de api/materiel.php.
// À inclure après config/db.php, middleware/auth.php et lib/api.php.

const MATERIEL_STATUTS = ['En attente', 'En service', 'En panne', 'Retiré'];

const MATERIEL_SELECT = "
    SELECT m.id, m.type, m.marque, m.modele, m.num_serie, m.date_achat, m.date_fin_garantie, m.statut,
           m.service_affecte, s.id AS service_id, m.utilisateur_assigne, u.id AS utilisateur_id,
           m.date_derniere_affect, m.date_creation, m.date_modification, m.date_modification::text AS dm_raw,
           (SELECT COUNT(*) FROM t_taches t WHERE t.materiel_id = m.id AND t.statut NOT IN ('Terminé', 'Annulé')) AS pannes_ouvertes
    FROM t_materiel m
    LEFT JOIN t_services s ON s.nom = m.service_affecte
    LEFT JOIN t_utilisateurs u ON u.nom = m.utilisateur_assigne
";

function materiel_libelle(array $m): string
{
    return trim(($m['type'] ?? '') . ' ' . ($m['marque'] ?? '') . ' ' . ($m['modele'] ?? ''))
        . (($m['num_serie'] ?? '') !== '' && $m['num_serie'] !== null ? ' (' . $m['num_serie'] . ')' : '');
}

function materiel_json(PDO $pdo, array $r): array
{
    return [
        'id' => (int) $r['id'],
        'libelle' => materiel_libelle($r),
        'type' => $r['type'], 'marque' => $r['marque'], 'modele' => $r['modele'], 'num_serie' => $r['num_serie'],
        'date_achat' => $r['date_achat'], 'date_fin_garantie' => $r['date_fin_garantie'], 'statut' => $r['statut'],
        'service' => $r['service_affecte'] === null || $r['service_affecte'] === '' ? null
            : ['id' => $r['service_id'] !== null ? (int) $r['service_id'] : null, 'nom' => $r['service_affecte']],
        'utilisateur' => $r['utilisateur_assigne'] === null || $r['utilisateur_assigne'] === '' ? null
            : ['id' => $r['utilisateur_id'] !== null ? (int) $r['utilisateur_id'] : null, 'nom' => $r['utilisateur_assigne']],
        'date_derniere_affect' => $r['date_derniere_affect'],
        'pannes_ouvertes' => (int) $r['pannes_ouvertes'],
        'created_at' => api_ts($pdo, $r['date_creation']),
        'updated_at' => api_ts($pdo, $r['date_modification']),
    ];
}

/** Clause de visibilité : tout le parc, ou seulement le matériel affecté à l'utilisateur. */
function materiel_visibilite(array $user, array &$params): string
{
    if (has_permission($user, 'materiel.voir_tous')) {
        return '';
    }
    if (has_permission($user, 'materiel.voir_siens')) {
        $params[] = $user['nom'];
        return 'm.utilisateur_assigne = ?';
    }
    api_fail(403, 'interdit', 'Vous n\'avez pas accès au parc matériel.');
}

function materiel_trouver(PDO $pdo, array $user, int $id): array
{
    $params = [$id];
    $vis = materiel_visibilite($user, $params);
    $st = $pdo->prepare(MATERIEL_SELECT . ' WHERE m.id = ?' . ($vis ? " AND $vis" : ''));
    $st->execute($params);
    $r = $st->fetch();
    if (!$r) {
        api_fail(404, 'introuvable', 'Matériel introuvable.');
    }
    return $r;
}

/** Contenu d'une étiquette QR (« PC Dell X1 | SN:ABC | ID:12 | Service:… ») ou numéro de série brut. */
function materiel_depuis_code(PDO $pdo, array $user, string $code): array
{
    $code = trim($code);
    $params = [];
    $vis = materiel_visibilite($user, $params);
    $essais = [];
    if (preg_match('/\bID:(\d+)/', $code, $m)) {
        $essais[] = ['m.id = ?', (int) $m[1]];
    }
    if (preg_match('/\bSN:([^|]+)/', $code, $m) && strcasecmp(trim($m[1]), 'N/A') !== 0) {
        $essais[] = ['m.num_serie = ?', trim($m[1])];
    }
    $essais[] = ['m.num_serie = ?', $code];
    foreach ($essais as [$cond, $val]) {
        $st = $pdo->prepare(MATERIEL_SELECT . " WHERE $cond" . ($vis ? " AND $vis" : '') . ' LIMIT 1');
        $st->execute(array_merge([$val], $params));
        if ($r = $st->fetch()) {
            return $r;
        }
    }
    api_fail(404, 'introuvable', 'Aucun matériel ne correspond à ce code.');
}

/** Valide et normalise les champs d'un matériel (après fusion avec l'existant en modification). */
function materiel_valider(PDO $pdo, array $in): array
{
    $d = [
        'type' => api_str($in, 'type', true, 100),
        'marque' => api_str($in, 'marque', false, 100),
        'modele' => api_str($in, 'modele', false, 100),
        'num_serie' => api_str($in, 'num_serie', false, 100),
        'date_achat' => api_date($in, 'date_achat'),
        'date_fin_garantie' => api_date($in, 'date_fin_garantie'),
        'statut' => api_enum($in, 'statut', MATERIEL_STATUTS),
        'date_derniere_affect' => api_date($in, 'date_derniere_affect'),
        'service_affecte' => null,
        'utilisateur_assigne' => null,
    ];
    if ($d['statut'] === null) {
        api_fail(422, 'champ_requis', 'Le champ « statut » est requis.', ['champ' => 'statut', 'valeurs' => MATERIEL_STATUTS]);
    }
    if (($sid = api_int($in, 'service_id')) !== null) {
        $st = $pdo->prepare("SELECT nom FROM t_services WHERE id = ?");
        $st->execute([$sid]);
        $d['service_affecte'] = $st->fetchColumn() ?: api_fail(422, 'champ_invalide', 'Service inconnu.', ['champ' => 'service_id']);
    }
    if (($uid = api_int($in, 'utilisateur_id')) !== null) {
        $st = $pdo->prepare("SELECT nom FROM t_utilisateurs WHERE id = ? AND actif AND NOT est_file_commune");
        $st->execute([$uid]);
        $d['utilisateur_assigne'] = $st->fetchColumn() ?: api_fail(422, 'champ_invalide', 'Utilisateur inconnu.', ['champ' => 'utilisateur_id']);
    }
    // Un service affecté impose une date d'affectation : par défaut, aujourd'hui.
    if ($d['service_affecte'] !== null && $d['date_derniere_affect'] === null) {
        $d['date_derniere_affect'] = date('Y-m-d');
    }
    return $d;
}

function materiel_numero_serie_libre(PDO $pdo, ?string $serie, ?int $exceptId): void
{
    if ($serie === null) {
        return;
    }
    $st = $pdo->prepare("SELECT id FROM t_materiel WHERE num_serie = ? AND id <> ?");
    $st->execute([$serie, $exceptId ?? 0]);
    if ($st->fetchColumn()) {
        api_fail(409, 'num_serie_existant', 'Ce numéro de série est déjà utilisé par un autre matériel.', ['champ' => 'num_serie']);
    }
}

function materiel_historiser(PDO $pdo, int $id, ?string $user, ?string $service, ?string $login, ?string $debut): void
{
    if ($user === null && $service === null) {
        return;
    }
    if ($debut) {
        $pdo->prepare("INSERT INTO t_materiel_affectations (materiel_id, utilisateur_assigne, service_affecte, date_debut, changed_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$id, $user, $service, $debut, $login]);
    } else {
        $pdo->prepare("INSERT INTO t_materiel_affectations (materiel_id, utilisateur_assigne, service_affecte, changed_by) VALUES (?, ?, ?, ?)")
            ->execute([$id, $user, $service, $login]);
    }
}

function materiel_creer(PDO $pdo, array $user, array $in): int
{
    $d = materiel_valider($pdo, $in);
    materiel_numero_serie_libre($pdo, $d['num_serie'], null);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("
            INSERT INTO t_materiel (type, marque, modele, num_serie, date_achat, date_fin_garantie, statut, service_affecte,
                utilisateur_assigne, date_derniere_affect, created_by_login, updated_by_login)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
        ");
        $st->execute([$d['type'], $d['marque'], $d['modele'], $d['num_serie'], $d['date_achat'], $d['date_fin_garantie'], $d['statut'],
            $d['service_affecte'], $d['utilisateur_assigne'], $d['date_derniere_affect'], $user['login'], $user['login']]);
        $id = (int) $st->fetchColumn();
        materiel_historiser($pdo, $id, $d['utilisateur_assigne'], $d['service_affecte'], $user['login'], $d['date_derniere_affect']);
        $pdo->commit();
        return $id;
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23505') {
            api_fail(409, 'num_serie_existant', 'Ce numéro de série est déjà utilisé par un autre matériel.', ['champ' => 'num_serie']);
        }
        throw $e;
    }
}

/** Modification partielle : seuls les champs présents dans $in changent. */
function materiel_modifier(PDO $pdo, array $user, int $id, array $in): void
{
    $st = $pdo->prepare(MATERIEL_SELECT . ' WHERE m.id = ?');
    $st->execute([$id]);
    $actuel = $st->fetch();
    if (!$actuel) {
        api_fail(404, 'introuvable', 'Matériel introuvable.');
    }
    $base = [
        'type' => $actuel['type'], 'marque' => $actuel['marque'], 'modele' => $actuel['modele'], 'num_serie' => $actuel['num_serie'],
        'date_achat' => $actuel['date_achat'], 'date_fin_garantie' => $actuel['date_fin_garantie'], 'statut' => $actuel['statut'], 'date_derniere_affect' => $actuel['date_derniere_affect'],
        'service_id' => $actuel['service_id'], 'utilisateur_id' => $actuel['utilisateur_id'],
    ];
    foreach (array_keys($base) as $cle) {
        if (array_key_exists($cle, $in)) {
            $base[$cle] = $in[$cle];
        }
    }
    // Un service/utilisateur existant mais sans correspondance en table (nom libre historique) est conservé tel quel.
    $d = materiel_valider($pdo, $base);
    if ($d['service_affecte'] === null && $base['service_id'] === null && !array_key_exists('service_id', $in)) {
        $d['service_affecte'] = $actuel['service_affecte'] ?: null;
    }
    if ($d['utilisateur_assigne'] === null && $base['utilisateur_id'] === null && !array_key_exists('utilisateur_id', $in)) {
        $d['utilisateur_assigne'] = $actuel['utilisateur_assigne'] ?: null;
    }
    materiel_numero_serie_libre($pdo, $d['num_serie'], $id);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE t_materiel SET type = ?, marque = ?, modele = ?, num_serie = ?, date_achat = ?, date_fin_garantie = ?, statut = ?,
                service_affecte = ?, utilisateur_assigne = ?, date_derniere_affect = ?, updated_by_login = ?
            WHERE id = ?
        ")->execute([$d['type'], $d['marque'], $d['modele'], $d['num_serie'], $d['date_achat'], $d['date_fin_garantie'], $d['statut'],
            $d['service_affecte'], $d['utilisateur_assigne'], $d['date_derniere_affect'], $user['login'], $id]);
        if (($actuel['utilisateur_assigne'] ?: null) !== $d['utilisateur_assigne'] || ($actuel['service_affecte'] ?: null) !== $d['service_affecte']) {
            $pdo->prepare("UPDATE t_materiel_affectations SET date_fin = NOW() WHERE materiel_id = ? AND date_fin IS NULL")->execute([$id]);
            materiel_historiser($pdo, $id, $d['utilisateur_assigne'], $d['service_affecte'], $user['login'], $d['date_derniere_affect']);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23505') {
            api_fail(409, 'num_serie_existant', 'Ce numéro de série est déjà utilisé par un autre matériel.', ['champ' => 'num_serie']);
        }
        throw $e;
    }
}
