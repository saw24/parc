<?php
// Renouvellement du matériel : détection des candidats (âge, pannes répétées) et cycle des propositions.
// À inclure après config/db.php, middleware/auth.php, lib/api.php et lib/materiel.php.

const RENOUV_DUREE_VIE_DEFAUT = 60;      // mois, si le type n'a pas de durée de vie propre
const RENOUV_PANNES_SEUIL = 3;           // pannes sur la fenêtre ci-dessous
const RENOUV_PANNES_FENETRE_MOIS = 12;
const RENOUV_STATUTS = ['Proposé', 'Validé', 'Refusé', 'Commandé', 'Remplacé'];
// Transitions autorisées par la décision.
const RENOUV_TRANSITIONS = ['Proposé' => ['Validé', 'Refusé'], 'Validé' => ['Commandé', 'Refusé'], 'Commandé' => ['Remplacé']];

/** Évalue un matériel : liste des critères déclenchés et score (plus c'est haut, plus c'est urgent). */
function renouv_evaluer(array $m): array
{
    $criteres = []; $score = 0;
    $duree = (int) ($m['duree_vie_mois'] ?? 0) ?: RENOUV_DUREE_VIE_DEFAUT;
    if (!empty($m['date_achat'])) {
        $age = (int) $m['age_mois'];
        if ($age >= $duree) {
            $criteres[] = ['code' => 'fin_de_vie', 'texte' => sprintf('Fin de vie atteinte : %s d\'âge pour %s prévus', renouv_duree($age), renouv_duree($duree))];
            $score += 50 + min(30, intdiv($age - $duree, 6) * 5);
        } elseif ($age >= (int) floor($duree * 0.85)) {
            $criteres[] = ['code' => 'proche_fin_de_vie', 'texte' => sprintf('Approche de la fin de vie : %s sur %s prévus', renouv_duree($age), renouv_duree($duree))];
            $score += 25;
        }
    }
    $pannes = (int) $m['pannes_recentes'];
    if ($pannes >= RENOUV_PANNES_SEUIL) {
        $criteres[] = ['code' => 'pannes_repetees', 'texte' => "$pannes pannes sur les " . RENOUV_PANNES_FENETRE_MOIS . ' derniers mois'];
        $score += 20 + min(30, ($pannes - RENOUV_PANNES_SEUIL) * 10);
    }
    if ($m['statut'] === 'En panne' && (int) $m['pannes_ouvertes'] > 0 && $criteres) {
        $criteres[] = ['code' => 'en_panne', 'texte' => 'Actuellement en panne'];
        $score += 10;
    }
    return ['criteres' => $criteres, 'score' => $score, 'duree_vie_mois' => $duree];
}

function renouv_duree(int $mois): string
{
    $a = intdiv($mois, 12); $r = $mois % 12;
    return $a > 0 ? $a . ' an' . ($a > 1 ? 's' : '') . ($r ? " $r mois" : '') : "$r mois";
}

const RENOUV_BASE_MATERIEL = "
    SELECT m.id, m.type, m.marque, m.modele, m.num_serie, m.date_achat, m.statut, m.service_affecte, m.utilisateur_assigne,
           tm.duree_vie_mois,
           CASE WHEN m.date_achat IS NULL THEN NULL
                ELSE (EXTRACT(YEAR FROM age(CURRENT_DATE, m.date_achat)) * 12 + EXTRACT(MONTH FROM age(CURRENT_DATE, m.date_achat)))::int END AS age_mois,
           (SELECT COUNT(*) FROM t_taches t WHERE t.materiel_id = m.id
              AND t.date_creation >= CURRENT_TIMESTAMP - (? || ' months')::interval) AS pannes_recentes,
           (SELECT COUNT(*) FROM t_taches t WHERE t.materiel_id = m.id AND t.statut NOT IN ('Terminé', 'Annulé')) AS pannes_ouvertes
    FROM t_materiel m
    LEFT JOIN t_type_materiel tm ON tm.type = m.type
";

/** Matériel à renouveler selon les règles, hors matériel retiré ou déjà visé par une proposition active. */
function renouv_suggestions(PDO $pdo): array
{
    $st = $pdo->prepare(RENOUV_BASE_MATERIEL . "
        WHERE m.statut <> 'Retiré'
          AND NOT EXISTS (SELECT 1 FROM t_renouvellements r WHERE r.materiel_id = m.id AND r.statut IN ('Proposé', 'Validé', 'Commandé'))");
    $st->execute([RENOUV_PANNES_FENETRE_MOIS]);
    $out = [];
    foreach ($st->fetchAll() as $m) {
        $e = renouv_evaluer($m);
        if (!$e['criteres']) { continue; }
        $out[] = [
            'materiel' => ['id' => (int) $m['id'], 'libelle' => materiel_libelle($m), 'type' => $m['type'], 'date_achat' => $m['date_achat'],
                'statut' => $m['statut'], 'service' => $m['service_affecte'], 'utilisateur' => $m['utilisateur_assigne']],
            'age_mois' => $m['age_mois'] !== null ? (int) $m['age_mois'] : null, 'duree_vie_mois' => $e['duree_vie_mois'],
            'pannes_recentes' => (int) $m['pannes_recentes'], 'criteres' => $e['criteres'], 'score' => $e['score'],
        ];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

const RENOUV_SELECT = "
    SELECT r.*, m.type, m.marque, m.modele, m.num_serie, m.service_affecte, m.utilisateur_assigne,
           up.nom AS propose_par_nom, ud.nom AS decide_par_nom
    FROM t_renouvellements r
    JOIN t_materiel m ON m.id = r.materiel_id
    LEFT JOIN t_utilisateurs up ON up.id = r.propose_par
    LEFT JOIN t_utilisateurs ud ON ud.id = r.decide_par
";

function renouv_json(PDO $pdo, array $r): array
{
    return [
        'id' => (int) $r['id'],
        'materiel' => ['id' => (int) $r['materiel_id'], 'libelle' => materiel_libelle($r), 'type' => $r['type'],
            'service' => $r['service_affecte'], 'utilisateur' => $r['utilisateur_assigne']],
        'origine' => $r['origine'], 'statut' => $r['statut'], 'motif' => $r['motif'],
        'criteres' => json_decode((string) $r['criteres'], true) ?: [],
        'cout_estime' => $r['cout_estime'] !== null ? (float) $r['cout_estime'] : null,
        'propose_par' => $r['propose_par_nom'], 'decide_par' => $r['decide_par_nom'],
        'decide_at' => api_ts($pdo, $r['decide_at']), 'commentaire' => $r['commentaire'],
        'created_at' => api_ts($pdo, $r['created_at']), 'updated_at' => api_ts($pdo, $r['updated_at']),
    ];
}

function renouv_trouver(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(RENOUV_SELECT . ' WHERE r.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { api_fail(404, 'introuvable', 'Proposition introuvable.'); }
    return $r;
}

/** Crée une proposition ; les critères détectés sont figés pour garder la trace du raisonnement. */
function renouv_proposer(PDO $pdo, array $user, int $materielId, ?string $motif, ?float $cout): int
{
    $st = $pdo->prepare(RENOUV_BASE_MATERIEL . ' WHERE m.id = ?');
    $st->execute([RENOUV_PANNES_FENETRE_MOIS, $materielId]);
    $m = $st->fetch();
    if (!$m) { api_fail(404, 'introuvable', 'Matériel introuvable.'); }
    if ($m['statut'] === 'Retiré') { api_fail(409, 'materiel_retire', 'Ce matériel est déjà retiré.'); }
    $e = renouv_evaluer($m);
    $auto = $e['criteres'] !== [];
    if (!$auto && ($motif === null || trim($motif) === '')) {
        api_fail(422, 'champ_requis', 'Aucun critère automatique : indiquez le motif du renouvellement.', ['champ' => 'motif']);
    }
    try {
        $ins = $pdo->prepare("INSERT INTO t_renouvellements (materiel_id, origine, criteres, motif, cout_estime, propose_par)
                              VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
        $ins->execute([$materielId, $motif ? 'manuelle' : 'auto', json_encode($e['criteres'], JSON_UNESCAPED_UNICODE), $motif, $cout, $user['id']]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23505') { api_fail(409, 'deja_propose', 'Ce matériel a déjà une proposition de renouvellement en cours.'); }
        throw $ex;
    }
    return (int) $ins->fetchColumn();
}

/** Décision : applique le cycle de vie ; « Remplacé » retire l'ancien matériel du service. */
function renouv_decider(PDO $pdo, array $user, int $id, string $decision, ?string $commentaire): void
{
    $r = renouv_trouver($pdo, $id);
    if (!in_array($decision, RENOUV_TRANSITIONS[$r['statut']] ?? [], true)) {
        api_fail(409, 'transition_interdite', "Impossible de passer de « {$r['statut']} » à « $decision ».");
    }
    if ($decision === 'Refusé' && ($commentaire === null || trim($commentaire) === '')) {
        api_fail(422, 'champ_requis', 'Indiquez la raison du refus.', ['champ' => 'commentaire']);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE t_renouvellements SET statut = ?, decide_par = ?, decide_at = CURRENT_TIMESTAMP,
                       commentaire = COALESCE(?, commentaire), updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$decision, $user['id'], $commentaire, $id]);
        if ($decision === 'Remplacé') {
            $pdo->prepare("UPDATE t_materiel SET statut = 'Retiré', date_modification = CURRENT_TIMESTAMP WHERE id = ?")->execute([$r['materiel_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
