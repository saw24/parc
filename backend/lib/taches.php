<?php
// Logique métier des pannes/tâches, partagée par les API (web aujourd'hui, mobile ensuite).
// Toute modification du cycle de vie d'une panne (prise en charge, affectation, statut,
// commentaire) passe par tache_transition() : une seule transaction qui verrouille la ligne,
// vérifie les droits, met à jour la tâche et écrit l'évènement d'historique. C'est cet
// historique que le système de notification lira (lot 4).
//
// À inclure après backend/config/db.php et backend/middleware/auth.php.

const TACHE_STATUTS_FINAUX = ['Terminé', 'Annulé'];

/** @return array{id:int,nom:string}|null  Le compte technique « Service IT » (file commune). */
function tache_file_commune(PDO $pdo): ?array
{
    static $cache = false;
    if ($cache === false) {
        $r = $pdo->query("SELECT id, nom FROM t_utilisateurs WHERE est_file_commune LIMIT 1")->fetch();
        $cache = $r ? ['id' => (int) $r['id'], 'nom' => $r['nom']] : null;
    }
    return $cache;
}

function tache_utilisateur_id_par_nom(PDO $pdo, string $nom): ?int
{
    if (trim($nom) === '') {
        return null;
    }
    $st = $pdo->prepare("SELECT id FROM t_utilisateurs WHERE nom = ?");
    $st->execute([$nom]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Retrouve le matériel d'une tâche à partir du libellé « type marque modèle (n° série) » produit
 * par le formulaire. Retourne null si aucun ou plusieurs matériels correspondent.
 */
function tache_materiel_id_depuis_libelle(PDO $pdo, ?string $libelle): ?int
{
    if ($libelle === null || !preg_match('/\(([^()]+)\)\s*$/', $libelle, $m)) {
        return null;
    }
    $st = $pdo->prepare("SELECT id FROM t_materiel WHERE num_serie = ?");
    $st->execute([trim($m[1])]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int) $ids[0] : null;
}

/** Échéances SLA (prise en charge, résolution) pour une priorité, à partir de maintenant. */
function tache_echeances(PDO $pdo, string $priorite): array
{
    $st = $pdo->prepare("
        SELECT NOW() + (delai_prise_en_charge_min || ' minutes')::interval AS pec,
               NOW() + (delai_resolution_min || ' minutes')::interval AS res
        FROM t_sla_regles WHERE priorite = ?
    ");
    $st->execute([$priorite]);
    $r = $st->fetch();
    return $r ? [$r['pec'], $r['res']] : [null, null];
}

function tache_evenement(PDO $pdo, int $tacheId, string $type, ?array $acteur, array $d = []): void
{
    $pdo->prepare("
        INSERT INTO t_taches_evenements
            (tache_id, type, acteur_id, acteur_nom, ancien_statut, nouveau_statut,
             ancien_assigne_id, nouveau_assigne_id, commentaire, visible_declarant)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $tacheId, $type, $acteur['id'] ?? null, $acteur['nom'] ?? null,
        $d['ancien_statut'] ?? null, $d['nouveau_statut'] ?? null,
        $d['ancien_assigne_id'] ?? null, $d['nouveau_assigne_id'] ?? null,
        $d['commentaire'] ?? null, ($d['visible_declarant'] ?? true) ? 'true' : 'false',
    ]);
}

/**
 * À appeler juste après l'INSERT d'une tâche par l'API web historique : renseigne les
 * identifiants (déclarant, assigné, matériel), les échéances SLA et écrit l'évènement de création.
 */
function tache_apres_creation(PDO $pdo, int $id, array $user, string $source = 'web'): void
{
    $t = $pdo->prepare("SELECT priorite, assigne_a, materiel_concerne FROM t_taches WHERE id = ?");
    $t->execute([$id]);
    $row = $t->fetch();
    [$pec, $res] = tache_echeances($pdo, (string) $row['priorite']);
    $assigneId = tache_utilisateur_id_par_nom($pdo, (string) $row['assigne_a']);
    $file = tache_file_commune($pdo);
    // Assignée d'emblée à une vraie personne (et non à la file) = prise en compte immédiate.
    $priseEnCompte = $assigneId !== null && (!$file || $assigneId !== $file['id']);

    $pdo->prepare("
        UPDATE t_taches SET declarant_id = ?, assigne_id = ?, materiel_id = ?, source = ?,
            echeance_prise_en_charge = ?, echeance_resolution = ?,
            date_prise_en_charge = CASE WHEN ? THEN NOW() ELSE NULL END
        WHERE id = ?
    ")->execute([$user['id'], $assigneId, tache_materiel_id_depuis_libelle($pdo, $row['materiel_concerne']),
        $source, $pec, $res, $priseEnCompte ? 'true' : 'false', $id]);

    $s = $pdo->prepare("SELECT statut FROM t_taches WHERE id = ?");
    $s->execute([$id]);
    tache_evenement($pdo, $id, 'creation', $user, ['nouveau_statut' => $s->fetchColumn(), 'nouveau_assigne_id' => $assigneId]);
}

/**
 * À appeler juste après un UPDATE de l'API web historique. $avant = ligne t_taches lue avant
 * la modification. Synchronise assigne_id / dates de cycle de vie et écrit les évènements.
 */
function tache_apres_modification(PDO $pdo, int $id, array $avant, array $user): void
{
    $st = $pdo->prepare("SELECT statut, assigne_a, materiel_concerne, date_prise_en_charge FROM t_taches WHERE id = ?");
    $st->execute([$id]);
    $apres = $st->fetch();
    $nouvelAssigne = tache_utilisateur_id_par_nom($pdo, (string) $apres['assigne_a']);
    $file = tache_file_commune($pdo);
    $personne = $nouvelAssigne !== null && (!$file || $nouvelAssigne !== $file['id']);
    $statutChange = $avant['statut'] !== $apres['statut'];
    $assigneChange = ($avant['assigne_id'] ?? null) !== $nouvelAssigne;

    $set = ['assigne_id = ?', 'materiel_id = ?', 'version = version + 1'];
    $args = [$nouvelAssigne, tache_materiel_id_depuis_libelle($pdo, $apres['materiel_concerne'])];
    if (($personne || $apres['statut'] === 'En cours') && $avant['date_prise_en_charge'] === null) {
        $set[] = 'date_prise_en_charge = NOW()';
    }
    if ($statutChange && $apres['statut'] === 'Terminé') {
        $set[] = 'date_resolution = NOW()';
    } elseif ($statutChange && $avant['statut'] === 'Terminé') {
        $set[] = 'date_resolution = NULL';
    }
    $args[] = $id;
    $pdo->prepare('UPDATE t_taches SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);

    if ($assigneChange) {
        tache_evenement($pdo, $id, 'affectation', $user, [
            'ancien_assigne_id' => $avant['assigne_id'] ?? null, 'nouveau_assigne_id' => $nouvelAssigne]);
    }
    if ($statutChange) {
        tache_evenement($pdo, $id, 'statut', $user, [
            'ancien_statut' => $avant['statut'], 'nouveau_statut' => $apres['statut']]);
    }
}

function tache_peut_voir(array $user, array $tache): bool
{
    if (has_permission($user, 'tache.voir_toutes')) {
        return true;
    }
    return has_permission($user, 'tache.voir_siennes')
        && ((int) $tache['declarant_id'] === (int) $user['id'] || (int) $tache['assigne_id'] === (int) $user['id']);
}

function tache_erreur(int $code, string $message): array
{
    return ['ok' => false, 'code' => $code, 'error' => $message];
}

/**
 * Applique une transition à une panne, de façon atomique et sûre en cas d'accès concurrents.
 *
 * Actions et paramètres :
 *  - 'prendre'    : l'agent IT prend la panne (aucun paramètre). Une seule personne peut gagner.
 *  - 'affecter'   : ['assigne_id' => int]  (l'id de la file commune la remet en file d'attente)
 *  - 'statut'     : ['statut' => ..., 'motif_attente' => ..., 'date_relance' => 'AAAA-MM-JJ']
 *  - 'commenter'  : ['commentaire' => string, 'visible_declarant' => bool]
 * Paramètre optionnel 'version' : si fourni et différent de la version en base → 409 (conflit).
 *
 * @return array{ok:true,tache:array,evenement:string}|array{ok:false,code:int,error:string}
 */
function tache_transition(PDO $pdo, int $id, array $user, string $action, array $p = []): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM t_taches WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t || !tache_peut_voir($user, $t)) {
            $pdo->rollBack();
            return tache_erreur(404, 'Panne introuvable.');
        }
        if (isset($p['version']) && (int) $p['version'] !== (int) $t['version']) {
            $pdo->rollBack();
            return tache_erreur(409, 'La panne a été modifiée entre-temps. Rechargez-la.');
        }
        $final = in_array($t['statut'], TACHE_STATUTS_FINAUX, true);
        $file = tache_file_commune($pdo);
        $enFile = $t['assigne_id'] === null || ($file && (int) $t['assigne_id'] === $file['id']);
        $set = []; $args = []; $evt = null; $evtData = [];

        switch ($action) {
            case 'prendre':
                if (!has_permission($user, 'tache.prendre')) {
                    $pdo->rollBack();
                    return tache_erreur(403, 'Vous ne pouvez pas prendre en charge une panne.');
                }
                if ($final) {
                    $pdo->rollBack();
                    return tache_erreur(409, 'Cette panne est déjà clôturée.');
                }
                if (!$enFile && (int) $t['assigne_id'] !== (int) $user['id']) {
                    $n = $pdo->prepare("SELECT nom FROM t_utilisateurs WHERE id = ?");
                    $n->execute([$t['assigne_id']]);
                    $pdo->rollBack();
                    return tache_erreur(409, 'Déjà prise en charge par ' . ($n->fetchColumn() ?: 'un autre agent') . '.');
                }
                $set = ['assigne_id = ?', 'assigne_a = ?', "statut = 'En cours'", 'date_prise_en_charge = COALESCE(date_prise_en_charge, NOW())'];
                $args = [$user['id'], $user['nom']];
                $evt = 'prise_en_charge';
                $evtData = ['ancien_statut' => $t['statut'], 'nouveau_statut' => 'En cours',
                            'ancien_assigne_id' => $t['assigne_id'], 'nouveau_assigne_id' => $user['id']];
                break;

            case 'affecter':
                if (!has_permission($user, 'tache.affecter')) {
                    $pdo->rollBack();
                    return tache_erreur(403, 'Vous ne pouvez pas affecter cette panne.');
                }
                if ($final) {
                    $pdo->rollBack();
                    return tache_erreur(409, 'Cette panne est déjà clôturée.');
                }
                $cible = (int) ($p['assigne_id'] ?? 0);
                $c = $pdo->prepare("SELECT id, nom, est_file_commune FROM t_utilisateurs WHERE id = ? AND actif");
                $c->execute([$cible]);
                $c = $c->fetch();
                if (!$c) {
                    $pdo->rollBack();
                    return tache_erreur(422, 'Agent destinataire introuvable ou inactif.');
                }
                if ($c['est_file_commune']) {
                    $set = ['assigne_id = ?', 'assigne_a = ?', "statut = 'À faire'"];
                } else {
                    $perm = charger_permissions($pdo, (string) $pdo->query("SELECT role FROM t_utilisateurs WHERE id = " . (int) $c['id'])->fetchColumn());
                    if (!in_array('tache.prendre', $perm, true)) {
                        $pdo->rollBack();
                        return tache_erreur(422, 'Cet utilisateur ne peut pas traiter de pannes.');
                    }
                    $set = ['assigne_id = ?', 'assigne_a = ?', 'date_prise_en_charge = COALESCE(date_prise_en_charge, NOW())'];
                }
                $args = [$c['id'], $c['nom']];
                $evt = 'affectation';
                $evtData = ['ancien_assigne_id' => $t['assigne_id'], 'nouveau_assigne_id' => $c['id'],
                            'commentaire' => $p['commentaire'] ?? null];
                break;

            case 'statut':
                $nouveau = (string) ($p['statut'] ?? '');
                if (!in_array($nouveau, ['À faire', 'En cours', 'En attente', 'Terminé', 'Annulé'], true)) {
                    $pdo->rollBack();
                    return tache_erreur(422, 'Statut invalide.');
                }
                $estAssigne = (int) $t['assigne_id'] === (int) $user['id'];
                $estDeclarantAnnule = $nouveau === 'Annulé' && (int) $t['declarant_id'] === (int) $user['id']
                    && $t['date_prise_en_charge'] === null && $t['statut'] === 'À faire' && has_permission($user, 'tache.declarer');
                $autorise = has_permission($user, 'tache.modifier_toutes')
                    || (has_permission($user, 'tache.modifier_siennes') && $estAssigne)
                    || $estDeclarantAnnule;
                if (!$autorise) {
                    $pdo->rollBack();
                    return tache_erreur(403, 'Vous ne pouvez pas changer le statut de cette panne.');
                }
                if ($nouveau === 'En attente' && (trim((string) ($p['motif_attente'] ?? '')) === '' || empty($p['date_relance']))) {
                    $pdo->rollBack();
                    return tache_erreur(422, 'Le motif d\'attente et la date de relance sont requis pour « En attente ».');
                }
                if ($nouveau === $t['statut']) {
                    $pdo->rollBack();
                    return tache_erreur(409, 'La panne a déjà ce statut.');
                }
                $set = ['statut = ?'];
                $args = [$nouveau];
                if ($nouveau === 'En attente') {
                    $set[] = 'motif_attente = ?'; $args[] = trim($p['motif_attente']);
                    $set[] = 'date_relance = ?';  $args[] = $p['date_relance'];
                }
                if ($nouveau === 'Terminé') {
                    $set[] = 'date_resolution = NOW()';
                } elseif ($t['statut'] === 'Terminé') {
                    $set[] = 'date_resolution = NULL';
                }
                if ($nouveau === 'En cours') {
                    $set[] = 'date_prise_en_charge = COALESCE(date_prise_en_charge, NOW())';
                }
                $evt = 'statut';
                $evtData = ['ancien_statut' => $t['statut'], 'nouveau_statut' => $nouveau,
                            'commentaire' => $nouveau === 'En attente' ? trim($p['motif_attente']) : ($p['commentaire'] ?? null)];
                break;

            case 'commenter':
                $texte = trim((string) ($p['commentaire'] ?? ''));
                if ($texte === '' || mb_strlen($texte) > 2000) {
                    $pdo->rollBack();
                    return tache_erreur(422, 'Commentaire vide ou trop long (2000 caractères maximum).');
                }
                $evt = 'commentaire';
                $evtData = ['commentaire' => $texte, 'visible_declarant' => (bool) ($p['visible_declarant'] ?? true)];
                // Un commentaire ne change pas la tâche, mais incrémente quand même la version.
                break;

            default:
                $pdo->rollBack();
                return tache_erreur(400, 'Action inconnue.');
        }

        $set[] = 'version = version + 1';
        $args[] = $id;
        $pdo->prepare('UPDATE t_taches SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        tache_evenement($pdo, $id, $evt, $user, $evtData);

        $st = $pdo->prepare("SELECT * FROM t_taches WHERE id = ?");
        $st->execute([$id]);
        $maj = $st->fetch();
        $pdo->commit();
        return ['ok' => true, 'tache' => $maj, 'evenement' => $evt];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/**
 * Déclare une panne (API mobile). Retourne l'id et si la panne vient d'être créée.
 * Idempotent grâce à client_uuid : rejouer le même envoi renvoie la panne existante.
 *
 * $d : titre, description, priorite, service_nom, materiel_id (?int), motif_cause, client_uuid,
 *      assigne_id (?int, null = file Service IT), date_limite (?string), source
 * @return array{id:int,cree:bool}
 */
function tache_declarer(PDO $pdo, array $user, array $d): array
{
    if (!empty($d['client_uuid'])) {
        $st = $pdo->prepare("SELECT id, declarant_id FROM t_taches WHERE client_uuid = ?");
        $st->execute([$d['client_uuid']]);
        if ($ex = $st->fetch()) {
            if ((int) $ex['declarant_id'] !== (int) $user['id']) {
                api_fail(409, 'uuid_deja_utilise', 'Cet identifiant client est déjà utilisé.');
            }
            return ['id' => (int) $ex['id'], 'cree' => false];
        }
    }
    $file = tache_file_commune($pdo);
    if (!$file) {
        api_fail(500, 'file_introuvable', 'File « Service IT » introuvable (migrations non appliquées ?).');
    }
    $assigneId = $d['assigne_id'] ?? $file['id'];
    $st = $pdo->prepare("SELECT id, nom, est_file_commune FROM t_utilisateurs WHERE id = ? AND actif");
    $st->execute([$assigneId]);
    $cible = $st->fetch();
    if (!$cible) {
        api_fail(422, 'champ_invalide', 'Agent destinataire introuvable.', ['champ' => 'assigne_id']);
    }
    $personne = !$cible['est_file_commune'];

    $libelle = null;
    if (!empty($d['materiel_id'])) {
        $m = $pdo->prepare("SELECT type, marque, modele, num_serie FROM t_materiel WHERE id = ?");
        $m->execute([$d['materiel_id']]);
        $m = $m->fetch();
        // Même format que le formulaire web : « type marque modèle (n° série) ».
        $libelle = $m ? trim($m['type'] . ' ' . $m['marque'] . ' ' . $m['modele']) . ' (' . $m['num_serie'] . ')' : null;
    }
    [$pec, $res] = tache_echeances($pdo, $d['priorite']);
    $dateLimite = $d['date_limite'] ?? ($res ? substr((string) $res, 0, 10) : date('Y-m-d'));

    try {
        $st = $pdo->prepare("
            INSERT INTO t_taches (titre, motif_cause, description, priorite, statut, assigne_a, assigne_id, declarant_id,
                materiel_concerne, materiel_id, service_concerne, date_limite, source, client_uuid,
                echeance_prise_en_charge, echeance_resolution, date_prise_en_charge, created_by_login, updated_by_login)
            VALUES (?, ?, ?, ?, 'À faire', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? THEN NOW() ELSE NULL END, ?, ?)
            RETURNING id
        ");
        $st->execute([$d['titre'], $d['motif_cause'] ?? null, $d['description'] ?? null, $d['priorite'], $cible['nom'], $cible['id'],
            $user['id'], $libelle, $d['materiel_id'] ?? null, $d['service_nom'], $dateLimite, $d['source'] ?? 'mobile',
            $d['client_uuid'] ?? null, $pec, $res, $personne ? 'true' : 'false', $user['login'], $user['login']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' && !empty($d['client_uuid'])) {
            // Course : une requête identique vient d'aboutir.
            $st = $pdo->prepare("SELECT id FROM t_taches WHERE client_uuid = ?");
            $st->execute([$d['client_uuid']]);
            return ['id' => (int) $st->fetchColumn(), 'cree' => false];
        }
        throw $e;
    }
    $id = (int) $st->fetchColumn();
    tache_evenement($pdo, $id, 'creation', $user, ['nouveau_statut' => 'À faire', 'nouveau_assigne_id' => $cible['id']]);
    return ['id' => $id, 'cree' => true];
}
