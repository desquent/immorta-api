<?php
declare(strict_types=1);

/**
 * Authentification worker HMAC-SHA256 (socle).
 *
 * Contrat d'en-têtes (à figer) :
 * - X-Immorta-Worker-Id : identifiant worker (doit correspondre au body.worker_id)
 * - X-Immorta-Timestamp : horodatage Unix UTC (secondes, entier strict)
 * - X-Immorta-Request-Id: request_id UUID canonique (doit correspondre au body.request_id)
 * - X-Immorta-Signature : hex minuscule HMAC-SHA256(secret, canonical_string)
 *
 * Canonical string (octets UTF-8) :
 *   METHOD \n PATH \n TIMESTAMP \n REQUEST_ID \n SHA256(RAW_BODY)
 * METHOD = verbe HTTP en majuscules ; PATH = chemin canonique figé en config (jamais issu d'un header client)
 * RAW_BODY = corps exact reçu (php://input).
 *
 * Signature valide ≠ protection contre les doubles mutations : anti-rejeu/idempotence géré en B3.
 */

function immorta_hash_equals(string $known, string $user): bool
{
    if (function_exists('hash_equals')) {
        return hash_equals($known, $user);
    }
    return false;
}

function immorta_canonical_string(string $method, string $path, string $timestamp, string $requestId, string $rawBody): string
{
    $bodyHash = hash('sha256', $rawBody);
    return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $requestId . "\n" . $bodyHash;
}

function immorta_authenticate(array $headers, array $envelope, string $rawBody, array $authConfig): array
{
    $workerIdHeader = trim((string)($headers['x-immorta-worker-id'] ?? ''));
    $timestamp = trim((string)($headers['x-immorta-timestamp'] ?? ''));
    $requestIdHeader = trim((string)($headers['x-immorta-request-id'] ?? ''));
    $signature = trim((string)($headers['x-immorta-signature'] ?? ''));

    if ($workerIdHeader === '' || $timestamp === '' || $requestIdHeader === '' || $signature === '') {
        return ['ok' => false, 'code' => 'AUTH_MISSING_HEADERS', 'message' => 'Authentication required', 'http' => 401];
    }

    // Cohérence header/body (worker_id body n'est pas une preuve mais doit correspondre)
    if ($workerIdHeader !== $envelope['worker_id']) {
        return ['ok' => false, 'code' => 'AUTH_WORKER_MISMATCH', 'message' => 'Authentication failed', 'http' => 401];
    }
    if ($requestIdHeader !== $envelope['request_id']) {
        return ['ok' => false, 'code' => 'AUTH_REQUEST_MISMATCH', 'message' => 'Authentication failed', 'http' => 401];
    }

    // Timestamp strict : entier positif, longueur raisonnable, pas de dépassement
    if (!ctype_digit($timestamp) || strlen($timestamp) < 1 || strlen($timestamp) > 10 || $timestamp[0] === '-') {
        return ['ok' => false, 'code' => 'AUTH_INVALID_TIMESTAMP', 'message' => 'Authentication failed', 'http' => 401];
    }
    $ts = (int)$timestamp;
    if ($ts <= 0) {
        return ['ok' => false, 'code' => 'AUTH_INVALID_TIMESTAMP', 'message' => 'Authentication failed', 'http' => 401];
    }

    $skew = (int)($authConfig['timestamp_skew_seconds'] ?? 300);
    if ($skew < 0) {
        $skew = 300;
    }
    $now = time();
    if (abs($now - $ts) > $skew) {
        return ['ok' => false, 'code' => 'AUTH_TIMESTAMP_SKEW', 'message' => 'Request timestamp rejected', 'http' => 401];
    }

    $workers = $authConfig['workers'] ?? [];
    if (!is_array($workers) || !array_key_exists($workerIdHeader, $workers)) {
        return ['ok' => false, 'code' => 'AUTH_UNKNOWN_WORKER', 'message' => 'Authentication failed', 'http' => 401];
    }
    $secret = $workers[$workerIdHeader];
    if (!is_string($secret) || $secret === '' || $secret === 'CHANGE_ME_SECRET') {
        return ['ok' => false, 'code' => 'AUTH_NOT_CONFIGURED', 'message' => 'Authentication failed', 'http' => 401];
    }

    // Chemin canonique figé en config (jamais depuis un header client / REQUEST_URI non maîtrisé)
    $canonicalPath = $authConfig['canonical_path'] ?? null;
    if (!is_string($canonicalPath) || $canonicalPath === '' || $canonicalPath[0] !== '/') {
        return ['ok' => false, 'code' => 'AUTH_NOT_CONFIGURED', 'message' => 'Authentication failed', 'http' => 401];
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
    $canonical = immorta_canonical_string($method, $canonicalPath, $timestamp, $requestIdHeader, $rawBody);
    $expected = hash_hmac('sha256', $canonical, $secret);

    // Signature : hexadécimal minuscule uniquement (contrat figé)
    $sigNorm = trim($signature);
    if (!preg_match('/^[a-f0-9]{64}$/', $sigNorm) || !immorta_hash_equals($expected, $sigNorm)) {
        return ['ok' => false, 'code' => 'AUTH_SIGNATURE_INVALID', 'message' => 'Authentication failed', 'http' => 401];
    }

    // Anti-rejeu fort (unicité request_id/fenêtre) : branché sur immorta_api_idempotency (B3).
    return [
        'ok' => true,
        'worker_id' => $workerIdHeader,
        'timestamp' => $ts,
        'request_id' => $requestIdHeader,
    ];
}
