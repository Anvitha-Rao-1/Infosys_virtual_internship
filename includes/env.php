<?php
/**
 * includes/env.php
 * ------------------------------------------------------------------
 * Minimal .env reader — the ONLY place the app learns about secrets.
 *
 * Why a file and not a constant in PHP: the Hugging Face API key must
 * never be committed. `.env` is gitignored; `.env.example` is committed
 * and holds the key NAMES with empty values, so anyone cloning the repo
 * can see what to set without ever seeing a real token.
 *
 * Lookup order for env_get('HF_API_KEY'):
 *   1. a real environment variable / Apache SetEnv  (getenv)
 *   2. $_SERVER / $_ENV
 *   3. the .env file at the project root
 *
 * That order means a server-level setting always wins over the file, and
 * nothing here ever writes, logs or echoes a value.
 * ------------------------------------------------------------------
 */

function env_all(): array {
    static $vars = null;
    if ($vars !== null) return $vars;

    $vars = [];
    $path = dirname(__DIR__) . '/.env';
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // Strip one layer of matching quotes, so both KEY=value and
            // KEY="value with spaces" work.
            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '') $vars[$key] = $val;
        }
    }
    return $vars;
}

function env_get(string $key, $default = null) {
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') return $fromEnv;
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

    $vars = env_all();
    if (isset($vars[$key]) && $vars[$key] !== '') return $vars[$key];
    return $default;
}

/** True when a usable-looking Hugging Face token is configured. */
function hf_configured(): bool {
    $key = env_get('HF_API_KEY');
    return is_string($key) && strlen(trim($key)) > 8;
}
