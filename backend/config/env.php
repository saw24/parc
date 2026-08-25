<?php
// Chargeur minimal de fichier .env (KEY=VALUE), sans dépendance externe.
// Le .env est attendu à la racine du projet (au-dessus de backend/ et frontend/).

function load_env(string $path): array
{
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }

    $vars = [];
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim($value);
        }
    }

    $cache[$path] = $vars;
    return $vars;
}
