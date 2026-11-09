<?php
// Administration depuis le mobile : utilisateurs, rôles (lecture) et services. Chaque route exige la permission
// correspondante — un rôle qui la possède peut donc tout faire depuis l'application, sans compte administrateur.

const V1_LOGIN_REGEX = '/^[A-Za-z0-9._@\-]{3,100}$/';

function v1_utilisateur_json(array $r): array
{
    return [
        'id' => (int) $r['id'], 'nom' => $r['nom'], 'login' => $r['login'], 'email' => $r['email'], 'telephone' => $r['telephone'],
        'role' => $r['role'], 'service' => $r['service_id'] ? ['id' => (int) $r['service_id'], 'nom' => $r['service']] : null,
        'actif' => (bool) $r['actif'], 'connexion_autorisee' => (bool) $r['connexion_autorisee'],
        'compte_technique' => (bool) $r['est_file_commune'],
    ];
}

const V1_UTILISATEUR_SELECT = "SELECT u.id, u.nom, u.login, u.email, u.telephone, u.role, u.service_id, s.nom AS service,
        u.actif, u.connexion_autorisee, u.est_file_commune
    FROM t_utilisateurs u LEFT JOIN t_services s ON s.id = u.service_id";

function v1_utilisateur_charger(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(V1_UTILISATEUR_SELECT . ' WHERE u.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: api_fail(404, 'introuvable', 'Utilisateur introuvable.');
}

function v1_est_admin_systeme(PDO $pdo, string $role): bool
{
    $st = $pdo->prepare("SELECT systeme FROM t_roles WHERE nom = ?");
    $st->execute([$role]);
    return (bool) $st->fetchColumn();
}

/** Rôle existant ; attribuer un rôle système (Administrateur) est réservé à un compte qui l'est lui-même. */
function v1_role_attribuable(PDO $pdo, array $acteur, string $role): void
{
    $st = $pdo->prepare("SELECT systeme FROM t_roles WHERE nom = ?");
    $st->execute([$role]);
    $ligne = $st->fetch();
    if (!$ligne) {
        api_fail(422, 'champ_invalide', 'Rôle inconnu.', ['champ' => 'role']);
    }
    if ($ligne['systeme'] && !v1_est_admin_systeme($pdo, $acteur['role'])) {
        api_fail(403, 'interdit', 'Seul un administrateur peut attribuer le rôle « ' . $role . ' ».');
    }
}

/** Nombre d'administrateurs actifs pouvant se connecter, hors $exclureId. */
function v1_autres_admins_actifs(PDO $pdo, int $exclureId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM t_utilisateurs u JOIN t_roles r ON r.nom = u.role
        WHERE r.systeme AND u.actif AND u.connexion_autorisee AND u.id <> ?");
    $st->execute([$exclureId]);
    return (int) $st->fetchColumn();
}

/** Coupe toutes les sessions (mobile et web) d'un compte : désactivation, changement de rôle ou de mot de passe. */
function v1_couper_sessions(PDO $pdo, int $userId, string $motif): void
{
    $pdo->prepare("UPDATE t_appareils SET revoque_at = NOW(), revoque_motif = ? WHERE user_id = ? AND revoque_at IS NULL")->execute([$motif, $userId]);
    $pdo->prepare("DELETE FROM t_sessions WHERE user_id = ?")->execute([$userId]);
}

function v1_bool(array $in, string $key): ?bool
{
    if (!array_key_exists($key, $in)) {
        return null;
    }
    if (!is_bool($in[$key])) {
        api_fail(422, 'champ_invalide', "Le champ « $key » doit être un booléen.", ['champ' => $key]);
    }
    return $in[$key];
}

function v1_mot_de_passe(array $in, bool $requis): ?string
{
    $mdp = $in['mot_de_passe'] ?? null;
    if ($mdp === null || $mdp === '') {
        if ($requis) {
            api_fail(422, 'champ_requis', 'Le champ « mot_de_passe » est requis.', ['champ' => 'mot_de_passe']);
        }
        return null;
    }
    if (!is_string($mdp) || mb_strlen($mdp) < 10 || mb_strlen($mdp) > 200) {
        api_fail(422, 'mot_de_passe_faible', 'Le mot de passe doit contenir au moins 10 caractères.', ['champ' => 'mot_de_passe']);
    }
    return $mdp;
}

function v1_champs_utilisateur(PDO $pdo, array $b, bool $creation): array
{
    $d = [];
    if ($creation || array_key_exists('nom', $b)) { $d['nom'] = api_str($b, 'nom', true, 100); }
    if ($creation || array_key_exists('login', $b)) {
        $d['login'] = api_str($b, 'login', true, 100);
        if (!preg_match(V1_LOGIN_REGEX, $d['login'])) {
            api_fail(422, 'champ_invalide', 'Identifiant : 3 à 100 caractères (lettres, chiffres, . _ @ -).', ['champ' => 'login']);
        }
    }
    if (array_key_exists('email', $b)) {
        $d['email'] = api_str($b, 'email', false, 255);
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            api_fail(422, 'champ_invalide', 'Adresse e-mail invalide.', ['champ' => 'email']);
        }
    }
    if (array_key_exists('telephone', $b)) {
        $d['telephone'] = api_str($b, 'telephone', false, 30);
        if ($d['telephone'] !== null && !preg_match('/^\+?[0-9 ().\-]{6,30}$/', $d['telephone'])) {
            api_fail(422, 'champ_invalide', 'Numéro de téléphone invalide.', ['champ' => 'telephone']);
        }
    }
    if ($creation || array_key_exists('role', $b)) { $d['role'] = api_str($b, 'role', true, 50); }
    if (array_key_exists('service_id', $b)) {
        $sid = api_int($b, 'service_id');
        if ($sid !== null) {
            $st = $pdo->prepare("SELECT 1 FROM t_services WHERE id = ?");
            $st->execute([$sid]);
            if (!$st->fetchColumn()) {
                api_fail(422, 'champ_invalide', 'Service inconnu.', ['champ' => 'service_id']);
            }
        }
        $d['service_id'] = $sid;
    }
    foreach (['actif', 'connexion_autorisee'] as $k) {
        if (($v = v1_bool($b, $k)) !== null) { $d[$k] = $v; }
    }
    return $d;
}

function v1_conflit_unicite(PDOException $e): never
{
    if ($e->getCode() === '23505') {
        $msg = $e->getMessage();
        if (str_contains($msg, 'login')) {
            api_fail(409, 'login_existant', 'Cet identifiant est déjà utilisé.');
        }
        api_fail(409, 'nom_existant', 'Un utilisateur porte déjà ce nom.');
    }
    throw $e;
}

// ---------------------------------------------------------------- Utilisateurs

/** Liste légère (choix d'un agent) ; ?detail=1 ajoute identifiant, rôle, état… et exige utilisateur.lister. */
route('GET', 'utilisateurs', function (array $c): void {
    $u = $c['user'];
    $detail = !empty($c['query']['detail']);
    if (!has_any_permission($u, $detail ? ['utilisateur.lister', 'utilisateur.gerer'] : ['utilisateur.lister', 'utilisateur.gerer', 'materiel.creer', 'materiel.modifier'])) {
        api_fail(403, 'interdit', 'Permission manquante : utilisateur.lister');
    }
    $where = ['NOT u.est_file_commune'];
    $args = [];
    if (!$detail || empty($c['query']['inclure_inactifs'])) { $where[] = 'u.actif'; }
    if (!empty($c['query']['q'])) {
        $where[] = '(u.nom ILIKE ? OR u.login ILIKE ?)';
        $m = '%' . addcslashes(mb_substr((string) $c['query']['q'], 0, 100), '%_\\') . '%';
        array_push($args, $m, $m);
    }
    $st = $c['pdo']->prepare(V1_UTILISATEUR_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.nom');
    $st->execute($args);
    api_data(array_map(fn($r) => $detail ? v1_utilisateur_json($r)
        : ['id' => (int) $r['id'], 'nom' => $r['nom'], 'service' => $r['service_id'] ? ['id' => (int) $r['service_id'], 'nom' => $r['service']] : null],
        $st->fetchAll()));
});

route('GET', 'utilisateurs/{id}', function (array $c): void {
    require_any_permission_v1($c['user'], ['utilisateur.lister', 'utilisateur.gerer']);
    api_data(v1_utilisateur_json(v1_utilisateur_charger($c['pdo'], $c['params']['id'])));
});

route('POST', 'utilisateurs', function (array $c): void {
    $pdo = $c['pdo']; $b = $c['body'];
    require_permission_v1($c['user'], 'utilisateur.gerer');
    $d = v1_champs_utilisateur($pdo, $b, true);
    $mdp = v1_mot_de_passe($b, true);
    v1_role_attribuable($pdo, $c['user'], $d['role']);
    try {
        $st = $pdo->prepare("INSERT INTO t_utilisateurs (nom, login, email, telephone, role, service_id, actif, connexion_autorisee, mot_de_passe, created_by_login)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
        $st->execute([$d['nom'], $d['login'], $d['email'] ?? null, $d['telephone'] ?? null, $d['role'], $d['service_id'] ?? null,
            ($d['actif'] ?? true) ? 'true' : 'false', ($d['connexion_autorisee'] ?? true) ? 'true' : 'false',
            password_hash($mdp, PASSWORD_DEFAULT), $c['user']['login']]);
    } catch (PDOException $e) {
        v1_conflit_unicite($e);
    }
    api_data(v1_utilisateur_json(v1_utilisateur_charger($pdo, (int) $st->fetchColumn())), [], 201);
});

route('PATCH', 'utilisateurs/{id}', function (array $c): void {
    $pdo = $c['pdo']; $b = $c['body']; $id = $c['params']['id']; $acteur = $c['user'];
    require_permission_v1($acteur, 'utilisateur.gerer');
    $cible = v1_utilisateur_charger($pdo, $id);
    if ($cible['est_file_commune']) {
        api_fail(403, 'compte_technique', 'Le compte « Service IT » (file commune) ne peut pas être modifié.');
    }
    $d = v1_champs_utilisateur($pdo, $b, false);
    $mdp = v1_mot_de_passe($b, false);
    $moi = $id === (int) $acteur['id'];
    $roleChange = isset($d['role']) && $d['role'] !== $cible['role'];
    $desactive = (array_key_exists('actif', $d) && !$d['actif']) || (array_key_exists('connexion_autorisee', $d) && !$d['connexion_autorisee']);
    if ($moi && ($roleChange || $desactive)) {
        api_fail(422, 'auto_modification', 'Vous ne pouvez pas changer votre propre rôle ni désactiver votre compte.');
    }
    if ($roleChange) {
        v1_role_attribuable($pdo, $acteur, $d['role']);
    }
    // Ne jamais laisser l'application sans administrateur utilisable.
    if (v1_est_admin_systeme($pdo, $cible['role']) && ($roleChange && !v1_est_admin_systeme($pdo, $d['role']) || $desactive)
        && v1_autres_admins_actifs($pdo, $id) === 0) {
        api_fail(409, 'dernier_admin', 'Impossible : ce compte est le dernier administrateur actif.');
    }
    $pdo->beginTransaction();
    try {
        $sets = []; $args = [];
        foreach ($d as $col => $val) {
            $sets[] = "$col = ?";
            $args[] = is_bool($val) ? ($val ? 'true' : 'false') : $val;
        }
        if ($mdp !== null) { $sets[] = 'mot_de_passe = ?'; $args[] = password_hash($mdp, PASSWORD_DEFAULT); }
        if ($sets) {
            $sets[] = 'updated_by_login = ?'; $args[] = $acteur['login']; $args[] = $id;
            $pdo->prepare('UPDATE t_utilisateurs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        }
        // Les affectations de pannes et de matériel référencent le NOM de l'agent (texte) : on les suit.
        if (isset($d['nom']) && $d['nom'] !== $cible['nom']) {
            $pdo->prepare("UPDATE t_taches SET assigne_a = ? WHERE assigne_a = ?")->execute([$d['nom'], $cible['nom']]);
            $pdo->prepare("UPDATE t_materiel SET utilisateur_assigne = ? WHERE utilisateur_assigne = ?")->execute([$d['nom'], $cible['nom']]);
        }
        if ($desactive || $mdp !== null || $roleChange) {
            v1_couper_sessions($pdo, $id, $mdp !== null ? 'mot_de_passe' : ($desactive ? 'desactive' : 'role'));
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        v1_conflit_unicite($e);
    }
    api_data(v1_utilisateur_json(v1_utilisateur_charger($pdo, $id)));
});

route('DELETE', 'utilisateurs/{id}', function (array $c): void {
    $pdo = $c['pdo']; $id = $c['params']['id'];
    require_permission_v1($c['user'], 'utilisateur.gerer');
    $cible = v1_utilisateur_charger($pdo, $id);
    if ($id === (int) $c['user']['id']) {
        api_fail(422, 'auto_modification', 'Vous ne pouvez pas supprimer votre propre compte.');
    }
    if ($cible['est_file_commune']) {
        api_fail(403, 'compte_technique', 'Le compte « Service IT » (file commune) ne peut pas être supprimé.');
    }
    if (v1_est_admin_systeme($pdo, $cible['role']) && v1_autres_admins_actifs($pdo, $id) === 0) {
        api_fail(409, 'dernier_admin', 'Impossible de supprimer le dernier administrateur actif.');
    }
    $pdo->prepare("DELETE FROM t_utilisateurs WHERE id = ?")->execute([$id]);   // pannes : déclarant/assigné passent à NULL, historique conservé
    api_data(['supprime' => true]);
});

// ---------------------------------------------------------------- Rôles et permissions

function v1_role_json(PDO $pdo, array $r, bool $avecPermissions): array
{
    $out = ['id' => (int) $r['id'], 'nom' => $r['nom'], 'description' => $r['description'], 'systeme' => (bool) $r['systeme'],
        'nb_utilisateurs' => (int) $r['nb_utilisateurs']];
    if ($avecPermissions) {
        $out['permissions'] = $r['systeme'] ? $pdo->query("SELECT code FROM t_permissions ORDER BY ordre")->fetchAll(PDO::FETCH_COLUMN)
            : array_map(fn($x) => $x, (function () use ($pdo, $r) {
                $st = $pdo->prepare("SELECT p.code FROM t_roles_permissions rp JOIN t_permissions p ON p.code = rp.permission_code WHERE rp.role_id = ? ORDER BY p.ordre");
                $st->execute([$r['id']]);
                return $st->fetchAll(PDO::FETCH_COLUMN);
            })());
    }
    return $out;
}

const V1_ROLE_SELECT = "SELECT r.id, r.nom, r.description, r.systeme, (SELECT COUNT(*) FROM t_utilisateurs u WHERE u.role = r.nom) AS nb_utilisateurs FROM t_roles r";

function v1_role_charger(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(V1_ROLE_SELECT . ' WHERE r.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: api_fail(404, 'introuvable', 'Rôle introuvable.');
}

/** Rôles attribuables ; avec role.gerer, chaque rôle porte aussi ses permissions. */
route('GET', 'roles', function (array $c): void {
    require_any_permission_v1($c['user'], ['utilisateur.lister', 'utilisateur.gerer', 'role.gerer']);
    $detail = has_permission($c['user'], 'role.gerer');
    $rows = $c['pdo']->query(V1_ROLE_SELECT . ' ORDER BY r.systeme DESC, r.nom')->fetchAll();
    api_data(array_map(fn($r) => v1_role_json($c['pdo'], $r, $detail), $rows));
});

/** Catalogue des permissions, regroupées, pour construire l'écran des rôles. */
route('GET', 'permissions', function (array $c): void {
    require_permission_v1($c['user'], 'role.gerer');
    $groupes = [];
    foreach ($c['pdo']->query("SELECT code, groupe, libelle FROM t_permissions ORDER BY ordre")->fetchAll() as $p) {
        $groupes[$p['groupe']][] = ['code' => $p['code'], 'libelle' => $p['libelle']];
    }
    api_data(array_map(fn($g, $l) => ['groupe' => $g, 'permissions' => $l], array_keys($groupes), array_values($groupes)));
});

function v1_role_permissions_valides(PDO $pdo, array $b): array
{
    $liste = $b['permissions'] ?? null;
    if (!is_array($liste) || !array_is_list($liste)) {
        api_fail(422, 'champ_invalide', 'Le champ « permissions » doit être une liste de codes.', ['champ' => 'permissions']);
    }
    $liste = array_values(array_unique(array_map('strval', $liste)));
    $connues = $pdo->query("SELECT code FROM t_permissions")->fetchAll(PDO::FETCH_COLUMN);
    if ($inconnues = array_diff($liste, $connues)) {
        api_fail(422, 'champ_invalide', 'Permission inconnue : ' . implode(', ', $inconnues), ['champ' => 'permissions']);
    }
    return $liste;
}

function v1_role_ecrire_permissions(PDO $pdo, int $id, array $codes): void
{
    $pdo->prepare("DELETE FROM t_roles_permissions WHERE role_id = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO t_roles_permissions (role_id, permission_code) VALUES (?, ?)");
    foreach ($codes as $code) { $ins->execute([$id, $code]); }
}

route('POST', 'roles', function (array $c): void {
    $pdo = $c['pdo']; $b = $c['body'];
    require_permission_v1($c['user'], 'role.gerer');
    $nom = api_str($b, 'nom', true, 50);
    $desc = api_str($b, 'description', false, 500);
    $codes = v1_role_permissions_valides($pdo, $b + ['permissions' => $b['permissions'] ?? []]);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO t_roles (nom, description) VALUES (?, ?) RETURNING id");
        $st->execute([$nom, $desc]);
        $id = (int) $st->fetchColumn();
        v1_role_ecrire_permissions($pdo, $id, $codes);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e->getCode() === '23505') { api_fail(409, 'nom_existant', 'Un rôle porte déjà ce nom.'); }
        throw $e;
    }
    api_data(v1_role_json($pdo, v1_role_charger($pdo, $id), true), [], 201);
});

route('PATCH', 'roles/{id}', function (array $c): void {
    $pdo = $c['pdo']; $b = $c['body']; $id = $c['params']['id'];
    require_permission_v1($c['user'], 'role.gerer');
    $r = v1_role_charger($pdo, $id);
    if ($r['systeme']) {
        api_fail(403, 'role_systeme', 'Le rôle « ' . $r['nom'] . ' » est un rôle système : il n\'est pas modifiable.');
    }
    $nom = array_key_exists('nom', $b) ? api_str($b, 'nom', true, 50) : $r['nom'];
    $desc = array_key_exists('description', $b) ? api_str($b, 'description', false, 500) : $r['description'];
    $codes = array_key_exists('permissions', $b) ? v1_role_permissions_valides($pdo, $b) : null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE t_roles SET nom = ?, description = ? WHERE id = ?")->execute([$nom, $desc, $id]);   // les comptes suivent (FK ON UPDATE CASCADE)
        if ($codes !== null) { v1_role_ecrire_permissions($pdo, $id, $codes); }
        // Les droits ont changé : les sessions des comptes concernés doivent repartir de zéro.
        $pdo->prepare("UPDATE t_appareils SET revoque_at = NOW(), revoque_motif = 'role' WHERE revoque_at IS NULL AND user_id IN (SELECT id FROM t_utilisateurs WHERE role = ? AND id <> ?)")->execute([$nom, $c['user']['id']]);
        $pdo->prepare("DELETE FROM t_sessions WHERE user_id IN (SELECT id FROM t_utilisateurs WHERE role = ? AND id <> ?)")->execute([$nom, $c['user']['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e->getCode() === '23505') { api_fail(409, 'nom_existant', 'Un rôle porte déjà ce nom.'); }
        throw $e;
    }
    api_data(v1_role_json($pdo, v1_role_charger($pdo, $id), true));
});

route('DELETE', 'roles/{id}', function (array $c): void {
    $pdo = $c['pdo']; $id = $c['params']['id'];
    require_permission_v1($c['user'], 'role.gerer');
    $r = v1_role_charger($pdo, $id);
    if ($r['systeme']) {
        api_fail(403, 'role_systeme', 'Un rôle système ne peut pas être supprimé.');
    }
    if ((int) $r['nb_utilisateurs'] > 0) {
        api_fail(409, 'role_utilise', "Ce rôle est attribué à {$r['nb_utilisateurs']} utilisateur(s) : changez leur rôle d'abord.", ['nb_utilisateurs' => (int) $r['nb_utilisateurs']]);
    }
    $pdo->prepare("DELETE FROM t_roles WHERE id = ?")->execute([$id]);
    api_data(['supprime' => true]);
});

// ---------------------------------------------------------------- Paramètres de l'application

/** Clés exposées : tout sauf les valeurs internes ; les secrets ne sortent jamais (seul un indicateur « configuré »). */
const V1_PARAM_SECRETS = ['smtp_password', 'sms_jeton', 'fcm_compte_service'];

function v1_parametres_lisibles(PDO $pdo): array
{
    $p = get_parametres($pdo);
    foreach (get_parametres_internes() as $k) { unset($p[$k]); }
    foreach (V1_PARAM_SECRETS as $k) {
        $p[$k . '_configure'] = trim((string) ($p[$k] ?? '')) !== '';
        unset($p[$k]);
    }
    return $p;
}

route('GET', 'parametres', function (array $c): void {
    require_permission_v1($c['user'], 'parametre.gerer');
    api_data(v1_parametres_lisibles($c['pdo']));
});

/** Modification partielle : seules les clés envoyées changent. Secret : "" conserve, null efface. */
route('PATCH', 'parametres', function (array $c): void {
    $pdo = $c['pdo']; $b = $c['body'];
    require_permission_v1($c['user'], 'parametre.gerer');
    $connues = get_default_parametres();
    foreach (get_parametres_internes() as $k) { unset($connues[$k]); }
    $donnees = [];
    foreach ($b as $cle => $val) {
        if (!array_key_exists($cle, $connues)) {
            api_fail(422, 'champ_invalide', "Paramètre inconnu : $cle", ['champ' => $cle]);
        }
        if (in_array($cle, V1_PARAM_SECRETS, true)) {
            if ($val === '') { continue; }
            $val = $val === null ? '' : $val;
        }
        if (is_bool($val)) { $val = $val ? '1' : '0'; }
        if (!is_scalar($val)) {
            api_fail(422, 'champ_invalide', "Valeur invalide pour $cle", ['champ' => $cle]);
        }
        $donnees[$cle] = (string) $val;
    }
    if (!$donnees) {
        api_fail(422, 'aucun_champ', 'Aucun paramètre à modifier.');
    }
    save_parametres($pdo, $donnees, $c['user']['login']);
    api_data(v1_parametres_lisibles($pdo));
});

function require_any_permission_v1(array $user, array $codes): void
{
    if (!has_any_permission($user, $codes)) {
        api_fail(403, 'interdit', 'Permission manquante : ' . $codes[0], ['permission' => $codes[0]]);
    }
}

// ---------------------------------------------------------------- Services

const V1_SERVICE_SELECT = "SELECT s.id, s.nom, s.description,
    (SELECT COUNT(*) FROM t_utilisateurs u WHERE u.service_id = s.id) AS nb_utilisateurs,
    (SELECT COUNT(*) FROM t_materiel m WHERE m.service_affecte = s.nom) AS nb_materiels
    FROM t_services s";

function v1_service_json(array $r): array
{
    return ['id' => (int) $r['id'], 'nom' => $r['nom'], 'description' => $r['description'],
        'nb_utilisateurs' => (int) $r['nb_utilisateurs'], 'nb_materiels' => (int) $r['nb_materiels']];
}

function v1_service_charger(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(V1_SERVICE_SELECT . ' WHERE s.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: api_fail(404, 'introuvable', 'Service introuvable.');
}

route('GET', 'services', function (array $c): void {
    require_permission_v1($c['user'], 'referentiel.voir');
    api_data(array_map('v1_service_json', $c['pdo']->query(V1_SERVICE_SELECT . ' ORDER BY s.nom')->fetchAll()));
});

route('POST', 'services', function (array $c): void {
    $pdo = $c['pdo'];
    require_permission_v1($c['user'], 'referentiel.gerer');
    $nom = api_str($c['body'], 'nom', true, 100);
    $desc = api_str($c['body'], 'description', false, 1000);
    try {
        $st = $pdo->prepare("INSERT INTO t_services (nom, description, created_by_login, updated_by_login) VALUES (?, ?, ?, ?) RETURNING id");
        $st->execute([$nom, $desc, $c['user']['login'], $c['user']['login']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') { api_fail(409, 'nom_existant', 'Un service porte déjà ce nom.'); }
        throw $e;
    }
    api_data(v1_service_json(v1_service_charger($pdo, (int) $st->fetchColumn())), [], 201);
});

route('PATCH', 'services/{id}', function (array $c): void {
    $pdo = $c['pdo']; $id = $c['params']['id']; $b = $c['body'];
    require_permission_v1($c['user'], 'referentiel.gerer');
    $avant = v1_service_charger($pdo, $id);
    $nom = array_key_exists('nom', $b) ? api_str($b, 'nom', true, 100) : $avant['nom'];
    $desc = array_key_exists('description', $b) ? api_str($b, 'description', false, 1000) : $avant['description'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE t_services SET nom = ?, description = ?, updated_by_login = ? WHERE id = ?")->execute([$nom, $desc, $c['user']['login'], $id]);
        if ($nom !== $avant['nom']) {   // pannes et matériel mémorisent le NOM du service : on les renomme avec lui
            $pdo->prepare("UPDATE t_materiel SET service_affecte = ? WHERE service_affecte = ?")->execute([$nom, $avant['nom']]);
            $pdo->prepare("UPDATE t_materiel_affectations SET service_affecte = ? WHERE service_affecte = ?")->execute([$nom, $avant['nom']]);
            $pdo->prepare("UPDATE t_taches SET service_concerne = ? WHERE service_concerne = ?")->execute([$nom, $avant['nom']]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e->getCode() === '23505') { api_fail(409, 'nom_existant', 'Un service porte déjà ce nom.'); }
        throw $e;
    }
    api_data(v1_service_json(v1_service_charger($pdo, $id)));
});

route('DELETE', 'services/{id}', function (array $c): void {
    $pdo = $c['pdo']; $id = $c['params']['id'];
    require_permission_v1($c['user'], 'referentiel.supprimer');
    $s = v1_service_charger($pdo, $id);
    if ((int) $s['nb_utilisateurs'] > 0 || (int) $s['nb_materiels'] > 0) {
        api_fail(409, 'service_utilise', "Ce service est utilisé par {$s['nb_utilisateurs']} utilisateur(s) et {$s['nb_materiels']} matériel(s) : réaffectez-les d'abord.",
            ['nb_utilisateurs' => (int) $s['nb_utilisateurs'], 'nb_materiels' => (int) $s['nb_materiels']]);
    }
    $pdo->prepare("DELETE FROM t_services WHERE id = ?")->execute([$id]);
    api_data(['supprime' => true]);
});
