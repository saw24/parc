// Écrans « Rôles et permissions » et « Passerelle SMS / push », branchés sur l'API v1 (mêmes routes que l'application
// mobile). La session du site (X-Session-Token / X-User-Id, ajoutés par le préfiltre de script.js) est acceptée par la v1.
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const estAdmin = () => localStorage.getItem('user_role') === 'Administrateur';
    let catalogue = [];      // [{groupe, permissions:[{code, libelle}]}]
    let roles = [];
    let enEdition = null;    // rôle en cours de modification (null = création)

    $('.sidebar-menu a[href="#roles"]').closest('li').toggle(estAdmin());

    function v1(methode, route, corps) {
        return $.ajax({
            url: V1 + route, type: methode, dataType: 'json', contentType: 'application/json',
            data: corps === undefined ? undefined : JSON.stringify(corps)
        });
    }
    const messageErreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';

    // ---------------------------------------------------------------- Rôles
    function chargerRoles() {
        if (!estAdmin()) { return; }
        $('#roles-statut').text('Chargement…');
        $.when(v1('GET', 'roles'), v1('GET', 'permissions')).done(function (r, c) {
            roles = r[0].data; catalogue = c[0].data;
            const libelles = {};
            catalogue.forEach(g => g.permissions.forEach(p => { libelles[p.code] = p.libelle; }));
            $('#roles-table tbody').html(roles.map(r => `
                <tr>
                    <td><strong>${esc(r.nom)}</strong>${r.systeme ? ' <span class="field-hint">(système)</span>' : ''}</td>
                    <td>${esc(r.description)}</td>
                    <td>${r.nb_utilisateurs}</td>
                    <td>${r.systeme ? 'Toutes' : r.permissions.length + ' / ' + Object.keys(libelles).length}</td>
                    <td class="actions">${r.systeme ? '' : `
                        <button class="btn-action btn-edit" data-id="${r.id}" title="Modifier"><i class="fas fa-pencil-alt"></i></button>
                        <button class="btn-action btn-delete" data-id="${r.id}" title="Supprimer"><i class="fas fa-trash-alt"></i></button>`}</td>
                </tr>`).join(''));
            $('#roles-statut').text('');
        }).fail(xhr => $('#roles-statut').text(messageErreur(xhr)));
    }

    function ouvrirEditeur(role) {
        enEdition = role;
        $('#role-modal-titre').text(role ? 'Modifier le rôle' : 'Nouveau rôle');
        $('#role-nom').val(role ? role.nom : '');
        $('#role-description').val(role ? role.description || '' : '');
        const cochees = new Set(role ? role.permissions : []);
        $('#role-permissions').html(catalogue.map(g => `
            <fieldset style="margin:12px 0;border:1px solid var(--color-border,#e2e8f0);border-radius:8px;padding:8px 12px">
                <legend><strong>${esc(g.groupe)}</strong></legend>
                ${g.permissions.map(p => `<label style="display:block;margin:4px 0"><input type="checkbox" value="${esc(p.code)}" ${cochees.has(p.code) ? 'checked' : ''}> ${esc(p.libelle)}</label>`).join('')}
            </fieldset>`).join(''));
        $('#role-erreur').text('');
        $('#role-modal').css('display', 'flex');
    }
    const fermerEditeur = () => $('#role-modal').hide();

    $('#role-nouveau').on('click', () => ouvrirEditeur(null));
    $('#role-fermer, #role-annuler').on('click', (e) => { e.preventDefault(); fermerEditeur(); });
    $('#roles-table').on('click', '.btn-edit', function () { ouvrirEditeur(roles.find(r => r.id === +$(this).data('id'))); });
    $('#roles-table').on('click', '.btn-delete', function () {
        const r = roles.find(x => x.id === +$(this).data('id'));
        if (!confirm(`Supprimer le rôle « ${r.nom} » ?`)) { return; }
        v1('DELETE', 'roles/' + r.id).done(chargerRoles).fail(xhr => alert(messageErreur(xhr)));
    });
    $('#role-enregistrer').on('click', function (e) {
        e.preventDefault();
        const corps = {
            nom: $('#role-nom').val().trim(), description: $('#role-description').val().trim() || null,
            permissions: $('#role-permissions input:checked').map((_, i) => i.value).get()
        };
        if (!corps.nom) { $('#role-erreur').text('Le nom est obligatoire.'); return; }
        const req = enEdition ? v1('PATCH', 'roles/' + enEdition.id, corps) : v1('POST', 'roles', corps);
        req.done(() => { fermerEditeur(); chargerRoles(); }).fail(xhr => $('#role-erreur').text(messageErreur(xhr)));
    });
    $('.sidebar-menu a[href="#roles"]').on('click', chargerRoles);

    // ---------------------------------------------------------------- Passerelle SMS et notifications push
    const CHAMPS = ['sms_url', 'sms_expediteur', 'sms_corps', 'fcm_projet'];
    const CASES = ['sms_actif', 'fcm_actif'];
    const SECRETS = { sms_jeton: 'sms_jeton_hint', fcm_compte_service: 'fcm_compte_service_hint' };

    function chargerNotifParametres(statut) {
        if (!estAdmin()) { $('#parametres-notif-form').hide(); return; }
        v1('GET', 'parametres').done(function (r) {
            const d = r.data;
            CHAMPS.forEach(k => $('#' + k).val(d[k] || ''));
            CASES.forEach(k => $('#' + k).prop('checked', d[k] === '1'));
            Object.keys(SECRETS).forEach(k => {
                $('#' + k).val('');
                $('#' + SECRETS[k]).text(d[k + '_configure'] ? 'Une valeur est déjà enregistrée ; laissez vide pour la conserver.' : 'Aucune valeur enregistrée.');
            });
            $('#parametres-notif-status').text(statut || '');
        });
    }
    $('.sidebar-menu a[href="#parametres"]').on('click', chargerNotifParametres);

    // Import du fichier JSON du compte de service : lu dans le navigateur, enregistré avec le formulaire.
    $('#fcm_parcourir').on('click', () => $('#fcm_fichier').trigger('click'));
    $('#fcm_fichier').on('change', function () {
        const fichier = this.files && this.files[0];
        if (!fichier) return;
        const lecteur = new FileReader();
        lecteur.onload = function () {
            let json;
            try { json = JSON.parse(String(lecteur.result)); } catch (e) { json = null; }
            if (!json || !json.private_key || !json.client_email) {
                $('#fcm_fichier_nom').text('Fichier invalide : ce n\'est pas un compte de service Firebase.');
                $('#fcm_fichier').val('');
                return;
            }
            $('#fcm_compte_service').val(JSON.stringify(json));
            if (json.project_id && !$('#fcm_projet').val().trim()) $('#fcm_projet').val(json.project_id);
            $('#fcm_fichier_nom').text(fichier.name + ' chargé — cliquez sur Enregistrer.');
        };
        lecteur.readAsText(fichier);
    });

    $('#parametres-notif-form').on('submit', function (e) {
        e.preventDefault();
        const corps = {};
        CHAMPS.forEach(k => { corps[k] = $('#' + k).val(); });
        CASES.forEach(k => { corps[k] = $('#' + k).is(':checked'); });
        Object.keys(SECRETS).forEach(k => { corps[k] = $('#' + k).val(); });   // vide = conserver
        $('#parametres-notif-status').text('Enregistrement…');
        v1('PATCH', 'parametres', corps).done(() => chargerNotifParametres('Enregistré.'))
            .fail(xhr => $('#parametres-notif-status').text('Erreur : ' + messageErreur(xhr)));
    });
});
