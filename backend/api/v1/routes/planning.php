<?php
// Planning : échéances de pannes et de maintenance, relances, plans à venir et fins de garantie sur une période.
// GET planning?du=AAAA-MM-JJ&au=AAAA-MM-JJ[&assigne=moi|<id>]  (période limitée à 62 jours)

route('GET', 'planning', function (array $c): void {
    $pdo = $c['pdo']; $u = $c['user']; $q = $c['query'];
    $du = api_date($q, 'du') ?? date('Y-m-d');
    $au = api_date($q, 'au') ?? date('Y-m-d', strtotime("$du +30 days"));
    if ($au < $du) { api_fail(422, 'champ_invalide', 'La fin de période précède le début.', ['champ' => 'au']); }
    if ((strtotime($au) - strtotime($du)) / 86400 > 62) { api_fail(422, 'periode_trop_longue', 'La période ne peut pas dépasser 62 jours.'); }
    $aujourdhui = date('Y-m-d');
    $evenements = [];

    // Pannes et tâches préventives ouvertes (échéance), relances d'attente — selon la visibilité de l'utilisateur.
    $params = [];
    $vis = v1_tache_visibilite($u, $params);
    $filtre = $vis ? "AND $vis" : '';
    $params2 = [];
    if (($q['assigne'] ?? '') === 'moi') { $filtre .= ' AND t.assigne_id = ?'; $params2[] = $u['id']; }
    elseif (ctype_digit((string) ($q['assigne'] ?? ''))) { $filtre .= ' AND t.assigne_id = ?'; $params2[] = (int) $q['assigne']; }
    $st = $pdo->prepare("
        SELECT t.id, t.reference, t.titre, t.priorite, t.statut, t.source, t.date_limite, t.date_relance, t.assigne_nom_aff,
               t.assigne_id, t.materiel_concerne
        FROM (SELECT x.*, u.nom AS assigne_nom_aff FROM t_taches x LEFT JOIN t_utilisateurs u ON u.id = x.assigne_id) t
        WHERE t.statut NOT IN ('Terminé', 'Annulé')
          AND ((t.date_limite BETWEEN ? AND ?) OR (t.statut = 'En attente' AND t.date_relance BETWEEN ? AND ?))
          $filtre ORDER BY t.date_limite, t.id LIMIT 500");
    $st->execute(array_merge([$du, $au, $du, $au], $params, $params2));
    foreach ($st->fetchAll() as $t) {
        $base = ['tache' => ['id' => (int) $t['id'], 'reference' => $t['reference'], 'statut' => $t['statut'], 'priorite' => $t['priorite']],
            'titre' => $t['titre'], 'detail' => $t['materiel_concerne'], 'assigne' => $t['assigne_nom_aff']];
        if ($t['date_limite'] !== null && $t['date_limite'] >= $du && $t['date_limite'] <= $au) {
            $evenements[] = $base + ['date' => $t['date_limite'], 'type' => $t['source'] === 'systeme' ? 'preventif' : 'echeance', 'retard' => $t['date_limite'] < $aujourdhui];
        }
        if ($t['statut'] === 'En attente' && $t['date_relance'] !== null && $t['date_relance'] >= $du && $t['date_relance'] <= $au) {
            $evenements[] = $base + ['date' => $t['date_relance'], 'type' => 'relance', 'retard' => $t['date_relance'] < $aujourdhui];
        }
    }

    // Plans de maintenance à venir (avant que leurs tâches soient générées).
    if (has_permission($u, 'maintenance.voir') && !isset($q['assigne'])) {
        foreach ($pdo->query(MAINT_SELECT . ' WHERE p.actif')->fetchAll() as $p) {
            $d = $p['prochaine_echeance']; $n = 0;
            while ($d <= $au && $n++ < 200) {
                if ($d >= $du) {
                    $evenements[] = ['date' => $d, 'type' => 'plan', 'titre' => $p['titre'], 'tache' => null, 'retard' => false,
                        'detail' => $p['materiel_id'] !== null ? materiel_libelle(['type' => $p['m_type'], 'marque' => $p['m_marque'], 'modele' => $p['m_modele'], 'num_serie' => $p['m_serie']])
                            : $p['type_materiel'] . ' (' . $p['nb_materiels'] . ')', 'assigne' => $p['assigne_nom']];
                }
                $d = maint_suivante($d, (int) $p['periodicite_valeur'], $p['periodicite_unite']);
            }
        }
    }

    // Fins de garantie.
    if (has_permission($u, 'materiel.voir_tous') && !isset($q['assigne'])) {
        $st = $pdo->prepare("SELECT id, type, marque, modele, num_serie, date_fin_garantie FROM t_materiel WHERE statut <> 'Retiré' AND date_fin_garantie BETWEEN ? AND ? ORDER BY date_fin_garantie");
        $st->execute([$du, $au]);
        foreach ($st->fetchAll() as $m) {
            $evenements[] = ['date' => $m['date_fin_garantie'], 'type' => 'garantie', 'titre' => 'Fin de garantie', 'tache' => null, 'retard' => false,
                'detail' => materiel_libelle($m), 'assigne' => null, 'materiel_id' => (int) $m['id']];
        }
    }

    usort($evenements, fn($a, $b) => [$a['date'], $a['type']] <=> [$b['date'], $b['type']]);
    api_data($evenements, ['du' => $du, 'au' => $au, 'aujourdhui' => $aujourdhui]);
});
