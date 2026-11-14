<?php
declare(strict_types=1);

/**
 * Point d'entrée — IMMORTA API v1 (mutualisé)
 * Socle : méthode HTTP, limite taille, JSON strict, enveloppe validée, auth HMAC, PDO UTC, dispatch, réponses uniformes.
 * Déploiement : hors périmètre (accord explicite requis). Ne pas remplacer les scripts historiques.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

// Gestion globale des exceptions non interceptées (aucun détail interne exposé)
set_exception_handler(static function (Throwable $e): void {
    // Ne pas journaliser secrets/corps ; réponse générique
    immorta_error(null, 'INTERNAL_ERROR', 'Service unavailable', 500);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Chargement config : config.php réel obligatoire (pas de fallback silencieux sur l'exemple)
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    immorta_error(null, 'SERVER_CONFIG_ERROR', 'Service unavailable', 503);
}
$config = require $configPath;
if (!is_array($config)) {
    immorta_error(null, 'SERVER_CONFIG_ERROR', 'Service unavailable', 503);
}

$allowedActions = $config['api']['allowed_actions'] ?? [];
if (!is_array($allowedActions) || $allowedActions === []) {
    immorta_error(null, 'SERVER_CONFIG_ERROR', 'Service unavailable', 503);
}
$maxBodyBytes = (int)($config['api']['max_body_bytes'] ?? 1048576);
if ($maxBodyBytes <= 0) {
    $maxBodyBytes = 1048576;
}

// Méthode HTTP : POST uniquement
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    immorta_error(null, 'METHOD_NOT_ALLOWED', 'Method not allowed', 405);
}

// Lecture/validation JSON (avec limite de taille avant décodage)
$body = immorta_read_json_body($maxBodyBytes);
if (!$body['ok']) {
    $err = (string)($body['error'] ?? 'INVALID_JSON');
    $http = (int)($body['http'] ?? 400);
    $code = $err;
    $msg = 'Invalid request';
    if ($err === 'EMPTY_BODY') { $msg = 'Request body required'; }
    elseif ($err === 'BODY_TOO_LARGE') { $msg = 'Request body too large'; $code = 'BODY_TOO_LARGE'; }
    elseif ($err === 'INVALID_CONTENT_LENGTH') { $msg = 'Invalid request'; $code = 'INVALID_REQUEST'; }
    elseif ($err === 'INVALID_JSON') { $msg = 'Invalid JSON body'; $code = 'INVALID_JSON'; }
    elseif ($err === 'READ_ERROR') { $msg = 'Invalid request'; $code = 'INVALID_REQUEST'; }
    immorta_error(null, $code, $msg, $http);
}

$envelope = immorta_validate_envelope($body['data'], $allowedActions);
if (!$envelope['ok']) {
    immorta_error($envelope['request_id'] ?? null, $envelope['code'], $envelope['message'], 400);
}

// Authentification HMAC (signature sur le corps brut, chemin canonique figé en config)
$headers = immorta_get_headers_lower();
$auth = immorta_authenticate($headers, $envelope, $body['raw'], $config['auth'] ?? []);
if (!$auth['ok']) {
    immorta_error($envelope['request_id'], $auth['code'], $auth['message'], $auth['http'] ?? 401);
}

// Connexion PDO locale + UTC (une seule connexion par requête, réutilisée par idempotence/dispatch/handlers)
try {
    $pdo = immorta_db_connect($config['db'] ?? []);
} catch (Throwable $e) {
    immorta_error($envelope['request_id'], 'DB_UNAVAILABLE', 'Service unavailable', 503);
}

// Dispatch central : B3 (idempotence/get_request_status) puis handlers métier (B4+), en fonctions séparées.
// Aucun SQL libre, aucune table hors opérations autorisées.
$action = $envelope['action'];
$requestId = $envelope['request_id'];
$workerId = $auth['worker_id'];

require_once __DIR__ . '/lib/dispatch.php';
immorta_dispatch($pdo, $config, $action, $requestId, $workerId, $envelope['payload']);

