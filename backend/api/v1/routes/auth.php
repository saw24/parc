<?php
// Authentification par appareil, profil, appareils.

route('POST', 'auth/login', function (array $c): void {
    $pdo = $c['pdo'];
    $login = api_str($c['body'], 'login', true, 100);
    $mdp = (string) ($c['body']['password'] ?? '');
    if ($mdp === '') {
        api_fail(422, 'champ_requis', 'Le champ « password » est requis.', ['champ' => 'password']);
    }
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

    if (device_login_bloque($pdo, $login, $ip)) {
        header('Retry-After: ' . (DEVICE_LOGIN_FENETRE_MIN * 60));
        api_fail(429, 'trop_de_tentatives', 'Trop de tentatives échouées. Réessayez dans ' . DEVICE_LOGIN_FENETRE_MIN . ' minutes.');
    }
    $st = $pdo->prepare("SELECT id, nom, login, role, email, telephone, service_id, mot_de_passe
                         FROM t_utilisateurs WHERE login = ? AND actif AND connexion_autorisee");
    $st->execute([$login]);
    $u = $st->fetch();
    // Vérification toujours effectuée (hash factice si le compte n'existe pas) : temps de réponse uniforme.
    $hash = $u['mot_de_passe'] ?? '$2y$12$NJK.0Kc2.qt7AxuYLUM07OP/EuxJqO.v9BCb0F4xA0fyYdd5evFhW';
    if (!password_verify($mdp, $hash) || !$u) {
        device_login_echec($pdo, $login, $ip);
        api_fail(401, 'identifiants_invalides', 'Identifiants incorrects.');
    }
    device_login_succes($pdo, $login);
    unset($u['mot_de_passe']);
    $u['id'] = (int) $u['id'];
    $u['permissions'] = charger_permissions($pdo, $u['role']);
    $info = is_array($c['body']['appareil'] ?? null) ? $c['body']['appareil'] : [];
    $jetons = device_creer($pdo, $u['id'], $info, $ip);
    api_data(['utilisateur' => v1_user_json($pdo, $u)] + $jetons);
}, false);

route('POST', 'auth/refresh', function (array $c): void {
    $refresh = api_str($c['body'], 'refresh_token', true, 64, 64);
    api_data(device_rafraichir($c['pdo'], $refresh));
}, false);

route('POST', 'auth/logout', function (array $c): void {
    device_revoquer($c['pdo'], $c['appareil']['id'], $c['user']['id']);
    api_data(['deconnecte' => true]);
});

route('GET', 'me', function (array $c): void {
    $st = $c['pdo']->prepare("SELECT notif_email, notif_sms FROM t_utilisateurs WHERE id = ?");
    $st->execute([$c['user']['id']]);
    api_data(v1_user_json($c['pdo'], array_merge($c['user'], $st->fetch())));
});

route('PATCH', 'me', function (array $c): void {
    $email = api_str($c['body'], 'email', false, 255);
    $tel = api_str($c['body'], 'telephone', false, 30);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail(422, 'champ_invalide', 'Adresse e-mail invalide.', ['champ' => 'email']);
    }
    if ($tel !== null && !preg_match('/^\+?[0-9 ().\-]{6,30}$/', $tel)) {
        api_fail(422, 'champ_invalide', 'Numéro de téléphone invalide.', ['champ' => 'telephone']);
    }
    $sets = []; $args = [];
    foreach (['email' => $email, 'telephone' => $tel] as $col => $val) {
        if (array_key_exists($col, $c['body'])) {   // une valeur vide efface le champ
            $sets[] = "$col = ?"; $args[] = $val;
        }
    }
    foreach (['notif_email', 'notif_sms'] as $col) {   // préférences de canaux
        if (array_key_exists($col, $c['body'])) {
            if (!is_bool($c['body'][$col])) {
                api_fail(422, 'champ_invalide', 'Valeur booléenne attendue.', ['champ' => $col]);
            }
            $sets[] = "$col = ?"; $args[] = $c['body'][$col] ? 'true' : 'false';
        }
    }
    if ($sets) {
        $args[] = $c['user']['id'];
        $c['pdo']->prepare('UPDATE t_utilisateurs SET ' . implode(', ', $sets) . ', updated_by_login = login WHERE id = ?')->execute($args);
    }
    $st = $c['pdo']->prepare("SELECT email, telephone, notif_email, notif_sms FROM t_utilisateurs WHERE id = ?");
    $st->execute([$c['user']['id']]);
    api_data(v1_user_json($c['pdo'], array_merge($c['user'], $st->fetch())));
});

route('POST', 'me/mot_de_passe', function (array $c): void {
    $actuel = (string) ($c['body']['actuel'] ?? '');
    $nouveau = (string) ($c['body']['nouveau'] ?? '');
    if (mb_strlen($nouveau) < 10 || mb_strlen($nouveau) > 200) {
        api_fail(422, 'mot_de_passe_faible', 'Le nouveau mot de passe doit contenir au moins 10 caractères.', ['champ' => 'nouveau']);
    }
    if (strcasecmp($nouveau, $c['user']['login']) === 0 || $nouveau === $actuel) {
        api_fail(422, 'mot_de_passe_faible', 'Choisissez un mot de passe différent de l\'actuel et du login.', ['champ' => 'nouveau']);
    }
    $st = $c['pdo']->prepare("SELECT mot_de_passe FROM t_utilisateurs WHERE id = ?");
    $st->execute([$c['user']['id']]);
    if (!password_verify($actuel, (string) $st->fetchColumn())) {
        api_fail(403, 'mot_de_passe_incorrect', 'Mot de passe actuel incorrect.', ['champ' => 'actuel']);
    }
    $c['pdo']->prepare("UPDATE t_utilisateurs SET mot_de_passe = ?, updated_by_login = login WHERE id = ?")
        ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $c['user']['id']]);
    // Les autres appareils (et les sessions web) doivent se reconnecter avec le nouveau mot de passe.
    $c['pdo']->prepare("UPDATE t_appareils SET revoque_at = NOW(), revoque_motif = 'changement_mdp'
                        WHERE user_id = ? AND id <> ? AND revoque_at IS NULL")->execute([$c['user']['id'], $c['appareil']['id']]);
    $c['pdo']->prepare("DELETE FROM t_sessions WHERE user_id = ?")->execute([$c['user']['id']]);
    api_data(['modifie' => true]);
});

route('GET', 'appareils', function (array $c): void {
    $st = $c['pdo']->prepare("SELECT id, nom, plateforme, version_app, derniere_activite, created_at
                              FROM t_appareils WHERE user_id = ? AND revoque_at IS NULL ORDER BY derniere_activite DESC NULLS LAST");
    $st->execute([$c['user']['id']]);
    api_data(array_map(fn($a) => [
        'id' => (int) $a['id'], 'nom' => $a['nom'], 'plateforme' => $a['plateforme'], 'version_app' => $a['version_app'],
        'courant' => (int) $a['id'] === $c['appareil']['id'],
        'derniere_activite' => api_ts($c['pdo'], $a['derniere_activite']), 'created_at' => api_ts($c['pdo'], $a['created_at']),
    ], $st->fetchAll()));
});

// Enregistrement du jeton de notification push (FCM, optionnel) et de la version de l'application.
route('PUT', 'appareils/courant', function (array $c): void {
    $fcm = api_str($c['body'], 'fcm_token', false, 255);
    $version = api_str($c['body'], 'version_app', false, 20);
    $nom = api_str($c['body'], 'nom', false, 100);
    $sets = ['derniere_activite = NOW()']; $args = [];
    if (array_key_exists('fcm_token', $c['body'])) { $sets[] = 'fcm_token = ?'; $args[] = $fcm; }
    if ($version !== null) { $sets[] = 'version_app = ?'; $args[] = $version; }
    if ($nom !== null) { $sets[] = 'nom = ?'; $args[] = $nom; }
    $args[] = $c['appareil']['id'];
    $c['pdo']->prepare('UPDATE t_appareils SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    api_data(['enregistre' => true]);
});

route('DELETE', 'appareils/{id}', function (array $c): void {
    if (!device_revoquer($c['pdo'], $c['params']['id'], $c['user']['id'], 'revoque_par_utilisateur')) {
        api_fail(404, 'introuvable', 'Appareil introuvable.');
    }
    api_data(['revoque' => true]);
});
