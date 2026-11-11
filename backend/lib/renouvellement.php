<?php
// Renouvellement du matériel : suggestions automatiques (fin de vie, pannes répétées, garantie proche).
// À inclure après config/db.php, middleware/auth.php, lib/api.php et lib/materiel.php.

const RENOUV_DUREE_VIE_DEFAUT = 60;      // mois, si le type n'a pas de durée de vie propre
const RENOUV_PANNES_SEUIL = 3;           // pannes sur la fenêtre ci-dessous
const RENOUV_PANNES_FENETRE_MOIS = 12;
const RENOUV_GARANTIE_ALERTE_JOURS = 90;

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
    if ($m['jours_garantie'] !== null) {
        $j = (int) $m['jours_garantie'];
        if ($j >= 0 && $j <= RENOUV_GARANTIE_ALERTE_JOURS) { $criteres[] = ['code' => 'garantie_proche', 'texte' => "Garantie expire dans $j jour(s)"]; $score += 15; }
    }
    $pannes = (int) $m['pannes_recentes'];
    if ($pannes >= RENOUV_PANNES_SEUIL) {
        $criteres[] = ['code' => 'pannes_repetees', 'texte' => "$pannes pannes sur les " . RENOUV_PANNES_FENETRE_MOIS . ' derniers mois'];
        $score += 20 + min(30, ($pannes - RENOUV_PANNES_SEUIL) * 10);
    }
    // Une garantie expirée ne suffit pas à elle seule (tout le vieux parc le serait) : elle aggrave un autre critère.
    if ($criteres && $m['jours_garantie'] !== null && (int) $m['jours_garantie'] < 0) {
        $criteres[] = ['code' => 'garantie_expiree', 'texte' => 'Garantie expirée depuis ' . abs((int) $m['jours_garantie']) . ' jour(s)']; $score += 10;
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
    SELECT m.id, m.type, m.marque, m.modele, m.num_serie, m.date_achat, m.date_fin_garantie, m.statut, m.service_affecte, m.utilisateur_assigne,
           tm.duree_vie_mois, (m.date_fin_garantie - CURRENT_DATE) AS jours_garantie,
           CASE WHEN m.date_achat IS NULL THEN NULL
                ELSE (EXTRACT(YEAR FROM age(CURRENT_DATE, m.date_achat)) * 12 + EXTRACT(MONTH FROM age(CURRENT_DATE, m.date_achat)))::int END AS age_mois,
           (SELECT COUNT(*) FROM t_taches t WHERE t.materiel_id = m.id
              AND t.date_creation >= CURRENT_TIMESTAMP - (? || ' months')::interval) AS pannes_recentes,
           (SELECT COUNT(*) FROM t_taches t WHERE t.materiel_id = m.id AND t.statut NOT IN ('Terminé', 'Annulé')) AS pannes_ouvertes
    FROM t_materiel m
    LEFT JOIN t_type_materiel tm ON tm.type = m.type
";

/** Matériel à renouveler selon les règles, hors matériel retiré. */
function renouv_suggestions(PDO $pdo): array
{
    $st = $pdo->prepare(RENOUV_BASE_MATERIEL . "
        WHERE m.statut <> 'Retiré'");
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
