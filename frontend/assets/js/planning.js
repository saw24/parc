// Écran « Planning » : calendrier mensuel des échéances de pannes, maintenances préventives, relances et fins de garantie.
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const v1 = (r) => $.ajax({ url: V1 + r, type: 'GET', dataType: 'json' });
    const erreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';
    const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const MOIS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    const LIBELLES = { echeance: 'Échéance', preventif: 'Préventif', plan: 'Plan à venir', relance: 'Relance', garantie: 'Garantie' };
    let mois = new Date(); mois.setDate(1);
    let evenements = [], choisi = null;

    function charger() {
        const debut = new Date(mois.getFullYear(), mois.getMonth(), 1 - ((new Date(mois.getFullYear(), mois.getMonth(), 1).getDay() + 6) % 7));
        const fin = new Date(debut); fin.setDate(fin.getDate() + 41);
        $('#plan-mois').text(MOIS[mois.getMonth()] + ' ' + mois.getFullYear());
        $('#plan-statut').text('Chargement…');
        const agent = $('#plan-agent').val();
        v1('planning?du=' + iso(debut) + '&au=' + iso(fin) + (agent ? '&assigne=' + agent : '')).done(function (r) {
            evenements = r.data; $('#plan-statut').text(''); dessiner(debut);
        }).fail(xhr => $('#plan-statut').text(xhr.status === 403 ? 'Vous n\'avez pas accès au planning.' : erreur(xhr)));
    }

    function dessiner(debut) {
        const parJour = {};
        evenements.forEach(e => { (parJour[e.date] = parJour[e.date] || []).push(e); });
        let html = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map(j => `<div class="plan-entete">${j}</div>`).join('');
        const aujourdhui = iso(new Date());
        for (let i = 0; i < 42; i++) {
            const d = new Date(debut); d.setDate(debut.getDate() + i);
            const k = iso(d), liste = parJour[k] || [];
            html += `<div class="plan-case${d.getMonth() !== mois.getMonth() ? ' hors-mois' : ''}${k === aujourdhui ? ' aujourdhui' : ''}${k === choisi ? ' choisie' : ''}" data-jour="${k}"${liste.length ? ` data-n="${liste.length}"` : ''}>
                <span class="plan-num">${d.getDate()}</span>
                ${liste.slice(0, 3).map(e => `<span class="plan-item plan-${e.type}${e.retard ? ' retard' : ''}" title="${esc(e.titre)}">${esc(e.titre)}</span>`).join('')}
                ${liste.length > 3 ? `<span class="field-hint">+${liste.length - 3} autre(s)</span>` : ''}</div>`;
        }
        $('#plan-grille').html(html);
        detailJour();
    }

    function detailJour() {
        if (!choisi) { $('#plan-jour').hide(); return; }
        const liste = evenements.filter(e => e.date === choisi);
        $('#plan-jour').show().html(`<h4>${choisi.split('-').reverse().join('/')}</h4><table><thead><tr><th>Type</th><th>Titre</th><th>Détail</th><th>Assigné</th><th>Statut</th></tr></thead><tbody>` +
            (liste.map(e => `<tr><td>${LIBELLES[e.type]}${e.retard ? ' <strong style="color:#dc2626">(en retard)</strong>' : ''}</td>
                <td>${esc(e.tache && e.tache.reference ? e.tache.reference + ' · ' : '')}${esc(e.titre)}</td><td>${esc(e.detail || '')}</td>
                <td>${esc(e.assigne || '—')}</td><td>${esc(e.tache ? e.tache.statut : '—')}</td></tr>`).join('') || '<tr><td colspan="5">Rien de prévu ce jour.</td></tr>') + '</tbody></table>');
    }

    $('.sidebar-menu a[href="#planning"]').on('click', function () { mois = new Date(); mois.setDate(1); choisi = iso(new Date()); charger(); });
    $('#plan-prec').on('click', () => { mois.setMonth(mois.getMonth() - 1); charger(); });
    $('#plan-suiv').on('click', () => { mois.setMonth(mois.getMonth() + 1); charger(); });
    $('#plan-aujourdhui').on('click', () => { mois = new Date(); mois.setDate(1); choisi = iso(new Date()); charger(); });
    $('#plan-agent').on('change', charger);
    $(document).on('click', '.plan-case', function () { choisi = $(this).data('jour'); $('.plan-case').removeClass('choisie'); $(this).addClass('choisie'); detailJour(); });
});
