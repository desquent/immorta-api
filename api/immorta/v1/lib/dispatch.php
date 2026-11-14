<?php
declare(strict_types=1);

/**
 * Dispatch central — IMMORTA API v1.
 * M0 n'expose que m0_probe (mutation idempotente) et get_request_status (lecture).
 * Les actions métier B4+ restent explicitement indisponibles.
 */

require_once __DIR__ . '/idempotency.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../handlers/m0.php';

function immorta_dispatch(PDO $pdo, array $config, string $action, string $requestId, string $workerId, array $payload): void
{
    // Lecture seule : ne crée aucune réservation.
    if ($action === 'get_request_status') {
        $res = immorta_idempotency_get_status($pdo, $requestId, $workerId, $payload);
        if (!empty($res['ok'])) {
            immorta_success($requestId, 'REQUEST_STATUS', $res['data'] ?? []);
        }
        immorta_error($requestId, $res['code'] ?? 'NOT_IMPLEMENTED', $res['message'] ?? 'Not implemented', (int)($res['http'] ?? 501));
    }

    // Registre M0 explicite : aucune action métier B4+ ne passe à la réservation.
    $allowedActions = $config['api']['allowed_actions'] ?? [];
    $probeEnabled = ($config['api']['m0_probe_enabled'] ?? false) === true;
    $probeAllowed = is_array($allowedActions) && in_array('m0_probe', $allowedActions, true);

    if ($action !== 'm0_probe' || !$probeEnabled || !$probeAllowed) {
        immorta_error($requestId, 'NOT_IMPLEMENTED', 'Action not available in M0', 501);
    }

    // Réservation uniquement après validation du registre M0.
    try {
        $idem = immorta_idempotency_begin($pdo, $config, $requestId, $workerId, $action, $payload);
    } catch (Throwable $e) {
        immorta_error($requestId, 'SERVICE_UNAVAILABLE', 'Service unavailable', 503);
    }

    if (empty($idem['ok'])) {
        immorta_error(
            $requestId,
            $idem['code'] ?? 'SERVICE_UNAVAILABLE',
            $idem['message'] ?? 'Service unavailable',
            (int)($idem['http'] ?? 503)
        );
    }

    if (($idem['replay'] ?? false) === true && isset($idem['record']) && is_array($idem['record'])) {
        immorta_idempotency_replay($idem['record'], $requestId);
    }

    if (empty($idem['reserved']) || !isset($idem['record']) || !is_array($idem['record'])) {
        immorta_error($requestId, 'IDEMPOTENCY_RESERVATION_INVALID', 'Request outcome is uncertain', 503);
    }

    // Handler + stockage terminal dans une seule transaction S1.
    try {
        $pdo->beginTransaction();

        $data = immorta_handle_m0_probe($payload);
        $responseBody = immorta_response_body(true, $requestId, 'M0_PROBE_OK', $data, null);

        $stored = immorta_idempotency_store_response(
            $pdo,
            $idem['record'],
            200,
            $responseBody,
            'done'
        );

        if (!$stored) {
            throw new RuntimeException('Idempotency reservation could not be finalized');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rollbackError) {
                // L'état reste incertain : aucune finalisation forcée.
            }
        }

        immorta_error($requestId, 'SERVICE_UNAVAILABLE', 'Service unavailable', 503);
    }

    // Émission seulement après confirmation du commit.
    immorta_emit_body($responseBody, 200);
}
