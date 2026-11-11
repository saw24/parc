// Écran « Inventaires » : campagnes de pointage du matériel (le scan QR se fait sur l'application mobile).
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const v1 = (m, r, c) => $.ajax({ url: V1 + r, type: m, dataType: 'json', contentType: 'application/json', data: c === undefined ? undefined : JSON.stringify(c) });
    const erreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';
    let courant = null, detail = null;

    function chargerRef() {
        return $.when(v1('GET', 'services'), v1('GET', 'types_materiel')).done(function (s, t) {
            $('#inv-service').html('<option value="">Tous les services</option>' + s[0].data.map(x => `<option value="${x.id}">${esc(x.nom)}</option>`).join(''));
            $('#inv-type').html('<option value="">Tous les types</option>' + t[0].data.map(x => `<option value="${esc(x.nom)}">${esc(x.nom)}</option>`).join(''));
        });
    }

    function charger() {
        $('#inv-statut').text('Chargement…');
        v1('GET', 'inventaires').done(function (r) {
            $('#inv-table tbody').html(r.data.map(i => {
                const c = i.compteurs, pct = c.attendus ? Math.round(100 * c.pointes / c.attendus) : 0;
                return `<tr>
                    <td><strong>${esc(i.titre)}</strong>${i.date_limite ? '<br><span class="field-hint">avant le ' + i.date_limite.split('-').reverse().join('/') + '</span>' : ''}</td>
                    <td>${esc([i.perimetre.service, i.perimetre.type].filter(Boolean).join(' · ') || 'Tout le parc')}</td>
                    <td>${c.pointes} / ${c.attendus} (${pct} %)</td>
                    <td>${c.deplaces} déplacé(s) · ${c.manquants} manquant(s) · ${c.hors_perimetre + c.non_references} en plus</td>
                    <td>${esc(i.statut)}</td>
                    <td class="actions">
                        <button class="btn-action inv-ouvrir" data-id="${i.id}" title="Ouvrir"><i class="fas fa-eye"></i></button>
                        <button class="btn-action inv-del" data-id="${i.id}" title="Supprimer"><i class="fas fa-trash-alt"></i></button>
                    </td></tr>`;
            }).join('') || '<tr><td colspan="6">Aucune campagne d\'inventaire.</td></tr>');
            $('#inv-statut').text('');
        }).fail(xhr => $('#inv-statut').text(xhr.status === 403 ? 'Vous n\'avez pas accès aux inventaires.' : erreur(xhr)));
    }

    function ouvrirDetail(id) {
        courant = id;
        v1('GET', 'inventaires/' + id).done(function (r) { detail = r.data; dessinerDetail(); }).fail(xhr => alert(erreur(xhr)));
    }

    function dessinerDetail() {
        const c = detail.compteurs, f = $('#inv-filtre').val();
        $('#inv-detail').show();
        $('#inv-detail-titre').text(detail.titre + (detail.statut === 'Clos' ? ' (clos)' : ''));
        $('#inv-detail-resume').text(`${c.pointes}/${c.attendus} pointés — présents ${c.presents}, déplacés ${c.deplaces}, manquants ${c.manquants}, à pointer ${c.a_pointer}; hors périmètre ${c.hors_perimetre}, non référencés ${c.non_references}.`);
        $('#inv-cloturer').toggle(detail.statut === 'Ouvert');
        $('#inv-lignes tbody').html(detail.lignes.filter(l => !f || l.statut === f).map(l => `
            <tr>
                <td>${l.materiel ? '<strong>' + esc(l.materiel.libelle) + '</strong>' : esc(l.code_scanne)}${l.hors_perimetre ? ' <span class="field-hint">(hors périmètre)</span>' : ''}</td>
                <td>${esc(l.materiel ? [l.materiel.service, l.materiel.utilisateur].filter(Boolean).join(' · ') || '—' : '—')}</td>
                <td>${esc(l.statut)}</td><td>${esc(l.observation || '')}</td><td>${esc(l.pointe_par || '—')}</td>
                <td class="actions">${detail.statut === 'Ouvert' && l.materiel ? ['Présent', 'Déplacé', 'Manquant'].map(s =>
                    `<button class="btn-action inv-point" data-mid="${l.materiel.id}" data-statut="${s}" title="${s}"><i class="fas ${s === 'Présent' ? 'fa-check' : s === 'Déplacé' ? 'fa-people-carry' : 'fa-question'}"></i></button>`).join('') : ''}</td>
            </tr>`).join('') || '<tr><td colspan="6">Aucune ligne.</td></tr>');
    }

    $('.sidebar-menu a[href="#inventaires"]').on('click', function () { $('#inv-detail').hide(); chargerRef().always(charger); });
    $('#inv-nouveau').on('click', () => { $('#inv-form')[0].reset(); $('#inv-form').show(); });
    $('#inv-annuler').on('click', () => $('#inv-form').hide());
    $('#inv-filtre').on('change', () => detail && dessinerDetail());

    $('#inv-form').on('submit', function (e) {
        e.preventDefault();
        v1('POST', 'inventaires', {
            titre: $('#inv-titre').val().trim(), description: $('#inv-description').val().trim() || null, date_limite: $('#inv-date-limite').val() || null,
            service_id: $('#inv-service').val() ? Number($('#inv-service').val()) : null, type_materiel: $('#inv-type').val() || null
        }).done(r => { $('#inv-form').hide(); charger(); ouvrirDetail(r.data.id); }).fail(xhr => alert(erreur(xhr)));
    });

    $(document).on('click', '.inv-ouvrir', function () { ouvrirDetail($(this).data('id')); });
    $(document).on('click', '.inv-del', function () {
        if (!confirm('Supprimer cette campagne et tous ses pointages ?')) { return; }
        v1('DELETE', 'inventaires/' + $(this).data('id')).done(() => { $('#inv-detail').hide(); charger(); }).fail(xhr => alert(erreur(xhr)));
    });
    $(document).on('click', '.inv-point', function () {
        const statut = $(this).data('statut'), corps = { materiel_id: Number($(this).data('mid')), statut };
        if (statut === 'Déplacé') { const o = prompt('Où se trouve ce matériel ?', ''); if (!o) { return; } corps.observation = o; }
        v1('POST', 'inventaires/' + courant + '/pointer', corps).done(() => ouvrirDetail(courant)).fail(xhr => alert(erreur(xhr)));
    });
    $('#inv-cloturer').on('click', function () {
        const reste = detail.compteurs.a_pointer;
        if (!confirm(reste ? `${reste} matériel(s) non pointé(s) seront marqués « Manquant ». Clore la campagne ?` : 'Clore la campagne ?')) { return; }
        v1('POST', 'inventaires/' + courant + '/cloture', {}).done(() => { ouvrirDetail(courant); charger(); }).fail(xhr => alert(erreur(xhr)));
    });
    $('#inv-export').on('click', function () {
        const q = (t) => '"' + String(t == null ? '' : t).replace(/"/g, '""') + '"';
        const lignes = [['Matériel', 'N° série', 'Service', 'Utilisateur', 'Statut', 'Hors périmètre', 'Observation', 'Pointé par'].map(q).join(';')].concat(detail.lignes.map(l =>
            [l.materiel ? l.materiel.libelle : l.code_scanne, l.materiel ? l.materiel.num_serie : '', l.materiel ? l.materiel.service : '', l.materiel ? l.materiel.utilisateur : '',
                l.statut, l.hors_perimetre ? 'oui' : 'non', l.observation, l.pointe_par].map(q).join(';')));
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + lignes.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        a.download = 'inventaire-' + detail.id + '.csv'; a.click(); URL.revokeObjectURL(a.href);
    });
});
