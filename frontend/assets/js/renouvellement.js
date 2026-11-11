// Écran « Renouvellement du matériel » : suggestions automatiques (âge, pannes répétées) et suivi des propositions.
// Utilise l'API v1 (mêmes routes que l'application mobile) avec la session du site.
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const v1 = (m, r, c) => $.ajax({ url: V1 + r, type: m, dataType: 'json', contentType: 'application/json', data: c === undefined ? undefined : JSON.stringify(c) });
    const erreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';
    const duree = (m) => m == null ? '—' : (m >= 12 ? Math.floor(m / 12) + ' an(s)' + (m % 12 ? ' ' + (m % 12) + ' mois' : '') : m + ' mois');
    const euros = (v) => v == null ? '—' : Number(v).toLocaleString('fr-FR');
    const SUIVANT = { 'Proposé': ['Validé', 'Refusé'], 'Validé': ['Commandé', 'Refusé'], 'Commandé': ['Remplacé'] };
    const ICONES = { 'Validé': 'fa-check', 'Refusé': 'fa-times', 'Commandé': 'fa-shopping-cart', 'Remplacé': 'fa-exchange-alt' };

    function charger() {
        $('#renouv-statut').text('Chargement…');
        $.when(v1('GET', 'renouvellements/suggestions'), v1('GET', 'renouvellements')).done(function (s, l) {
            const regles = s[0].meta && s[0].meta.regles;
            if (regles) {
                $('#renouv-regles').text(`Règles : fin de vie du type (défaut ${duree(regles.duree_vie_defaut_mois)}), ou ${regles.pannes_seuil} pannes ou plus sur ${regles.pannes_fenetre_mois} mois. La durée de vie se règle par type de matériel.`);
            }
            $('#renouv-suggestions-table tbody').html(s[0].data.map(x => `
                <tr>
                    <td><strong>${esc(x.materiel.libelle)}</strong></td>
                    <td>${esc([x.materiel.service, x.materiel.utilisateur].filter(Boolean).join(' · ') || '—')}</td>
                    <td>${duree(x.age_mois)}</td>
                    <td>${x.criteres.map(c => esc(c.texte)).join('<br>')}</td>
                    <td>${x.score}</td>
                    <td class="actions"><button class="btn btn-primary renouv-proposer" data-id="${x.materiel.id}" data-nom="${esc(x.materiel.libelle)}">Proposer</button></td>
                </tr>`).join('') || '<tr><td colspan="6">Aucun matériel à renouveler selon les règles actuelles.</td></tr>');
            $('#renouv-table tbody').html(l[0].data.map(r => `
                <tr>
                    <td><strong>${esc(r.materiel.libelle)}</strong></td>
                    <td>${esc(r.statut)}</td>
                    <td>${esc([r.motif].concat(r.criteres.map(c => c.texte)).filter(Boolean).join(' — '))}</td>
                    <td>${euros(r.cout_estime)}</td>
                    <td>${esc(r.propose_par || '—')}</td>
                    <td>${esc(r.decide_par ? r.decide_par + (r.commentaire ? ' : ' + r.commentaire : '') : '—')}</td>
                    <td class="actions">${(SUIVANT[r.statut] || []).map(d =>
                        `<button class="btn-action renouv-decision" data-id="${r.id}" data-decision="${d}" title="${d}"><i class="fas ${ICONES[d]}"></i></button>`).join('')}</td>
                </tr>`).join('') || '<tr><td colspan="7">Aucune proposition.</td></tr>');
            $('#renouv-statut').text('');
        }).fail(xhr => $('#renouv-statut').text(xhr.status === 403 ? 'Vous n\'avez pas accès aux renouvellements.' : erreur(xhr)));
    }

    $('.sidebar-menu a[href="#renouvellement"]').on('click', charger);

    $(document).on('click', '.renouv-proposer', function () {
        const id = Number($(this).data('id')), nom = $(this).data('nom');
        const cout = prompt(`Coût estimé du remplacement de « ${nom} » (facultatif) :`, '');
        if (cout === null) { return; }
        const corps = { materiel_id: id };
        if (cout.trim() !== '') { corps.cout_estime = Number(cout.replace(/\s/g, '').replace(',', '.')); }
        v1('POST', 'renouvellements', corps).done(charger).fail(xhr => alert(erreur(xhr)));
    });

    $(document).on('click', '.renouv-decision', function () {
        const id = $(this).data('id'), decision = $(this).data('decision');
        let commentaire = null;
        if (decision === 'Refusé') {
            commentaire = prompt('Raison du refus :', '');
            if (commentaire === null || !commentaire.trim()) { return; }
        } else if (!confirm(`Passer cette proposition à « ${decision} » ?` + (decision === 'Remplacé' ? '\nL\'ancien matériel sera marqué « Retiré ».' : ''))) { return; }
        v1('POST', 'renouvellements/' + id + '/decision', { decision, commentaire }).done(charger).fail(xhr => alert(erreur(xhr)));
    });
});
