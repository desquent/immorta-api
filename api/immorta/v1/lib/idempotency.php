<?php
declare(strict_types=1);

/**
 * B3 — Idempotence IMMORTA API v1
 * Table : immorta_api_idempotency
 * Aucune création ou modification SQL.
 */

function immorta_is_list_array(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

/**
 * Canonisation récursive :
 * - objets associatifs : clés triées ;
 * - listes : ordre conservé.
 */
function immorta_canonicalize_for_hash($value)
{
    if (!is_array($value)) {
        return $value;
    }

    if (!immorta_is_list_array($value)) {
        ksort($value, SORT_STRING);
    }

    foreach ($value as $key => $item) {
        $value[$key] = immorta_canonicalize_for_hash($item);
    }

    return $value;
}

function immorta_payload_hash(array $payload): string
{
    $canonical = immorta_canonicalize_for_hash($payload);

    try {
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        throw new RuntimeException('Payload canonicalization failed', 0, $e);
    }

    return hash('sha256', $json);
}

function immorta_idempotency_ttl(array $config): int
{
    $ttl = $config['idempotency']['in_progress_ttl_seconds'] ?? 300;

    if (!is_int($ttl) && !(is_string($ttl) && ctype_digit($ttl))) {
        return 300;
    }

    $ttl = (int)$ttl;

    return max(30, min($ttl, 86400));
}

function immorta_idempotency_is_duplicate(PDOException $e): bool
{
    return isset($e->errorInfo[1])
        && (int)$e->errorInfo[1] === 1062;
}

/**
 * Réservation atomique d'une requête.
 *
 * Résultats :
 * - ok + reserved : nouvelle réservation acquise ;
 * - ok + replay : réponse déjà mémorisée ;
 * - erreur 409 : conflit, requête en cours ou résultat incertain ;
 * - erreur 503 : problème technique.
 */
function immorta_idempotency_begin(
    PDO $pdo,
    array $config,
    string $requestId,
    string $workerId,
    string $action,
    array $payload
): array {
    $hash = immorta_payload_hash($payload);
    $ttl = immorta_idempotency_ttl($config);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO immorta_api_idempotency
                (
                    worker_id,
                    request_id,
                    action,
                    payload_hash,
                    status,
                    started_at,
                    last_heartbeat_at,
                    expires_at
                )
             VALUES
                (
                    :worker_id,
                    :request_id,
                    :action,
                    :payload_hash,
                    'in_progress',
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP(),
                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND)
                )"
        );

        $stmt->bindValue(':worker_id', $workerId);
        $stmt->bindValue(':request_id', $requestId);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':payload_hash', $hash);
        $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $stmt->execute();

        $id = (int)$pdo->lastInsertId();

        $pdo->commit();

        return [
            'ok' => true,
            'reserved' => true,
            'replay' => false,
            'record' => [
                'id' => $id,
                'worker_id' => $workerId,
                'request_id' => $requestId,
                'action' => $action,
                'payload_hash' => $hash,
                'status' => 'in_progress',
            ],
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (!immorta_idempotency_is_duplicate($e)) {
            throw $e;
        }
    }

    /*
     * Collision sur uk_worker_request.
     * On relit la ligne sous verrou pour éviter les décisions concurrentes.
     */
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT
                id,
                worker_id,
                request_id,
                action,
                payload_hash,
                status,
                response_code,
                response_body,
                started_at,
                last_heartbeat_at,
                expires_at,
                completed_at,
                (expires_at <= UTC_TIMESTAMP()) AS is_expired
             FROM immorta_api_idempotency
             WHERE worker_id = :worker_id
               AND request_id = :request_id
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->execute([
            ':worker_id' => $workerId,
            ':request_id' => $requestId,
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $pdo->rollBack();

            return [
                'ok' => false,
                'code' => 'IDEMPOTENCY_LOOKUP_FAILED',
                'message' => 'Unable to resolve request status',
                'http' => 503,
            ];
        }

        if (!hash_equals((string)$record['payload_hash'], $hash)) {
            $pdo->commit();

            return [
                'ok' => false,
                'code' => 'IDEMPOTENCY_CONFLICT',
                'message' => 'Request identifier already used with another payload',
                'http' => 409,
            ];
        }

        if ((string)$record['action'] !== $action) {
            $pdo->commit();

            return [
                'ok' => false,
                'code' => 'IDEMPOTENCY_CONFLICT',
                'message' => 'Request identifier already used for another action',
                'http' => 409,
            ];
        }

        /*
         * Une réservation expirée est marquée stale.
         * Elle n'est jamais relancée automatiquement.
         */
        if (
            $record['status'] === 'in_progress'
            && (int)$record['is_expired'] === 1
        ) {
            $update = $pdo->prepare(
                "UPDATE immorta_api_idempotency
                 SET status = 'stale'
                 WHERE id = :id
                   AND status = 'in_progress'
                   AND expires_at <= UTC_TIMESTAMP()"
            );

            $update->execute([':id' => $record['id']]);
            if ($update->rowCount() === 1) {
                $record['status'] = 'stale';
            }
        }

        $pdo->commit();

        if ($record['status'] === 'done' || $record['status'] === 'failed') {
            if (
                $record['response_code'] === null
                || $record['response_body'] === null
                || $record['response_body'] === ''
            ) {
                return [
                    'ok' => false,
                    'code' => 'IDEMPOTENCY_RESPONSE_MISSING',
                    'message' => 'Stored response unavailable; manual reconciliation required',
                    'http' => 503,
                ];
            }

            return [
                'ok' => true,
                'reserved' => false,
                'replay' => true,
                'record' => $record,
            ];
        }

        if ($record['status'] === 'in_progress') {
            return [
                'ok' => false,
                'code' => 'IDEMPOTENCY_IN_PROGRESS',
                'message' => 'Request is already being processed',
                'http' => 409,
            ];
        }

        return [
            'ok' => false,
            'code' => 'IDEMPOTENCY_STALE',
            'message' => 'Request outcome is uncertain; reconciliation required',
            'http' => 409,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Enregistre une réponse connue.
 * Seule une ligne encore in_progress peut être finalisée.
 */
function immorta_idempotency_store_response(
    PDO $pdo,
    array $record,
    int $httpCode,
    string $responseBody,
    string $status = 'done'
): bool {
    if (!in_array($status, ['done', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid idempotency final status');
    }

    if ($httpCode < 100 || $httpCode > 599) {
        throw new InvalidArgumentException('Invalid HTTP response code');
    }

    if (!$pdo->inTransaction()) {
        throw new LogicException('Idempotency response must be stored inside the handler transaction');
    }

    if (strlen($responseBody) > 16777215) {
        throw new RuntimeException('Idempotency response exceeds MEDIUMTEXT limit');
    }

    $decoded = json_decode($responseBody);

    if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
        throw new InvalidArgumentException('Invalid stored JSON response');
    }

    if (
        !property_exists($decoded, 'request_id')
        || !is_string($decoded->request_id)
        || !isset($record['request_id'])
        || $decoded->request_id !== $record['request_id']
    ) {
        throw new InvalidArgumentException('Stored response request_id does not match reservation');
    }

    $stmt = $pdo->prepare(
        "UPDATE immorta_api_idempotency
         SET status = :status,
             response_code = :response_code,
             response_body = :response_body,
             completed_at = UTC_TIMESTAMP(),
             last_heartbeat_at = UTC_TIMESTAMP()
         WHERE id = :id
           AND worker_id = :worker_id
           AND request_id = :request_id
           AND status = 'in_progress'"
    );

    $stmt->execute([
        ':status' => $status,
        ':response_code' => $httpCode,
        ':response_body' => $responseBody,
        ':id' => $record['id'],
        ':worker_id' => $record['worker_id'],
        ':request_id' => $record['request_id'],
    ]);

    return $stmt->rowCount() === 1;
}

/**
 * Prolonge une réservation in_progress pendant une opération longue.
 * Ne remplace pas la vérification du bail/lease+fencing du job (B4).
 * Aucun renouvellement si la requête n'est plus in_progress (stale/done/failed) ou owner/request_id ne correspond pas.
 */
function immorta_idempotency_heartbeat(
    PDO $pdo,
    array $config,
    array $record
): bool {
    if (
        !isset($record['id'], $record['worker_id'], $record['request_id'])
        || !is_int($record['id']) && !(is_string($record['id']) && ctype_digit((string)$record['id']))
    ) {
        throw new InvalidArgumentException('Invalid idempotency record for heartbeat');
    }

    $ttl = immorta_idempotency_ttl($config);

    $stmt = $pdo->prepare(
        "UPDATE immorta_api_idempotency
         SET last_heartbeat_at = UTC_TIMESTAMP(),
             expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND)
         WHERE id = :id
           AND worker_id = :worker_id
           AND request_id = :request_id
           AND status = 'in_progress'"
    );

    $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
    $stmt->bindValue(':id', (int)$record['id'], PDO::PARAM_INT);
    $stmt->bindValue(':worker_id', (string)$record['worker_id']);
    $stmt->bindValue(':request_id', (string)$record['request_id']);
    $stmt->execute();

    return $stmt->rowCount() === 1;
}

/**
 * Émet exactement le corps JSON stocké.
 */
function immorta_idempotency_replay(array $record, string $requestId): never
{
    $body = $record['response_body'] ?? null;
    $code = $record['response_code'] ?? null;

    if (
        !is_string($body)
        || $body === ''
        || !is_numeric($code)
    ) {
        immorta_error(
            $requestId,
            'IDEMPOTENCY_RESPONSE_MISSING',
            'Stored response unavailable; reconciliation required',
            503
        );
    }

    $decoded = json_decode($body);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_object($decoded)
        || !property_exists($decoded, 'request_id')
        || $decoded->request_id !== $requestId
    ) {
        immorta_error(
            $requestId,
            'IDEMPOTENCY_RESPONSE_INVALID',
            'Stored response invalid; reconciliation required',
            503
        );
    }

    immorta_json_headers();
    http_response_code((int)$code);
    echo $body;
    exit;
}

/**
 * Consultation owner-only de l'état d'une requête.
 */
function immorta_idempotency_get_status(
    PDO $pdo,
    string $requestId,
    string $workerId,
    array $payload
): array {
    $targetId = $payload['request_id'] ?? null;

    if (
        !is_string($targetId)
        || !immorta_is_canonical_uuid($targetId)
    ) {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'message' => 'Invalid target request_id',
            'http' => 400,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT
            request_id,
            action,
            status,
            response_code,
            started_at,
            last_heartbeat_at,
            expires_at,
            completed_at
         FROM immorta_api_idempotency
         WHERE worker_id = :worker_id
           AND request_id = :request_id
         LIMIT 1"
    );

    $stmt->execute([
        ':worker_id' => $workerId,
        ':request_id' => $targetId,
    ]);

    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        return [
            'ok' => true,
            'data' => [
                'found' => false,
                'request_id' => $targetId,
            ],
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'found' => true,
            'request_id' => $record['request_id'],
            'action' => $record['action'],
            'status' => $record['status'],
            'response_code' => $record['response_code'] !== null
                ? (int)$record['response_code']
                : null,
            'started_at' => $record['started_at'],
            'last_heartbeat_at' => $record['last_heartbeat_at'],
            'expires_at' => $record['expires_at'],
            'completed_at' => $record['completed_at'],
        ],
    ];
}
