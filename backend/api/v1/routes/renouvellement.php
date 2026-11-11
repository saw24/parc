<?php
// Renouvellement du matériel : suggestions automatiques (fin de vie, pannes répétées, garantie proche).

route('GET', 'renouvellements/suggestions', function (array $c): void {
    require_permission_v1($c['user'], 'renouvellement.voir');
    api_data(renouv_suggestions($c['pdo']), ['regles' => [
        'duree_vie_defaut_mois' => RENOUV_DUREE_VIE_DEFAUT, 'pannes_seuil' => RENOUV_PANNES_SEUIL, 'pannes_fenetre_mois' => RENOUV_PANNES_FENETRE_MOIS]]);
});
