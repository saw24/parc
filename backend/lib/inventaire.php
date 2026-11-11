<?php
// Campagnes d'inventaire : périmètre figé, pointage (présent / déplacé / manquant), clôture.
// À inclure après config/db.php, middleware/auth.php, lib/api.php et lib/materiel.php.

const INV_STATUTS_POINTAGE = ['Présent', 'Déplacé', 'Manquant'];

function inv_trouver(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT i.*, uc.nom AS cree_par_nom FROM t_inventaires i LEFT JOIN t_utilisateurs uc ON uc.id = i.created_by WHERE i.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: api_fail(404, 'introuvable', 'Inventaire introuvable.');
}

function inv_compteurs(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT statut, hors_perimetre, COUNT(*) AS n FROM t_inventaire_lignes WHERE inventaire_id = ? GROUP BY 1, 2");
    $st->execute([$id]);
    $c = ['attendus' => 0, 'a_pointer' => 0, 'presents' => 0, 'deplaces' => 0, 'manquants' => 0, 'hors_perimetre' => 0, 'non_references' => 0];
    foreach ($st->fetchAll() as $r) {
        $n = (int) $r['n'];
        if ($r['statut'] === 'Non référencé') { $c['non_references'] += $n; continue; }
        if ($r['hors_perimetre']) { $c['hors_perimetre'] += $n; continue; }
        $c['attendus'] += $n;
        $c[['À pointer' => 'a_pointer', 'Présent' => 'presents', 'Déplacé' => 'deplaces', 'Manquant' => 'manquants'][$r['statut']]] += $n;
    }
    $c['pointes'] = $c['attendus'] - $c['a_pointer'];
    return $c;
}

function inv_json(PDO $pdo, array $i): array
{
    return [
        'id' => (int) $i['id'], 'titre' => $i['titre'], 'description' => $i['description'],
        'perimetre' => ['service' => $i['service_nom'], 'type' => $i['type_materiel']],
        'date_limite' => $i['date_limite'], 'statut' => $i['statut'], 'cree_par' => $i['cree_par_nom'] ?? null,
        'created_at' => api_ts($pdo, $i['created_at']), 'clos_at' => api_ts($pdo, $i['clos_at']),
        'compteurs' => inv_compteurs($pdo, (int) $i['id']),
    ];
}

function inv_ligne_json(array $l): array
{
    return [
        'id' => (int) $l['id'], 'statut' => $l['statut'], 'hors_perimetre' => (bool) $l['hors_perimetre'], 'observation' => $l['observation'],
        'code_scanne' => $l['code_scanne'], 'pointe_par' => $l['pointe_par_nom'], 'pointe_at' => $l['pointe_at'],
        'materiel' => $l['materiel_id'] !== null ? ['id' => (int) $l['materiel_id'], 'libelle' => materiel_libelle($l), 'num_serie' => $l['num_serie'],
            'service' => $l['service_affecte'], 'utilisateur' => $l['utilisateur_assigne']] : null,
    ];
}

const INV_LIGNES_SELECT = "
    SELECT l.*, m.type, m.marque, m.modele, m.num_serie, m.service_affecte, m.utilisateur_assigne, up.nom AS pointe_par_nom
    FROM t_inventaire_lignes l
    LEFT JOIN t_materiel m ON m.id = l.materiel_id
    LEFT JOIN t_utilisateurs up ON up.id = l.pointe_par
";

/** Crée la campagne et fige la liste du matériel attendu (hors « Retiré »). */
function inv_creer(PDO $pdo, array $user, array $in): int
{
    $titre = api_str($in, 'titre', true, 200);
    $service = null;
    if (($sid = api_int($in, 'service_id')) !== null) {
        $st = $pdo->prepare('SELECT nom FROM t_services WHERE id = ?'); $st->execute([$sid]);
        $service = $st->fetchColumn() ?: api_fail(422, 'champ_invalide', 'Service inconnu.', ['champ' => 'service_id']);
    }
    $type = api_str($in, 'type_materiel', false, 100) ?: null;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO t_inventaires (titre, description, service_nom, type_materiel, date_limite, created_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
        $st->execute([$titre, api_str($in, 'description', false, 2000), $service, $type, api_date($in, 'date_limite'), $user['id']]);
        $id = (int) $st->fetchColumn();
        $pdo->prepare("INSERT INTO t_inventaire_lignes (inventaire_id, materiel_id)
            SELECT ?, m.id FROM t_materiel m WHERE m.statut <> 'Retiré' AND (?::text IS NULL OR m.service_affecte = ?) AND (?::text IS NULL OR m.type = ?)")
            ->execute([$id, $service, $service, $type, $type]);
        if ((int) $pdo->query("SELECT COUNT(*) FROM t_inventaire_lignes WHERE inventaire_id = $id")->fetchColumn() === 0) {
            api_fail(422, 'perimetre_vide', 'Aucun matériel actif ne correspond à ce périmètre.');
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** Retrouve un matériel à partir d'un code scanné (« … | SN:xxx | ID:12 » ou numéro de série brut), sans filtre de visibilité. */
function inv_resoudre_code(PDO $pdo, string $code): ?int
{
    $code = trim($code);
    $essais = [];
    if (preg_match('/\bID:(\d+)/', $code, $m)) { $essais[] = ['id = ?', (int) $m[1]]; }
    if (preg_match('/\bSN:([^|]+)/', $code, $m) && strcasecmp(trim($m[1]), 'N/A') !== 0) { $essais[] = ['num_serie = ?', trim($m[1])]; }
    $essais[] = ['num_serie = ?', $code];
    foreach ($essais as [$cond, $val]) {
        $st = $pdo->prepare("SELECT id FROM t_materiel WHERE $cond LIMIT 1");
        $st->execute([$val]);
        if ($id = $st->fetchColumn()) { return (int) $id; }
    }
    return null;
}

/**
 * Pointe un matériel (par id ou par code scanné). Rejouable : repointer remplace le pointage précédent.
 * Un matériel absent de la liste est ajouté « hors périmètre » ; un code inconnu est gardé « non référencé ».
 */
function inv_pointer(PDO $pdo, array $user, int $invId, array $in): array
{
    $inv = inv_trouver($pdo, $invId);
    if ($inv['statut'] !== 'Ouvert') { api_fail(409, 'inventaire_clos', 'Cet inventaire est clos.'); }
    $statut = api_enum($in, 'statut', INV_STATUTS_POINTAGE, 'Présent');
    $obs = api_str($in, 'observation', false, 1000);
    if ($statut === 'Déplacé' && ($obs === null || trim($obs) === '')) {
        api_fail(422, 'champ_requis', 'Indiquez où se trouve le matériel déplacé.', ['champ' => 'observation']);
    }
    $materielId = api_int($in, 'materiel_id');
    $code = api_str($in, 'code', false, 500);
    if ($materielId === null && $code === null) { api_fail(422, 'champ_requis', 'Fournissez « materiel_id » ou « code ».'); }
    if ($materielId === null) { $materielId = inv_resoudre_code($pdo, $code); }

    if ($materielId === null) {   // code inconnu : on le garde pour traitement manuel
        $pdo->prepare("INSERT INTO t_inventaire_lignes (inventaire_id, code_scanne, statut, observation, pointe_par, pointe_at, hors_perimetre)
                       VALUES (?, ?, 'Non référencé', ?, ?, NOW(), TRUE)
                       ON CONFLICT (inventaire_id, code_scanne) WHERE materiel_id IS NULL DO UPDATE SET observation = EXCLUDED.observation, pointe_par = EXCLUDED.pointe_par, pointe_at = NOW()")
            ->execute([$invId, $code, $obs, $user['id']]);
        $st = $pdo->prepare(INV_LIGNES_SELECT . ' WHERE l.inventaire_id = ? AND l.materiel_id IS NULL AND l.code_scanne = ?');
        $st->execute([$invId, $code]);
        return inv_ligne_json($st->fetch());
    }
    $st = $pdo->prepare('SELECT 1 FROM t_materiel WHERE id = ?'); $st->execute([$materielId]);
    if (!$st->fetchColumn()) { api_fail(404, 'introuvable', 'Matériel introuvable.'); }
    $pdo->prepare("INSERT INTO t_inventaire_lignes (inventaire_id, materiel_id, statut, observation, pointe_par, pointe_at, hors_perimetre)
                   VALUES (?, ?, ?, ?, ?, NOW(), TRUE)
                   ON CONFLICT (inventaire_id, materiel_id) WHERE materiel_id IS NOT NULL
                   DO UPDATE SET statut = EXCLUDED.statut, observation = EXCLUDED.observation, pointe_par = EXCLUDED.pointe_par, pointe_at = NOW()")
        ->execute([$invId, $materielId, $statut, $obs, $user['id']]);
    $st = $pdo->prepare(INV_LIGNES_SELECT . ' WHERE l.inventaire_id = ? AND l.materiel_id = ?');
    $st->execute([$invId, $materielId]);
    return inv_ligne_json($st->fetch());
}

/** Clôture : les matériels non pointés deviennent « Manquant » (sauf si $marquerManquants est faux). */
function inv_cloturer(PDO $pdo, array $user, int $invId, bool $marquerManquants): int
{
    $inv = inv_trouver($pdo, $invId);
    if ($inv['statut'] !== 'Ouvert') { api_fail(409, 'inventaire_clos', 'Cet inventaire est déjà clos.'); }
    $pdo->beginTransaction();
    try {
        $n = 0;
        if ($marquerManquants) {
            $st = $pdo->prepare("UPDATE t_inventaire_lignes SET statut = 'Manquant', pointe_par = ?, pointe_at = NOW(), observation = COALESCE(observation, 'Non retrouvé à la clôture')
                                 WHERE inventaire_id = ? AND statut = 'À pointer'");
            $st->execute([$user['id'], $invId]);
            $n = $st->rowCount();
        }
        $pdo->prepare("UPDATE t_inventaires SET statut = 'Clos', clos_par = ?, clos_at = NOW() WHERE id = ?")->execute([$user['id'], $invId]);
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
