// Écran « Renouvellement du matériel » : suggestions automatiques (fin de vie, pannes répétées, garantie proche).
$(function () {
    const V1 = '../backend/api/v1/index.php/';
    const esc = (t) => $('<div>').text(t == null ? '' : String(t)).html();
    const erreur = (xhr) => (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Erreur de communication.';
    const duree = (m) => m == null ? '—' : (m >= 12 ? Math.floor(m / 12) + ' an(s)' + (m % 12 ? ' ' + (m % 12) + ' mois' : '') : m + ' mois');

    function charger() {
        $('#renouv-statut').text('Chargement…');
        $.ajax({ url: V1 + 'renouvellements/suggestions', dataType: 'json' }).done(function (r) {
            const regles = r.meta && r.meta.regles;
            if (regles) {
                $('#renouv-regles').text(`Règles : fin de vie du type (défaut ${duree(regles.duree_vie_defaut_mois)}), ${regles.pannes_seuil} pannes ou plus sur ${regles.pannes_fenetre_mois} mois, ou garantie qui expire bientôt. La durée de vie se règle par type de matériel.`);
            }
            $('#renouv-suggestions-table tbody').html(r.data.map(x => `
                <tr>
                    <td><strong>${esc(x.materiel.libelle)}</strong></td>
                    <td>${esc([x.materiel.service, x.materiel.utilisateur].filter(Boolean).join(' · ') || '—')}</td>
                    <td>${duree(x.age_mois)}</td>
                    <td>${x.criteres.map(c => esc(c.texte)).join('<br>')}</td>
                    <td>${x.score}</td>
                </tr>`).join('') || '<tr><td colspan="5">Aucun matériel à renouveler selon les règles actuelles.</td></tr>');
            $('#renouv-statut').text('');
        }).fail(xhr => $('#renouv-statut').text(xhr.status === 403 ? 'Vous n\'avez pas accès aux renouvellements.' : erreur(xhr)));
    }

    $('.sidebar-menu a[href="#renouvellement"]').on('click', charger);
});
