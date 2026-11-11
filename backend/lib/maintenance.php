<?php
// Maintenance préventive : plans récurrents → tâches générées automatiquement avant l'échéance.
// À inclure après config/db.php, middleware/auth.php, lib/api.php, lib/materiel.php et lib/taches.php.

const MAINT_UNITES = ['jour', 'semaine', 'mois', 'an'];

/** Date suivante d'après la périodicité (les mois sont ajoutés calendairement : 31/01 + 1 mois = 28/02). */
function maint_suivante(string $date, int $valeur, string $unite): string
{
    $d = new DateTimeImmutable($date);
    if ($unite === 'mois' || $unite === 'an') {
        $mois = $unite === 'an' ? 12 * $valeur : $valeur;
        $jour = (int) $d->format('j');
        $x = $d->modify('first day of this month')->modify("+$mois months");
        return $x->modify('+' . (min($jour, (int) $x->format('t')) - 1) . ' days')->format('Y-m-d');
    }
    return $d->modify('+' . ($unite === 'semaine' ? 7 * $valeur : $valeur) . ' days')->format('Y-m-d');
}

const MAINT_SELECT = "
    SELECT p.*, m.type AS m_type, m.marque AS m_marque, m.modele AS m_modele, m.num_serie AS m_serie,
           u.nom AS assigne_nom,
           (SELECT COUNT(*) FROM t_materiel x WHERE x.statut <> 'Retiré'
              AND ((p.materiel_id IS NOT NULL AND x.id = p.materiel_id) OR (p.type_materiel IS NOT NULL AND x.type = p.type_materiel))) AS nb_materiels
    FROM t_plans_maintenance p
    LEFT JOIN t_materiel m ON m.id = p.materiel_id
    LEFT JOIN t_utilisateurs u ON u.id = p.assigne_id
";

function maint_json(array $p): array
{
    return [
        'id' => (int) $p['id'], 'titre' => $p['titre'], 'description' => $p['description'],
        'cible' => $p['materiel_id'] !== null
            ? ['materiel' => ['id' => (int) $p['materiel_id'], 'libelle' => materiel_libelle(['type' => $p['m_type'], 'marque' => $p['m_marque'], 'modele' => $p['m_modele'], 'num_serie' => $p['m_serie']])], 'type' => null]
            : ['materiel' => null, 'type' => $p['type_materiel']],
        'nb_materiels' => (int) $p['nb_materiels'],
        'periodicite' => ['valeur' => (int) $p['periodicite_valeur'], 'unite' => $p['periodicite_unite']],
        'prochaine_echeance' => $p['prochaine_echeance'], 'anticipation_jours' => (int) $p['anticipation_jours'],
        'priorite' => $p['priorite'], 'assigne' => $p['assigne_id'] !== null ? ['id' => (int) $p['assigne_id'], 'nom' => $p['assigne_nom']] : null,
        'checklist' => $p['checklist'], 'actif' => (bool) $p['actif'], 'derniere_generation' => $p['derniere_generation'],
    ];
}

function maint_trouver(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(MAINT_SELECT . ' WHERE p.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: api_fail(404, 'introuvable', 'Plan introuvable.');
}

/** Valide un plan (corps complet) → colonnes. */
function maint_valider(PDO $pdo, array $in): array
{
    $d = [
        'titre' => api_str($in, 'titre', true, 200),
        'description' => api_str($in, 'description', false, 2000),
        'materiel_id' => api_int($in, 'materiel_id'),
        'type_materiel' => api_str($in, 'type_materiel', false, 100),
        'periodicite_valeur' => api_int($in, 'periodicite_valeur', true),
        'periodicite_unite' => api_enum($in, 'periodicite_unite', MAINT_UNITES),
        'prochaine_echeance' => api_date($in, 'prochaine_echeance'),
        'anticipation_jours' => api_int($in, 'anticipation_jours') ?? 7,
        'priorite' => api_enum($in, 'priorite', ['Basse', 'Moyenne', 'Haute'], 'Moyenne'),
        'assigne_id' => api_int($in, 'assigne_id'),
        'checklist' => api_str($in, 'checklist', false, 4000),
        'actif' => (bool) ($in['actif'] ?? true),
    ];
    if ($d['periodicite_unite'] === null) { api_fail(422, 'champ_requis', 'Le champ « periodicite_unite » est requis.', ['champ' => 'periodicite_unite', 'valeurs' => MAINT_UNITES]); }
    if ($d['prochaine_echeance'] === null) { api_fail(422, 'champ_requis', 'Le champ « prochaine_echeance » est requis.', ['champ' => 'prochaine_echeance']); }
    if ($d['periodicite_valeur'] < 1 || $d['periodicite_valeur'] > 120) { api_fail(422, 'champ_invalide', 'Périodicité entre 1 et 120.', ['champ' => 'periodicite_valeur']); }
    if ($d['anticipation_jours'] < 0 || $d['anticipation_jours'] > 120) { api_fail(422, 'champ_invalide', 'Anticipation entre 0 et 120 jours.', ['champ' => 'anticipation_jours']); }
    if (($d['materiel_id'] === null) === ($d['type_materiel'] === null || $d['type_materiel'] === '')) {
        api_fail(422, 'cible_invalide', 'Indiquez soit un matériel précis, soit un type de matériel (pas les deux).');
    }
    if ($d['type_materiel'] === '') { $d['type_materiel'] = null; }
    if ($d['materiel_id'] !== null) {
        $st = $pdo->prepare('SELECT 1 FROM t_materiel WHERE id = ?'); $st->execute([$d['materiel_id']]);
        if (!$st->fetchColumn()) { api_fail(422, 'champ_invalide', 'Matériel inconnu.', ['champ' => 'materiel_id']); }
    } else {
        $st = $pdo->prepare('SELECT 1 FROM t_type_materiel WHERE type = ?'); $st->execute([$d['type_materiel']]);
        if (!$st->fetchColumn()) { api_fail(422, 'champ_invalide', 'Type de matériel inconnu.', ['champ' => 'type_materiel']); }
    }
    if ($d['assigne_id'] !== null) {
        $st = $pdo->prepare('SELECT 1 FROM t_utilisateurs WHERE id = ? AND actif AND NOT est_file_commune'); $st->execute([$d['assigne_id']]);
        if (!$st->fetchColumn()) { api_fail(422, 'champ_invalide', 'Agent inconnu.', ['champ' => 'assigne_id']); }
    }
    return $d;
}

function maint_creer(PDO $pdo, array $user, array $in): int
{
    $d = maint_valider($pdo, $in);
    $st = $pdo->prepare("INSERT INTO t_plans_maintenance (titre, description, materiel_id, type_materiel, periodicite_valeur, periodicite_unite,
            prochaine_echeance, anticipation_jours, priorite, assigne_id, checklist, actif, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
    $st->execute([$d['titre'], $d['description'], $d['materiel_id'], $d['type_materiel'], $d['periodicite_valeur'], $d['periodicite_unite'],
        $d['prochaine_echeance'], $d['anticipation_jours'], $d['priorite'], $d['assigne_id'], $d['checklist'], $d['actif'] ? 'true' : 'false', $user['id']]);
    return (int) $st->fetchColumn();
}

function maint_modifier(PDO $pdo, int $id, array $in): void
{
    $p = maint_trouver($pdo, $id);
    $base = [];
    foreach (['titre', 'description', 'materiel_id', 'type_materiel', 'periodicite_valeur', 'periodicite_unite', 'prochaine_echeance',
              'anticipation_jours', 'priorite', 'assigne_id', 'checklist', 'actif'] as $k) { $base[$k] = $p[$k]; }
    $base['actif'] = (bool) $p['actif'];
    // Changer de cible : fournir l'une efface l'autre.
    if (array_key_exists('materiel_id', $in) && $in['materiel_id'] !== null) { $base['type_materiel'] = null; }
    if (array_key_exists('type_materiel', $in) && $in['type_materiel'] !== null) { $base['materiel_id'] = null; }
    $d = maint_valider($pdo, array_merge($base, $in));
    $pdo->prepare("UPDATE t_plans_maintenance SET titre = ?, description = ?, materiel_id = ?, type_materiel = ?, periodicite_valeur = ?,
            periodicite_unite = ?, prochaine_echeance = ?, anticipation_jours = ?, priorite = ?, assigne_id = ?, checklist = ?, actif = ?,
            updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$d['titre'], $d['description'], $d['materiel_id'], $d['type_materiel'], $d['periodicite_valeur'], $d['periodicite_unite'],
            $d['prochaine_echeance'], $d['anticipation_jours'], $d['priorite'], $d['assigne_id'], $d['checklist'], $d['actif'] ? 'true' : 'false', $id]);
}

/**
 * Génère les tâches des plans dont l'échéance entre dans la fenêtre d'anticipation, puis avance l'échéance
 * (une seule tâche par matériel même si plusieurs périodes ont été manquées). Rejouable sans doublon.
 * @return array{plans:int, taches:int}
 */
function maint_generer(PDO $pdo, ?int $planId = null, ?string $aujourdhui = null): array
{
    $aujourdhui ??= date('Y-m-d');
    $file = tache_file_commune($pdo) ?: api_fail(500, 'file_introuvable', 'File « Service IT » introuvable.');
    $acteurSt = $pdo->prepare('SELECT id, nom, login FROM t_utilisateurs WHERE id = ?');
    $acteurSt->execute([$file['id']]);
    $acteur = $acteurSt->fetch();

    $q = $pdo->prepare("SELECT * FROM t_plans_maintenance WHERE actif AND prochaine_echeance - anticipation_jours <= ?::date" . ($planId ? ' AND id = ?' : '') . ' ORDER BY id');
    $q->execute($planId ? [$aujourdhui, $planId] : [$aujourdhui]);
    $nbPlans = 0; $nbTaches = 0;
    foreach ($q->fetchAll() as $p) {
        $cibles = $pdo->prepare("SELECT id, service_affecte FROM t_materiel WHERE statut <> 'Retiré' AND " . ($p['materiel_id'] !== null ? 'id = ?' : 'type = ?'));
        $cibles->execute([$p['materiel_id'] ?? $p['type_materiel']]);
        $pdo->beginTransaction();
        try {
            foreach ($cibles->fetchAll() as $m) {
                $dejaFait = $pdo->prepare('SELECT 1 FROM t_plan_occurrences WHERE plan_id = ? AND materiel_id = ? AND echeance = ?');
                $dejaFait->execute([$p['id'], $m['id'], $p['prochaine_echeance']]);
                if ($dejaFait->fetchColumn()) { continue; }
                $r = tache_declarer($pdo, $acteur + ['login' => $acteur['login']], [
                    'titre' => '[Préventif] ' . $p['titre'], 'priorite' => $p['priorite'], 'materiel_id' => (int) $m['id'],
                    'description' => trim(($p['description'] ?? '') . ($p['checklist'] ? "\n\nPoints à vérifier :\n" . $p['checklist'] : '')) ?: null,
                    'motif_cause' => 'Maintenance préventive',
                    'service_nom' => $m['service_affecte'], 'assigne_id' => $p['assigne_id'] !== null ? (int) $p['assigne_id'] : null,
                    'date_limite' => $p['prochaine_echeance'], 'source' => 'systeme']);
                $pdo->prepare('INSERT INTO t_plan_occurrences (plan_id, materiel_id, echeance, tache_id) VALUES (?, ?, ?, ?)')
                    ->execute([$p['id'], $m['id'], $p['prochaine_echeance'], $r['id']]);
                $nbTaches++;
            }
            $prochaine = maint_suivante($p['prochaine_echeance'], (int) $p['periodicite_valeur'], $p['periodicite_unite']);
            while ($prochaine <= $aujourdhui) { $prochaine = maint_suivante($prochaine, (int) $p['periodicite_valeur'], $p['periodicite_unite']); }
            $pdo->prepare('UPDATE t_plans_maintenance SET prochaine_echeance = ?, derniere_generation = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$prochaine, $p['id']]);
            $pdo->commit();
            $nbPlans++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
    return ['plans' => $nbPlans, 'taches' => $nbTaches];
}
