// Écran « Types de matériel » : CRUD des types et réglage de la durée de vie (fin de vie) propre à chaque type.
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const v1 = (m, r, c) => $.ajax({ url: V1 + r, type: m, dataType: 'json', contentType: 'application/json', data: c === undefined ? undefined : JSON.stringify(c) });
    const erreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';
    const annees = (m) => { const a = m / 12; return (Number.isInteger(a) ? a : a.toFixed(1).replace('.', ',')) + ' an' + (a >= 2 ? 's' : ''); };
    let types = [], defaut = 60;

    function charger() {
        $('#type-statut').text('Chargement…');
        v1('GET', 'types_materiel').done(function (r) {
            types = r.data; defaut = (r.meta && r.meta.duree_vie_defaut_mois) || 60;
            $('#type-duree-aide').text('Par défaut : ' + annees(defaut) + ' (entre 6 mois et 20 ans).');
            $('#types-table tbody').html(types.map(t => `
                <tr>
                    <td><strong>${esc(t.nom)}</strong></td>
                    <td>${t.duree_vie_mois != null ? annees(t.duree_vie_mois) : '<span class="field-hint">' + annees(defaut) + ' (défaut)</span>'}</td>
                    <td>${t.nb_materiels}</td>
                    <td class="actions">
                        <button class="btn-action type-edit" data-id="${t.id}" title="Modifier"><i class="fas fa-pencil-alt"></i></button>
                        <button class="btn-action type-del" data-id="${t.id}" title="Supprimer"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`).join('') || '<tr><td colspan="4">Aucun type de matériel.</td></tr>');
            $('#type-statut').text('');
        }).fail(xhr => $('#type-statut').text(xhr.status === 403 ? 'Vous n\'avez pas accès aux types de matériel.' : erreur(xhr)));
    }

    function ouvrir(t) {
        $('#type-form')[0].reset();
        $('#type-id').val(t ? t.id : '');
        $('#type-modal-titre').text(t ? 'Modifier le type' : 'Nouveau type');
        if (t) { $('#type-nom').val(t.nom); $('#type-duree').val(t.duree_vie_mois != null ? t.duree_vie_mois / 12 : ''); }
        $('#type-erreur').text('');
        $('#type-modal').css('display', 'flex');
        $('#type-nom').trigger('focus');
    }
    const fermer = () => $('#type-modal').hide();

    $('.sidebar-menu a[href="#types-materiel"]').on('click', charger);
    $('#type-nouveau').on('click', () => ouvrir(null));
    $('#type-fermer, #type-annuler').on('click', (e) => { e.preventDefault(); fermer(); });
    $(document).on('click', '.type-edit', function () { ouvrir(types.find(t => t.id === Number($(this).data('id')))); });

    function enregistrer() {
        const form = $('#type-form')[0];
        if (!form.reportValidity()) { return; }
        const id = $('#type-id').val(), saisie = $('#type-duree').val().trim();
        const corps = { nom: $('#type-nom').val().trim(), duree_vie_mois: saisie === '' ? null : Math.round(Number(saisie.replace(',', '.')) * 12) };
        (id ? v1('PATCH', 'types_materiel/' + id, corps) : v1('POST', 'types_materiel', corps))
            .done(() => { fermer(); charger(); if (typeof window.loadTypeMaterielInSelects === 'function') { window.loadTypeMaterielInSelects(); } })
            .fail(xhr => $('#type-erreur').text(erreur(xhr)));
    }
    $('#type-enregistrer').on('click', function (e) { e.preventDefault(); enregistrer(); });
    $('#type-form').on('submit', function (e) { e.preventDefault(); enregistrer(); });

    $(document).on('click', '.type-del', function () {
        const t = types.find(x => x.id === Number($(this).data('id')));
        if (!confirm('Supprimer le type « ' + t.nom + ' » ?')) { return; }
        v1('DELETE', 'types_materiel/' + t.id).done(charger).fail(xhr => alert(erreur(xhr)));
    });
});
