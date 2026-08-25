<?php
// Paramètres applicatifs modifiables depuis l'écran "Paramètres" (réservé aux
// Administrateurs) : configuration SMTP, seuils métier, fréquences de rappel.
// Stockés en base (table t_parametres, clé/valeur) pour éviter le codage en dur.
// À inclure APRÈS backend/config/db.php.

/**
 * Valeurs par défaut de tous les paramètres connus. Toute clé absente de
 * t_parametres retombe sur sa valeur ici — l'app fonctionne donc sans
 * configuration explicite (comportement actuel préservé par défaut).
 */
function get_default_parametres(): array
{
    return [
        // --- Seuils métier (en jours) ---
        'seuil_echeance_proche_jours'         => '3',
        'seuil_non_prise_en_charge_jours'     => '3',
        'seuil_tache_dormante_jours'          => '7',

        // --- Jauge d'efficacité (Terminées / (Terminées + En retard) sur une fenêtre
        // glissante) : tâches "À faire"/"En cours" non en retard et tâches Annulées
        // exclues du calcul (ni un échec, ni imputable à l'agent). ---
        'efficacite_fenetre_jours' => '30',
        'efficacite_seuil_bon'     => '80', // % à partir duquel la jauge est verte
        'efficacite_seuil_moyen'   => '50', // % à partir duquel la jauge est jaune (en-dessous : rouge)

        // --- Fréquence de rafraîchissement de l'interface (secondes) ---
        'intervalle_rafraichissement_secondes' => '30',

        // --- Notifications par email : interrupteur général + par évènement ---
        'email_notifications_actives'          => '0',
        'email_on_nouvelle_tache_assignee'     => '0',
        'email_on_digest_quotidien'            => '0',
        'email_on_escalade'                    => '0',
        // Vide = envoyer à tous les comptes Administrateur
        'escalade_email_destinataires'         => '',

        // --- Fréquence des rappels par email (heures minimum entre deux envois) ---
        'frequence_digest_heures'              => '24',
        'frequence_escalade_heures'            => '24',

        // --- Configuration SMTP ---
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_encryption' => 'tls', // 'none' | 'tls' | 'ssl'
        'smtp_username'   => '',
        'smtp_password'   => '',
        'smtp_from_email' => '',
        'smtp_from_name'  => 'Parc Info',

        // --- Bookkeeping interne (dernier envoi), non exposé dans l'écran Paramètres ---
        'last_digest_sent_at'   => '',
        'last_escalade_sent_at' => '',
    ];
}

// Clés de bookkeeping interne : jamais renvoyées par l'API 'read', jamais
// acceptées par l'API 'update' (seul le script de rappels les écrit).
function get_parametres_internes(): array
{
    return ['last_digest_sent_at', 'last_escalade_sent_at'];
}

/**
 * Retourne tous les paramètres (défauts fusionnés avec les valeurs en base).
 */
function get_parametres(PDO $pdo): array
{
    $params = get_default_parametres();
    try {
        $stmt = $pdo->query("SELECT cle, valeur FROM t_parametres");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $params[$row['cle']] = $row['valeur'] ?? '';
        }
    } catch (PDOException $e) {
        error_log('Erreur lecture t_parametres (valeurs par défaut utilisées) : ' . $e->getMessage());
    }
    return $params;
}

/**
 * Sous-ensemble sans secret, exposable à tout utilisateur authentifié (utilisé par le
 * frontend pour remplacer les seuils/fréquences codés en dur en JavaScript).
 */
function get_parametres_publics(PDO $pdo): array
{
    $params = get_parametres($pdo);
    return [
        'seuil_echeance_proche_jours'          => (int) $params['seuil_echeance_proche_jours'],
        'seuil_non_prise_en_charge_jours'      => (int) $params['seuil_non_prise_en_charge_jours'],
        'seuil_tache_dormante_jours'           => (int) $params['seuil_tache_dormante_jours'],
        'intervalle_rafraichissement_secondes' => (int) $params['intervalle_rafraichissement_secondes'],
        'efficacite_fenetre_jours'              => (int) $params['efficacite_fenetre_jours'],
    ];
}

/**
 * Taux de résolution = Terminées / (Terminées + En retard), arrondi. Les tâches encore
 * ouvertes non en retard et les tâches Annulées sont volontairement exclues en amont
 * (ni un échec de l'agent, ni toujours de son fait) — voir les requêtes SQL qui
 * alimentent $resolues/$enRetard dans taches.php et utilisateurs.php.
 * Retourne null si aucune tâche résolue ni en retard sur la période (rien à mesurer).
 */
function compute_efficacite_pct(int $resolues, int $enRetard): ?float
{
    $total = $resolues + $enRetard;
    if ($total === 0) {
        return null;
    }
    return round(($resolues / $total) * 100);
}

/**
 * Classe un pourcentage d'efficacité en zone 'bon' | 'moyen' | 'mauvais' selon les seuils
 * configurés (Paramètres > Seuils métier). Retourne null si $pct est null (pas assez de
 * données sur la fenêtre pour calculer un taux).
 */
function efficacite_zone(?float $pct, array $params): ?string
{
    if ($pct === null) {
        return null;
    }
    if ($pct >= (float) $params['efficacite_seuil_bon']) {
        return 'bon';
    }
    if ($pct >= (float) $params['efficacite_seuil_moyen']) {
        return 'moyen';
    }
    return 'mauvais';
}

/**
 * Enregistre un ensemble de paramètres (upsert). Ignore silencieusement les clés
 * inconnues ou internes, pour ne jamais laisser l'API 'update' écraser le
 * bookkeeping du script de rappels ou créer des clés arbitraires.
 */
function save_parametres(PDO $pdo, array $valeurs, string $updatedByLogin): void
{
    $clesValides = array_diff(array_keys(get_default_parametres()), get_parametres_internes());
    $stmt = $pdo->prepare("
        INSERT INTO t_parametres (cle, valeur, updated_by_login, date_modification)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT (cle) DO UPDATE SET
            valeur = EXCLUDED.valeur,
            updated_by_login = EXCLUDED.updated_by_login,
            date_modification = CURRENT_TIMESTAMP
    ");

    foreach ($valeurs as $cle => $valeur) {
        if (!in_array($cle, $clesValides, true)) {
            continue;
        }
        $stmt->execute([$cle, (string) $valeur, $updatedByLogin]);
    }
}
