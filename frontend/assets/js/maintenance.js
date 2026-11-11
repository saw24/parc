// Écran « Maintenance préventive » : plans récurrents qui génèrent automatiquement des tâches avant l'échéance.
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const v1 = (m, r, c) => $.ajax({ url: V1 + r, type: m, dataType: 'json', contentType: 'application/json', data: c === undefined ? undefined : JSON.stringify(c) });
    const erreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';
    const dateFr = (d) => d ? d.split('-').reverse().join('/') : '—';
    let plans = [], types = [], materiels = [];

    function chargerRef() {
        return $.when(v1('GET', 'types_materiel'), v1('GET', 'materiel?limit=200'), v1('GET', 'agents')).done(function (t, m, a) {
            types = t[0].data; materiels = m[0].data;
            $('#maint-assigne').html('<option value="">File Service IT</option>' + a[0].data.filter(x => !x.file_commune).map(x => `<option value="${x.id}">${esc(x.nom)}</option>`).join(''));
            remplirCible();
        });
    }
    function remplirCible(valeur) {
        const parType = $('#maint-cible-type').val() === 'type';
        $('#maint-cible').html(parType ? types.map(t => `<option value="${esc(t.nom)}">${esc(t.nom)}</option>`).join('')
            : materiels.map(m => `<option value="${m.id}">${esc(m.libelle)}</option>`).join(''));
        if (valeur != null) { $('#maint-cible').val(String(valeur)); }
    }

    function charger() {
        $('#maint-statut').text('Chargement…');
        v1('GET', 'maintenance/plans').done(function (r) {
            plans = r.data;
            $('#maint-table tbody').html(plans.map(p => `
                <tr>
                    <td><strong>${esc(p.titre)}</strong></td>
                    <td>${p.cible.type ? 'Type : ' + esc(p.cible.type) + ' (' + p.nb_materiels + ')' : esc(p.cible.materiel.libelle)}</td>
                    <td>Tous les ${p.periodicite.valeur} ${p.periodicite.unite}${p.periodicite.valeur > 1 && p.periodicite.unite !== 'mois' ? 's' : ''}</td>
                    <td>${dateFr(p.prochaine_echeance)}</td>
                    <td>${esc(p.assigne ? p.assigne.nom : 'File Service IT')}</td>
                    <td>${p.actif ? 'Actif' : 'Suspendu'}</td>
                    <td class="actions">
                        <button class="btn-action maint-edit" data-id="${p.id}" title="Modifier"><i class="fas fa-pencil-alt"></i></button>
                        <button class="btn-action maint-del" data-id="${p.id}" title="Supprimer"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`).join('') || '<tr><td colspan="7">Aucun plan de maintenance.</td></tr>');
            $('#maint-statut').text('');
        }).fail(xhr => $('#maint-statut').text(xhr.status === 403 ? 'Vous n\'avez pas accès à la maintenance.' : erreur(xhr)));
    }

    function ouvrir(p) {
        $('#maint-form')[0].reset();
        $('#maint-id').val(p ? p.id : '');
        $('#maint-cible-type').val(p && p.cible.materiel ? 'materiel' : 'type');
        remplirCible(p ? (p.cible.materiel ? p.cible.materiel.id : p.cible.type) : null);
        if (p) {
            $('#maint-titre').val(p.titre); $('#maint-priorite').val(p.priorite); $('#maint-valeur').val(p.periodicite.valeur);
            $('#maint-unite').val(p.periodicite.unite); $('#maint-echeance').val(p.prochaine_echeance); $('#maint-anticipation').val(p.anticipation_jours);
            $('#maint-assigne').val(p.assigne ? p.assigne.id : ''); $('#maint-actif').val(p.actif ? '1' : '0');
            $('#maint-checklist').val(p.checklist || ''); $('#maint-description').val(p.description || '');
        }
        $('#maint-form').show();
    }

    $('.sidebar-menu a[href="#maintenance"]').on('click', function () { chargerRef().always(charger); });
    $('#maint-cible-type').on('change', () => remplirCible());
    $('#maint-nouveau').on('click', () => ouvrir(null));
    $('#maint-annuler').on('click', () => $('#maint-form').hide());
    $(document).on('click', '.maint-edit', function () { ouvrir(plans.find(p => p.id === Number($(this).data('id')))); });

    $('#maint-form').on('submit', function (e) {
        e.preventDefault();
        const id = $('#maint-id').val(), parType = $('#maint-cible-type').val() === 'type';
        const corps = {
            titre: $('#maint-titre').val().trim(), priorite: $('#maint-priorite').val(),
            materiel_id: parType ? null : Number($('#maint-cible').val()), type_materiel: parType ? $('#maint-cible').val() : null,
            periodicite_valeur: Number($('#maint-valeur').val()), periodicite_unite: $('#maint-unite').val(),
            prochaine_echeance: $('#maint-echeance').val(), anticipation_jours: Number($('#maint-anticipation').val() || 0),
            assigne_id: $('#maint-assigne').val() ? Number($('#maint-assigne').val()) : null, actif: $('#maint-actif').val() === '1',
            checklist: $('#maint-checklist').val().trim() || null, description: $('#maint-description').val().trim() || null
        };
        (id ? v1('PATCH', 'maintenance/plans/' + id, corps) : v1('POST', 'maintenance/plans', corps))
            .done(() => { $('#maint-form').hide(); charger(); }).fail(xhr => alert(erreur(xhr)));
    });

    $(document).on('click', '.maint-del', function () {
        if (!confirm('Supprimer ce plan ? Les tâches déjà créées sont conservées.')) { return; }
        v1('DELETE', 'maintenance/plans/' + $(this).data('id')).done(charger).fail(xhr => alert(erreur(xhr)));
    });

    $('#maint-generer').on('click', function () {
        v1('POST', 'maintenance/generer', {}).done(r => { $('#maint-statut').text(`${r.data.taches} tâche(s) créée(s) pour ${r.data.plans} plan(s).`); charger(); })
            .fail(xhr => alert(erreur(xhr)));
    });
});
