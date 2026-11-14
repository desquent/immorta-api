<?php
declare(strict_types=1);

/**
 * Socle API IMMORTA v1 — réponses JSON, lecture requête, helpers.
 * Aucun détail interne/sensible ne doit être exposé au worker.
 */

const IMMORTA_API_VERSION = 'v1';

function immorta_json_headers(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
}

function immorta_response_body(bool $success, ?string $requestId, string $code, ?array $data, ?array $error): string
{
    try {
        return json_encode([
            'success' => $success,
            'request_id' => $requestId,
            'code' => $code,
            'data' => $data === null ? null : (object)$data,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Unable to encode API response', 0, $e);
    }
}

function immorta_emit_body(string $body, int $httpStatus): never
{
    immorta_json_headers();
    http_response_code($httpStatus);
    echo $body;
    exit;
}

function immorta_send(array $payload, int $httpStatus = 200): never
{
    try {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        http_response_code(500);
        exit;
    }
    immorta_emit_body($body, $httpStatus);
}

function immorta_success(?string $requestId, string $code, array $data = []): never
{
    immorta_emit_body(immorta_response_body(true, $requestId, $code, $data, null), 200);
}

function immorta_error(?string $requestId, string $code, string $message, int $httpStatus = 400): never
{
    // Message destiné au worker : générique/ciblé, jamais d’interna (SQL, stack, chemins, secrets).
    immorta_emit_body(immorta_response_body(false, $requestId, $code, null, ['message' => $message]), $httpStatus);
}

function immorta_get_headers_lower(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $name = strtolower(str_replace('_', '-', $key));
            $headers[$name] = $value;
        }
    }
    // Fallback getallheaders si disponible
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
    }
    return $headers;
}

function immorta_read_json_body(int $maxBytes = 1048576): array
{
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if ($contentLength !== null && $contentLength !== '') {
        if (!ctype_digit((string)$contentLength)) {
            return ['ok' => false, 'raw' => '', 'data' => null, 'error' => 'INVALID_CONTENT_LENGTH', 'http' => 400];
        }
        if ((int)$contentLength > $maxBytes) {
            return ['ok' => false, 'raw' => '', 'data' => null, 'error' => 'BODY_TOO_LARGE', 'http' => 413];
        }
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        return ['ok' => false, 'raw' => '', 'data' => null, 'error' => 'READ_ERROR', 'http' => 400];
    }
    if (strlen($raw) > $maxBytes) {
        return ['ok' => false, 'raw' => '', 'data' => null, 'error' => 'BODY_TOO_LARGE', 'http' => 413];
    }
    if ($raw === '') {
        return ['ok' => false, 'raw' => '', 'data' => null, 'error' => 'EMPTY_BODY', 'http' => 400];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return ['ok' => false, 'raw' => $raw, 'data' => null, 'error' => 'INVALID_JSON', 'http' => 400];
    }
    return ['ok' => true, 'raw' => $raw, 'data' => $data, 'error' => null, 'http' => 200];
}

function immorta_is_canonical_uuid(string $value): bool
{
    // UUID canonique 36 caractères (immorta_api_idempotency.request_id CHAR(36))
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value);
}

function immorta_validate_envelope(array $data, array $allowedActions): array
{
    $requestId = $data['request_id'] ?? null;
    $workerId = $data['worker_id'] ?? null;
    $action = $data['action'] ?? null;
    $payload = $data['payload'] ?? null;

    // request_id : UUID canonique 36 caractères (immorta_api_idempotency.request_id CHAR(36))
    if (!is_string($requestId) || !immorta_is_canonical_uuid($requestId)) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST_ID', 'message' => 'Invalid request_id', 'request_id' => is_string($requestId) ? $requestId : null];
    }
    if (!is_string($workerId) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $workerId)) {
        return ['ok' => false, 'code' => 'INVALID_WORKER_ID', 'message' => 'Invalid worker_id', 'request_id' => $requestId];
    }
    if (!is_string($action) || $action === '' || !in_array($action, $allowedActions, true)) {
        return ['ok' => false, 'code' => 'UNKNOWN_ACTION', 'message' => 'Action not allowed', 'request_id' => $requestId];
    }
    if (!is_array($payload)) {
        // payload doit être un objet JSON (tableau associatif côté PHP)
        return ['ok' => false, 'code' => 'INVALID_PAYLOAD', 'message' => 'Invalid payload', 'request_id' => $requestId];
    }

    return [
        'ok' => true,
        'request_id' => $requestId,
        'worker_id' => $workerId,
        'action' => $action,
        'payload' => $payload,
    ];
}
