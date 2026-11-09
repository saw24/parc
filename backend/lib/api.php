<?php
// Utilitaires de l'API mobile v1 : réponses JSON uniformes, erreurs typées, lecture du corps,
// validation et conversion des dates.
//
// Succès : {"data": ..., "meta": {...}}     Erreur : {"error": {"code": "...", "message": "...", "details": {...}}}
// Les dates sont renvoyées en ISO 8601 UTC (« 2026-11-09T12:30:00.123456Z »).

class ApiException extends Exception
{
    public function __construct(public int $status, public string $apiCode, string $message, public array $details = [])
    {
        parent::__construct($message);
    }
}

function api_fail(int $status, string $code, string $message, array $details = []): never
{
    throw new ApiException($status, $code, $message, $details);
}

function api_send(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit();
}

function api_data(mixed $data, array $meta = [], int $status = 200): never
{
    $payload = ['data' => $data];
    if ($meta) {
        $payload['meta'] = $meta;
    }
    api_send($status, $payload);
}

/** Corps de requête JSON (objet). Vide → []. */
function api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        api_fail(400, 'json_invalide', 'Le corps de la requête doit être un objet JSON.');
    }
    return $data;
}

/** Chaîne obligatoire ou facultative, rognée ; longueur contrôlée. */
function api_str(array $in, string $key, bool $required = false, int $max = 255, int $min = 0): ?string
{
    $v = $in[$key] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($required) {
            api_fail(422, 'champ_requis', "Le champ « $key » est requis.", ['champ' => $key]);
        }
        return null;
    }
    if (!is_string($v)) {
        api_fail(422, 'champ_invalide', "Le champ « $key » doit être une chaîne.", ['champ' => $key]);
    }
    $v = trim($v);
    $len = mb_strlen($v);
    if ($len > $max || $len < $min) {
        api_fail(422, 'champ_invalide', "Le champ « $key » doit contenir entre $min et $max caractères.", ['champ' => $key]);
    }
    return $v;
}

function api_int(array $in, string $key, bool $required = false): ?int
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            api_fail(422, 'champ_requis', "Le champ « $key » est requis.", ['champ' => $key]);
        }
        return null;
    }
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
        api_fail(422, 'champ_invalide', "Le champ « $key » doit être un entier.", ['champ' => $key]);
    }
    return (int) $v;
}

function api_enum(array $in, string $key, array $valeurs, ?string $defaut = null): ?string
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        return $defaut;
    }
    if (!is_string($v) || !in_array($v, $valeurs, true)) {
        api_fail(422, 'champ_invalide', "Valeur invalide pour « $key ».", ['champ' => $key, 'valeurs' => $valeurs]);
    }
    return $v;
}

function api_date(array $in, string $key): ?string
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
        api_fail(422, 'champ_invalide', "« $key » doit être une date AAAA-MM-JJ.", ['champ' => $key]);
    }
    return $v;
}

function api_uuid(array $in, string $key): ?string
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_string($v) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
        api_fail(422, 'champ_invalide', "« $key » doit être un UUID.", ['champ' => $key]);
    }
    return strtolower($v);
}

/** Fuseau horaire de la session PostgreSQL (les colonnes TIMESTAMP sont sans fuseau). */
function api_db_tz(PDO $pdo): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone((string) $pdo->query('SHOW TimeZone')->fetchColumn());
}

/** « 2026-11-09 12:30:00.123456 » (heure de la base) → « 2026-11-09T12:30:00.123456Z » (UTC). */
function api_ts(PDO $pdo, ?string $naive): ?string
{
    if ($naive === null || $naive === '') {
        return null;
    }
    $d = new DateTimeImmutable($naive, api_db_tz($pdo));
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}

/** ISO 8601 (avec fuseau) → heure naïve de la base, pour comparer aux colonnes TIMESTAMP. */
function api_ts_in(PDO $pdo, string $iso, string $champ = 'updated_since'): string
{
    try {
        $d = new DateTimeImmutable($iso);
    } catch (Exception) {
        api_fail(422, 'champ_invalide', "« $champ » doit être une date ISO 8601.", ['champ' => $champ]);
    }
    return $d->setTimezone(api_db_tz($pdo))->format('Y-m-d H:i:s.u');
}

function api_now(PDO $pdo): string
{
    return (string) api_ts($pdo, (string) $pdo->query('SELECT NOW()::text')->fetchColumn());
}

/** Pagination par curseur opaque (base64url d'un tableau JSON). */
function api_cursor_encode(array $v): string
{
    return rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
}

function api_cursor_decode(?string $c): ?array
{
    if ($c === null || $c === '') {
        return null;
    }
    $v = json_decode((string) base64_decode(strtr($c, '-_', '+/')), true);
    if (!is_array($v)) {
        api_fail(422, 'curseur_invalide', 'Curseur de pagination invalide.');
    }
    return $v;
}

function api_limit(array $query, int $defaut = 50, int $max = 100): int
{
    $l = isset($query['limit']) && ctype_digit((string) $query['limit']) ? (int) $query['limit'] : $defaut;
    return max(1, min($max, $l));
}

/** Compare deux versions « x.y.z ». */
function api_version_inferieure(string $v, string $min): bool
{
    return version_compare($v, $min, '<');
}
