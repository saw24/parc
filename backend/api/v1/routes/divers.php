<?php
// Configuration publique, référentiels, agents IT, tableau de bord d'accueil.

route('GET', 'config', function (array $c): void {
    $p = get_parametres($c['pdo']);
    api_data([
        'api_version' => 1,
        'server_time' => api_now($c['pdo']),
        'app_version_courante' => $p['app_version_courante'],
        'app_version_minimale' => $p['app_version_minimale'],
        'apk_url' => $p['app_apk_url'] ?: null,
        'notes_version' => $p['app_notes_version'] ?: null,
    ]);
}, false);

// Listes de valeurs pour les formulaires (accessibles à tout compte connecté : un déclarant doit
// pouvoir choisir un service).
route('GET', 'referentiels', function (array $c): void {
    $pdo = $c['pdo'];
    api_data([
        'services' => $pdo->query("SELECT id, nom FROM t_services ORDER BY nom")->fetchAll(),
        'types_materiel' => $pdo->query("SELECT id, type AS nom FROM t_type_materiel ORDER BY type")->fetchAll(),
        'priorites' => ['Basse', 'Moyenne', 'Haute'],
        'statuts_tache' => ['À faire', 'En cours', 'En attente', 'Terminé', 'Annulé'],
        'statuts_materiel' => MATERIEL_STATUTS,
        'sla' => $pdo->query("SELECT priorite, delai_prise_en_charge_min, delai_resolution_min FROM t_sla_regles ORDER BY delai_prise_en_charge_min")->fetchAll(),
    ]);
});

// Agents auxquels on peut affecter une panne (+ la file « Service IT »), avec leur charge actuelle.
route('GET', 'agents', function (array $c): void {
    require_permission_v1($c['user'], 'tache.affecter');
    $st = $c['pdo']->query("
        SELECT u.id, u.nom, u.est_file_commune,
               (SELECT COUNT(*) FROM t_taches t WHERE t.assigne_id = u.id AND t.statut NOT IN ('Terminé', 'Annulé')) AS charge
        FROM t_utilisateurs u JOIN t_roles r ON r.nom = u.role
        WHERE u.actif AND (u.est_file_commune OR r.systeme OR EXISTS (
              SELECT 1 FROM t_roles_permissions rp WHERE rp.role_id = r.id AND rp.permission_code = 'tache.prendre'))
        ORDER BY u.est_file_commune DESC, u.nom
    ");
    api_data(array_map(fn($a) => ['id' => (int) $a['id'], 'nom' => $a['nom'], 'file_commune' => (bool) $a['est_file_commune'],
        'charge' => (int) $a['charge']], $st->fetchAll()));
});

function require_permission_v1(array $user, string $code): void
{
    if (!has_permission($user, $code)) {
        api_fail(403, 'interdit', 'Permission manquante : ' . $code, ['permission' => $code]);
    }
}
