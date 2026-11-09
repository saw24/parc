<?php
// Anciens endpoints du site (backend/api/<entité>.php?action=…). Le site passe désormais par l'API v1 pour toutes
// les opérations métier (voir frontend/assets/js/legacy-bridge.js) : ces actions répondent 410 afin qu'aucune
// règle (workflow des pannes, garde-fous administrateur, notifications) ne puisse être contournée par l'ancienne voie.

/** @param list<string> $actionsMigrees */
function legacy_migre(string $action, array $actionsMigrees): void
{
    if (in_array($action, $actionsMigrees, true)) {
        http_response_code(410);
        echo json_encode(['error' => 'Cette opération est désormais assurée par l\'API v1 (backend/api/v1).']);
        exit();
    }
}
