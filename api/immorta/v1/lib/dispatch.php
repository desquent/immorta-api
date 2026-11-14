<?php
declare(strict_types=1);

/**
 * Dispatch central — IMMORTA API v1
 * Séparation : auth (index) -> idempotence (mutations/réservations) -> handlers métier.
 * Lectures/consultations sans effet de bord : hors réservation idempotence de mutation.
 */

require_once __DIR__ . '/idempotency.php';

function immorta_dispatch(PDO $pdo, array $config, string $action, string $requestId, string $workerId, array $payload): void
{
    // Consultations lecture seule (aucune mutation, aucun renouvellement de bail, aucune réservation, aucun compteur modifié)
    if ($action === 'get_request_status') {
        $res = immorta_idempotency_get_status($pdo, $requestId, $workerId, $payload);
        if (!empty($res['ok'])) {
            immorta_success($requestId, 'REQUEST_STATUS', $res['data'] ?? []);
        }
        immorta_error($requestId, $res['code'] ?? 'NOT_IMPLEMENTED', $res['message'] ?? 'Not implemented', (int)($res['http'] ?? 501));
    }
    if ($action === 'get_job' || $action === 'quota_status') {
        // Handlers lecture seule à implémenter (B4+/B6-B7) : accès BDD contrôlé, owner/lease vérifiés selon contrat,
        // sans écriture, sans renouvellement de lease, sans acquisition/réservation de quota.
        immorta_error($requestId, 'NOT_IMPLEMENTED', 'Action handler not implemented', 501);
    }

    // Actions mutantes / réservations d'état : passage obligatoire par idempotence (B3) avant tout handler
    // (claim_job, renew_lease, release_lease, update_job, update_post, quota_acquire,
    //  prepare_finalize, commit_finalize, worker_heartbeat, etc.)
    $idem = immorta_idempotency_begin($pdo, $config, $requestId, $workerId, $action, $payload);
    if (!empty($idem['ok'])) {
        // Cas : réponse mémorisée à restituer sans ré-exécution handler
        if (isset($idem['replay']) && $idem['replay'] === true && isset($idem['record']) && is_array($idem['record'])) {
            immorta_idempotency_replay($idem['record'], $requestId);
        }
        // Cas : réservation acquise -> exécuter handler (B4+), puis enregistrer résultat (à implémenter avec schéma)
    }

    // Échec de porte idempotence (conflit/in_progress/expiré-incertain/non configuré)
    immorta_error(
        $requestId,
        $idem['code'] ?? 'NOT_IMPLEMENTED',
        $idem['message'] ?? 'Action handler not implemented',
        (int)($idem['http'] ?? 501)
    );
}
