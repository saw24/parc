// Passerelle de migration : les appels jQuery historiques du site vers backend/api/<entité>.php?action=… sont
// redirigés vers l'API v1 (les mêmes routes que l'application mobile), et la réponse est remise sous la forme que
// le reste de script.js attend. Une entité est migrée en ajoutant ses gestionnaires dans MIGRES ; les autres appels
// (auth, tableau de bord, paramètres) continuent d'utiliser leurs endpoints d'origine.
(function ($) {
    const V1 = '../backend/api/v1/index.php/';

    class Echec extends Error { constructor(status, message) { super(message); this.status = status; } }

    async function v1(methode, route, corps, entetes) {
        const r = await fetch(V1 + route, {
            method: methode, headers: Object.assign({ 'Content-Type': 'application/json' }, entetes),
            body: corps === undefined ? undefined : JSON.stringify(corps)
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) { throw new Echec(r.status, (json.error && json.error.message) || 'Erreur ' + r.status); }
        return json;
    }

    /** Parcourt toutes les pages d'une liste v1 (curseur). */
    async function toutes(route, requete, entetes) {
        const out = [];
        let curseur = null;
        do {
            const q = new URLSearchParams(Object.assign({ limit: '100' }, requete, curseur ? { cursor: curseur } : {}));
            const r = await v1('GET', route + '?' + q, undefined, entetes);
            out.push(...r.data);
            curseur = r.meta && r.meta.next_cursor;
        } while (curseur && out.length < 5000);
        return out;
    }

    /** Appel de l'ancien endpoint (repli pour les cas que la v1 ne couvre pas, ex. modifier son propre mot de passe). */
    async function ancien(fichier, action, d, entetes) {
        const r = await fetch('../backend/api/' + fichier + '?action=' + action, {
            method: 'POST', headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, entetes), body: new URLSearchParams(d)
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) { throw new Echec(r.status, json.error || 'Erreur ' + r.status); }
        return json;
    }

    const vide = (v) => v === undefined || v === null || String(v).trim() === '';
    const entier = (v) => (vide(v) ? null : parseInt(v, 10));
    const moi = () => parseInt(localStorage.getItem('user_id') || '0', 10);

    /** Utilisateur v1 → ligne au format de l'ancien endpoint. */
    const ligneUtilisateur = (u) => ({
        id: u.id, nom: u.nom, login: u.login, email: u.email, telephone: u.telephone, role: u.role,
        service_id: u.service ? u.service.id : null, service: u.service ? u.service.nom : null,
        actif: u.actif === undefined ? true : u.actif, connexion_autorisee: u.connexion_autorisee === undefined ? true : u.connexion_autorisee,
        est_file_commune: !!u.compte_technique,
        efficacite_pct: u.efficacite ? u.efficacite.pct : undefined, efficacite_zone: u.efficacite ? u.efficacite.zone : undefined
    });

    // ---- Tâches : l'ancien formulaire envoie un enregistrement complet (noms en texte) ; la v1 attend des identifiants et
    // des opérations distinctes (modifier, affecter, changer le statut) qui appliquent le workflow, la version et les notifications.
    const jour = (v) => (vide(v) ? null : String(v).slice(0, 10));
    const nomEgal = (a, b) => String(a || '').trim() === String(b || '').trim();

    const ligneTache = (t) => ({
        id: t.id, reference: t.reference, titre: t.titre, motif_cause: t.motif_cause || '', description: t.description || '',
        priorite: t.priorite, statut: t.statut, assigne_a: t.assigne ? t.assigne.nom : '', assigne_id: t.assigne ? t.assigne.id : null,
        materiel_concerne: t.materiel ? t.materiel.libelle : '', materiel_id: t.materiel ? t.materiel.id : null,
        service_concerne: t.service || '', motif_attente: t.motif_attente || '', date_relance: t.date_relance, date_limite: t.date_limite,
        actions: t.actions_texte || '', date_creation: t.dates.creation, date_modification: t.updated_at,
        date_prise_en_charge: t.dates.prise_en_charge, date_resolution: t.dates.resolution,
        declarant_id: t.declarant ? t.declarant.id : null, version: t.version, en_file: t.en_file,
        retard_prise_en_charge: t.sla.retard_prise_en_charge, retard_resolution: t.sla.retard_resolution
    });

    async function idService(nom, h) {
        if (vide(nom)) { return null; }
        const l = (await v1('GET', 'referentiels', undefined, h)).data.services;
        const s = l.find(x => nomEgal(x.nom, nom));
        if (!s) { throw new Echec(422, 'Service inconnu : ' + nom); }
        return s.id;
    }
    async function idAgent(nom, h) {
        if (vide(nom)) { return null; }
        const l = (await v1('GET', 'agents', undefined, h)).data;
        const a = l.find(x => nomEgal(x.nom, nom));
        if (!a) { throw new Echec(422, 'Agent inconnu : ' + nom); }
        return a.id;
    }
    async function idMateriel(libelle, h) {
        if (vide(libelle)) { return null; }
        try {
            const m = (await toutes('materiel', {}, h)).find(x => nomEgal(x.libelle, libelle));
            return m ? m.id : undefined;   // undefined = texte libre sans fiche correspondante : on n'y touche pas
        } catch (e) { return undefined; }
    }

    const MOI = () => localStorage.getItem('user_name') || '';

    const TACHES = {
        read: async (d, h) => (await toutes('taches', {}, h)).map(ligneTache),
        create: async (d, h) => {
            const corps = { titre: d.titre, description: d.description || null, motif_cause: d.motif_cause || null,
                priorite: d.priorite, service_id: await idService(d.service_concerne, h) };
            const mid = await idMateriel(d.materiel_concerne, h);
            if (mid) { corps.materiel_id = mid; }
            const reserve = {};
            if (!vide(d.assigne_a)) { try { reserve.assigne_id = await idAgent(d.assigne_a, h); } catch (e) { /* sans droit d'affecter : file Service IT */ } }
            if (!vide(d.date_limite)) { reserve.date_limite = jour(d.date_limite); }
            let r;
            try { r = await v1('POST', 'taches', Object.assign({}, corps, reserve), h); }
            catch (e) {
                // Un simple déclarant n'a pas le droit de choisir l'assigné ni l'échéance : sa panne rejoint la file.
                if (e instanceof Echec && e.status === 403 && Object.keys(reserve).length) { r = await v1('POST', 'taches', corps, h); }
                else { throw e; }
            }
            let t = r.data;
            if (!vide(d.statut) && d.statut !== t.statut && t.actions.changer_statut) {
                t = (await v1('POST', 'taches/' + t.id + '/statut', { statut: d.statut, version: t.version,
                    motif_attente: d.motif_attente || undefined, date_relance: jour(d.date_relance) || undefined }, h)).data;
            }
            if (!vide(d.actions) && t.actions.modifier) { await v1('PATCH', 'taches/' + t.id, { actions_texte: d.actions, version: t.version }, h); }
            return ok('Tâche créée avec succès.');
        },
        update: async (d, h) => {
            let t = (await v1('GET', 'taches/' + d.id, undefined, h)).data;
            // 1. Champs de la fiche
            const patch = {};
            if (!nomEgal(d.titre, t.titre)) { patch.titre = d.titre; }
            if (!nomEgal(d.description, t.description)) { patch.description = d.description || null; }
            if (d.priorite && d.priorite !== t.priorite) { patch.priorite = d.priorite; }
            if (!nomEgal(d.motif_cause, t.motif_cause)) { patch.motif_cause = d.motif_cause || null; }
            if (!vide(d.date_limite) && jour(d.date_limite) !== t.date_limite) { patch.date_limite = jour(d.date_limite); }
            if ('actions' in d && !nomEgal(d.actions, t.actions_texte)) { patch.actions_texte = d.actions || null; }
            if (!vide(d.service_concerne) && !nomEgal(d.service_concerne, t.service)) { patch.service_id = await idService(d.service_concerne, h); }
            if (!nomEgal(d.materiel_concerne, t.materiel ? t.materiel.libelle : '')) {
                const mid = vide(d.materiel_concerne) ? null : await idMateriel(d.materiel_concerne, h);
                if (mid !== undefined) { patch.materiel_id = mid; }
            }
            if (Object.keys(patch).length) {
                patch.version = t.version;
                t = (await v1('PATCH', 'taches/' + d.id, patch, h)).data;
            }
            // 2. Assignation (la prise en charge d'une panne de la file passe par l'opération atomique « prendre »)
            const aNom = t.assigne ? t.assigne.nom : '';
            if (!vide(d.assigne_a) && !nomEgal(d.assigne_a, aNom)) {
                const prise = nomEgal(d.assigne_a, MOI()) && t.actions.prendre && d.statut === 'En cours';
                if (prise) { t = (await v1('POST', 'taches/' + d.id + '/prendre', {}, h)).data; }
                else { t = (await v1('POST', 'taches/' + d.id + '/affecter', { assigne_id: await idAgent(d.assigne_a, h), version: t.version }, h)).data; }
            }
            // 3. Statut
            if (!vide(d.statut) && d.statut !== t.statut) {
                t = (await v1('POST', 'taches/' + d.id + '/statut', { statut: d.statut, version: t.version,
                    motif_attente: d.motif_attente || undefined, date_relance: jour(d.date_relance) || undefined }, h)).data;
            }
            return ok('Tâche mise à jour avec succès.');
        },
        delete: async (d, h) => { await v1('DELETE', 'taches/' + d.id, undefined, h); return ok('Tâche supprimée avec succès.'); }
    };

    // ---- Matériel
    const ligneMateriel = (m) => ({
        id: m.id, type: m.type, marque: m.marque, modele: m.modele, num_serie: m.num_serie, date_achat: m.date_achat, statut: m.statut,
        service_affecte: m.service ? m.service.nom : '', service_id: m.service ? m.service.id : null,
        utilisateur_assigne: m.utilisateur ? m.utilisateur.nom : '', utilisateur_id: m.utilisateur ? m.utilisateur.id : null,
        date_derniere_affect: m.date_derniere_affect, date_creation: m.created_at, date_modification: m.updated_at,
        pannes_ouvertes: m.pannes_ouvertes, libelle: m.libelle
    });

    /** Service / agent nommé → id ; null si vide ; undefined si le nom ne correspond à aucune fiche (texte libre historique). */
    async function idParNom(liste, nom) {
        if (vide(nom)) { return null; }
        const x = liste.find(e => nomEgal(e.nom, nom));
        return x ? x.id : undefined;
    }
    async function corpsMateriel(d, h, complet) {
        const corps = { type: d.type, statut: d.statut, marque: d.marque || null, modele: d.modele || null, num_serie: d.num_serie || null,
            date_achat: jour(d.date_achat), date_derniere_affect: jour(d.date_derniere_affect) };
        const sid = await idParNom((await v1('GET', 'referentiels', undefined, h)).data.services, d.service);
        let uid;
        try { uid = await idParNom((await v1('GET', 'utilisateurs', undefined, h)).data, d.utilisateur); } catch (e) { uid = undefined; }
        if (sid !== undefined) { corps.service_id = sid; }
        if (uid !== undefined) { corps.utilisateur_id = uid; }
        return corps;
    }

    const MATERIEL = {
        read: async (d, h) => (await toutes('materiel', {}, h)).map(ligneMateriel),
        historique_affectations: async (d, h) => (await v1('GET', 'materiel/' + d.id + '/affectations', undefined, h)).data.map(a => ({
            id: a.id, utilisateur_assigne: a.utilisateur, service_affecte: a.service, date_debut: a.debut, date_fin: a.fin, changed_by: a.modifie_par || '' })),
        create: async (d, h) => { await v1('POST', 'materiel', await corpsMateriel(d, h), h); return ok('Matériel ajouté avec succès.'); },
        update: async (d, h) => { await v1('PATCH', 'materiel/' + d.id, await corpsMateriel(d, h), h); return ok('Matériel mis à jour avec succès.'); },
        delete: async (d, h) => { await v1('DELETE', 'materiel/' + d.id, undefined, h); return ok('Matériel supprimé avec succès.'); }
    };

    const ok = (message, extra) => Object.assign({ success: true, message }, extra);

    // Gestionnaires par « fichier.php » puis par action : (donnees, entetes) => réponse au format historique.
    const MIGRES = {
        'services.php': {
            read: async (d, h) => (await v1('GET', 'services', undefined, h)).data,
            create: async (d, h) => { await v1('POST', 'services', { nom: d.nom, description: d.description || null }, h); return ok('Service créé avec succès.'); },
            update: async (d, h) => { await v1('PATCH', 'services/' + d.id, { nom: d.nom, description: d.description || null }, h); return ok('Service mis à jour avec succès.'); },
            delete: async (d, h) => { await v1('DELETE', 'services/' + d.id, undefined, h); return ok('Service supprimé avec succès.'); }
        },
        'taches.php': TACHES,
        'materiel.php': MATERIEL,
        'utilisateurs.php': {
            read: async (d, h) => {
                if (!vide(d.id)) {
                    try { return [ligneUtilisateur((await v1('GET', 'utilisateurs/' + d.id, undefined, h)).data)]; }
                    catch (e) {
                        if (!(e instanceof Echec) || e.status !== 403 || +d.id !== moi()) { throw e; }
                        return [ligneUtilisateur((await v1('GET', 'me', undefined, h)).data)];   // sa propre fiche, sans droit de lister
                    }
                }
                return (await v1('GET', 'utilisateurs?detail=1&inclure_inactifs=1&inclure_file=1&efficacite=1', undefined, h)).data.map(ligneUtilisateur);
            },
            create: async (d, h) => {
                await v1('POST', 'utilisateurs', {
                    nom: d.nom, login: d.login, email: vide(d.email) ? null : d.email, telephone: vide(d.telephone) ? null : d.telephone,
                    role: d.role, mot_de_passe: d.mot_de_passe, service_id: entier(d.service_id)
                }, h);
                return ok('Utilisateur créé avec succès.');
            },
            update: async (d, h) => {
                const corps = { nom: d.nom, login: d.login, email: vide(d.email) ? null : d.email, role: d.role };
                if ('telephone' in d) { corps.telephone = vide(d.telephone) ? null : d.telephone; }
                if ('service_id' in d) { corps.service_id = entier(d.service_id); }
                if (!vide(d.mot_de_passe)) { corps.mot_de_passe = d.mot_de_passe; }
                try { await v1('PATCH', 'utilisateurs/' + d.id, corps, h); }
                catch (e) {
                    // Sans droit de gérer les comptes, on peut tout de même modifier sa propre fiche (mot de passe, téléphone).
                    if (e instanceof Echec && e.status === 403 && +d.id === moi()) { return ancien('utilisateurs.php', 'update', d, h); }
                    throw e;
                }
                return ok('Utilisateur mis à jour avec succès.');
            },
            delete: async (d, h) => { await v1('DELETE', 'utilisateurs/' + d.id, undefined, h); return ok('Utilisateur supprimé avec succès.'); }
        },
        'type_materiel.php': {
            read: async (d, h) => (await v1('GET', 'types_materiel', undefined, h)).data.map(t => ({ id: t.id, type: t.nom })),
            create: async (d, h) => { const r = await v1('POST', 'types_materiel', { nom: d.type }, h); return ok('Type créé avec succès.', { id: r.data.id }); },
            update: async (d, h) => { await v1('PATCH', 'types_materiel/' + d.id, { nom: d.type }, h); return ok('Type mis à jour avec succès.'); },
            delete: async (d, h) => { await v1('DELETE', 'types_materiel/' + d.id, undefined, h); return ok('Type supprimé avec succès.'); }
        }
    };
    window.LegacyBridge = { MIGRES, v1, toutes, ok, Echec };

    function donnees(options, original) {
        const d = original.data;
        if (d && typeof d === 'object') { return d; }
        return Object.fromEntries(new URLSearchParams(typeof d === 'string' ? d : ''));
    }

    $.ajaxTransport('+*', function (options, original) {
        const m = typeof options.url === 'string' && options.url.match(/backend\/api\/([a-z_]+\.php)\?([^#]*)/);
        if (!m || !MIGRES[m[1]]) { return; }
        const action = new URLSearchParams(m[2]).get('action') || donnees(options, original).action || '';
        const gestionnaire = MIGRES[m[1]][action];
        if (!gestionnaire) { return; }   // action non migrée : appel d'origine
        let annule = false;
        return {
            send: function (entetes, complete) {
                const h = { 'X-Session-Token': entetes['X-Session-Token'] || '', 'X-User-Id': entetes['X-User-Id'] || '' };
                const d = Object.assign({}, donnees(options, original));
                if (m[2]) { new URLSearchParams(m[2]).forEach((v, k) => { if (!(k in d)) { d[k] = v; } }); }
                gestionnaire(d, h).then(
                    rep => { if (!annule) { complete(200, 'OK', { text: JSON.stringify(rep) }); } },
                    err => {
                        if (annule) { return; }
                        const status = err instanceof Echec ? err.status : 500;
                        complete(status, 'error', { text: JSON.stringify({ success: false, error: err.message }) });
                    });
            },
            abort: function () { annule = true; }
        };
    });
})(jQuery);
