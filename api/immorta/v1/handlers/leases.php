<?php
declare(strict_types=1);

/**
 * B4 — Leases, fencing, claim, renew, release, heartbeat (IMMORTA API v1)
 * Tables : immorta_worker_leases (registre/coordination), immorta_jobs (source de vérité).
 * Aucune création/modification SQL, aucun déploiement, aucun impact legacy TorBox.
 *
 * Règles (CDC/audit) :
 * - 1 job actif par worker_id. Pool VPS = slot_owner=99. Jobs historiques slot_owner=0 hors périmètre auto.
 * - Aucun changement auto slot_owner=99 -> 0. Jamais d'écrasement par ancien fence.
 * - Ordre verrous (ops touchant les deux tables) : LOCK immorta_worker_leases (worker) PUIS immorta_jobs (job),
 *   vérifier lease+fence, muter, synchroniser registre, COMMIT. Ne jamais inverser.
 * - Mutations protégées : vérifier worker_id==lease_owner, active_job_id==job.id, lease_token (job),
 *   lease_fence cohérent (job + registre + preuve), lease non expiré.
 * - lease_fence incrémenté à chaque nouvelle attribution (claim/adoption). Transport JSON : chaîne décimale.
 *
 * Schéma réel pris en compte (DDL immorta_worker_leases communiqué) :
 * - immorta_worker_leases : worker_id, active_job_id, lease_expires_at, lease_fence, updated_at
 *   (PAS de lease_token, PAS de last_heartbeat_at). updated_at auto (ON UPDATE).
 * - immorta_jobs : id, slot_owner, state, retry_at, lease_owner, lease_token, lease_expires_at, lease_fence, ...
 *   Le token reste exclusivement dans immorta_jobs.lease_token.
 *
 * Contrat handler : retourne ['ok'=>bool,'code','message'?,'http'?,'data'?] ; ne termine pas le processus.
 * La persistance idempotence (store_response) et l'émission HTTP doivent être faites par le dispatch (à câbler).
 */

// -----------------------------
// Configuration / helpers temps
// -----------------------------

function immorta_lease_ttl_seconds(array $config): int
{
    $ttl = $config['leases']['ttl_seconds'] ?? 600;
    if (!is_int($ttl) && !(is_string($ttl) && ctype_digit((string)$ttl))) {
        $ttl = 600;
    }
    $ttl = (int)$ttl;
    return max(30, min($ttl, 86400));
}

function immorta_lease_utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function immorta_lease_expires_at(int $ttlSeconds): string
{
    return gmdate('Y-m-d H:i:s', time() + max(1, $ttlSeconds));
}

function immorta_generate_uuid_v4_lower(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
}

function immorta_is_uuid_string(string $value): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value);
}

// -----------------------------
// Fencing (BIGINT UNSIGNED, chaîne décimale sûre)
// -----------------------------

function immorta_fence_to_string($value): string
{
    if (is_int($value)) {
        if ($value < 0) {
            throw new InvalidArgumentException('Invalid lease_fence value');
        }
        return (string)$value;
    }
    if (is_string($value)) {
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException('Invalid lease_fence value');
        }
        $norm = ltrim($value, '0');
        return $norm === '' ? '0' : $norm;
    }
    throw new InvalidArgumentException('Invalid lease_fence value');
}

function immorta_fence_equal($a, $b): bool
{
    return immorta_fence_to_string($a) === immorta_fence_to_string($b);
}

function immorta_fence_compare(string $a, string $b): int
{
    $a = immorta_fence_to_string($a);
    $b = immorta_fence_to_string($b);
    if (strlen($a) !== strlen($b)) {
        return strlen($a) < strlen($b) ? -1 : 1;
    }
    if ($a === $b) {
        return 0;
    }
    return $a < $b ? -1 : 1;
}

function immorta_fence_increment($value): string
{
    $s = immorta_fence_to_string($value);
    $max = '18446744073709551615'; // BIGINT UNSIGNED max
    if (immorta_fence_compare($s, $max) >= 0) {
        throw new RuntimeException('Lease fence overflow');
    }

    $len = strlen($s);
    $carry = 1;
    $res = '';
    for ($i = $len - 1; $i >= 0; $i--) {
        $digit = (int)$s[$i] + $carry;
        if ($digit === 10) {
            $res = '0' . $res;
            $carry = 1;
        } else {
            $res = (string)$digit . $res;
            $carry = 0;
        }
    }
    if ($carry === 1) {
        $res = '1' . $res;
    }
    $res = immorta_fence_to_string($res);
    if (immorta_fence_compare($res, $max) > 0) {
        throw new RuntimeException('Lease fence overflow');
    }
    return $res;
}

// -----------------------------
// Validation payload lease
// -----------------------------

/**
 * Champs requis pour opérations sur bail existant (renew/release/mutations protégées).
 * @return array{ok:bool,job_id?:int,lease_token?:string,lease_fence?:string,code?:string,message?:string,http?:int}
 */
function immorta_require_lease_proof(array $payload): array
{
    $jobId = $payload['job_id'] ?? null;
    $token = $payload['lease_token'] ?? null;
    $fence = $payload['lease_fence'] ?? null;

    if (!is_int($jobId) && !(is_string($jobId) && ctype_digit((string)$jobId))) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid job_id', 'http' => 400];
    }
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid job_id', 'http' => 400];
    }
    if (!is_string($token) || !immorta_is_uuid_string($token)) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid lease_token', 'http' => 400];
    }

    // Fence : entier non négatif ou chaîne décimale ; refus explicite des valeurs négatives/invalides
    if (is_int($fence)) {
        if ($fence < 0) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid lease_fence', 'http' => 400];
        }
        $fenceStr = (string)$fence;
    } elseif (is_string($fence)) {
        if (!preg_match('/^\d+$/', $fence)) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid lease_fence', 'http' => 400];
        }
        $fenceStr = immorta_fence_to_string($fence);
    } else {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid lease_fence', 'http' => 400];
    }

    return ['ok' => true, 'job_id' => $jobId, 'lease_token' => $token, 'lease_fence' => $fenceStr];
}

// -----------------------------
// Accès/locks (ordre obligatoire : worker_leases puis jobs)
// -----------------------------

function immorta_lock_worker_lease(PDO $pdo, string $workerId): ?array
{
    // DDL réel : worker_id (unique/PK), active_job_id, lease_expires_at, lease_fence, updated_at
    $stmt = $pdo->prepare(
        "SELECT worker_id, active_job_id, lease_expires_at, lease_fence, updated_at
         FROM immorta_worker_leases
         WHERE worker_id = :worker_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':worker_id' => $workerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function immorta_ensure_worker_lease_row(PDO $pdo, string $workerId): array
{
    $row = immorta_lock_worker_lease($pdo, $workerId);
    if ($row !== null) {
        return $row;
    }
    // Création registre minimal (colonnes réelles uniquement). updated_at géré par MySQL.
    $stmt = $pdo->prepare(
        "INSERT INTO immorta_worker_leases (worker_id, active_job_id, lease_expires_at, lease_fence)
         VALUES (:worker_id, NULL, NULL, 0)"
    );
    try {
        $stmt->execute([':worker_id' => $workerId]);
    } catch (PDOException $e) {
        // Conflit de création concurrente : relire sous verrou
        if (!isset($e->errorInfo[1]) || (int)$e->errorInfo[1] !== 1062) {
            throw $e;
        }
    }
    $row = immorta_lock_worker_lease($pdo, $workerId);
    if ($row === null) {
        throw new RuntimeException('WORKER_LEASE_ROW_UNAVAILABLE');
    }
    return $row;
}

function immorta_lock_job(PDO $pdo, int $jobId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM immorta_jobs
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function immorta_is_lease_expired(?string $expiresAtUtc): bool
{
    if ($expiresAtUtc === null || $expiresAtUtc === '') {
        return true;
    }
    $ts = strtotime($expiresAtUtc . ' UTC');
    if ($ts === false) {
        return true;
    }
    return $ts <= time();
}

function immorta_worker_has_valid_active_lease(array $workerLease): bool
{
    $activeId = $workerLease['active_job_id'] ?? null;
    $wlExp = $workerLease['lease_expires_at'] ?? null;
    return $activeId !== null && !immorta_is_lease_expired(is_string($wlExp) ? $wlExp : null);
}

// -----------------------------
// Fencing (vérifications communes)
// -----------------------------

/**
 * Vérifie cohérence bail/fence entre registre worker et job (source de vérité = immorta_jobs).
 * Prérequis : lignes worker_leases et jobs déjà verrouillées dans l'ordre requis.
 * Le token est vérifié exclusivement sur immorta_jobs.lease_token (registre sans token).
 */
function immorta_verify_fencing(string $workerId, array $workerLease, array $job, array $proof): array
{
    $jobId = (int)$job['id'];

    $wlActive = $workerLease['active_job_id'] ?? null;
    if ($wlActive === null || (int)$wlActive !== $jobId) {
        return ['ok' => false, 'code' => 'LEASE_MISMATCH', 'message' => 'Lease does not match active job', 'http' => 409];
    }

    $jobOwner = $job['lease_owner'] ?? null;
    if (!is_string($jobOwner) || $jobOwner !== $workerId) {
        return ['ok' => false, 'code' => 'LEASE_FORBIDDEN', 'message' => 'Lease not owned by worker', 'http' => 403];
    }

    $jobToken = $job['lease_token'] ?? null;
    if (!is_string($jobToken) || $jobToken !== $proof['lease_token']) {
        return ['ok' => false, 'code' => 'LEASE_TOKEN_INVALID', 'message' => 'Lease token invalid', 'http' => 409];
    }

    try {
        $jobFenceStr = immorta_fence_to_string($job['lease_fence'] ?? null);
        $wlFenceStr = immorta_fence_to_string($workerLease['lease_fence'] ?? null);
        $proofFenceStr = immorta_fence_to_string($proof['lease_fence']);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'code' => 'LEASE_FENCE_INVALID', 'message' => 'Lease fence invalid', 'http' => 409];
    }
    if ($jobFenceStr !== $proofFenceStr || $wlFenceStr !== $proofFenceStr || $jobFenceStr !== $wlFenceStr) {
        return ['ok' => false, 'code' => 'LEASE_FENCE_INVALID', 'message' => 'Lease fence invalid', 'http' => 409];
    }

    $jobExp = $job['lease_expires_at'] ?? null;
    $wlExp = $workerLease['lease_expires_at'] ?? null;
    if (immorta_is_lease_expired(is_string($jobExp) ? $jobExp : null) || immorta_is_lease_expired(is_string($wlExp) ? $wlExp : null)) {
        return ['ok' => false, 'code' => 'LEASE_EXPIRED', 'message' => 'Lease expired', 'http' => 409];
    }

    // Pool réservé VPS : protection contre manipulation hors périmètre (selon CDC)
    if (isset($job['slot_owner']) && (int)$job['slot_owner'] !== 99) {
        return ['ok' => false, 'code' => 'LEASE_NOT_VPS_POOL', 'message' => 'Job not in VPS pool', 'http' => 409];
    }

    return ['ok' => true];
}

/**
 * Réconciliation contrôlée d'un registre worker pointant vers un bail expiré (C1).
 * - Ne touche pas aux baux valides.
 * - Ne réattribue aucun job.
 * - Ne nettoie le registre que si l'état est prouvé sûr et réconcilié de façon contrôlée.
 * - En cas d'incohérence/non-preuve (job absent, bail job valide, owner/token/fence/slot_owner incohérents),
 *   ne modifie aucune des deux tables et signale BLOCKED_UNSAFE.
 * Prérequis : transaction active, ligne immorta_worker_leases verrouillée (FOR UPDATE). Verrouille
 * immorta_jobs référencé ensuite (ordre worker -> job).
 *
 * @return array{status:string,reason:string,job_id:?int}
 */
function immorta_reconcile_expired_worker_lease(PDO $pdo, string $workerId, array $workerLease): array
{
    $activeId = $workerLease['active_job_id'] ?? null;
    if ($activeId === null) {
        return ['status' => 'NOOP_ALREADY_NEUTRAL', 'reason' => 'NO_ACTIVE_JOB', 'job_id' => null];
    }

    $jobId = (int)$activeId;
    $wlExp = $workerLease['lease_expires_at'] ?? null;
    if (!immorta_is_lease_expired(is_string($wlExp) ? $wlExp : null)) {
        // Bail registre encore valide : ne pas toucher (le claim doit rester bloqué en amont).
        return ['status' => 'NOOP_NOT_EXPIRED', 'reason' => 'REGISTRY_NOT_EXPIRED', 'job_id' => $jobId];
    }

    // Verrouiller le job référencé (ordre worker -> job déjà respecté par l'appelant).
    $job = immorta_lock_job($pdo, $jobId);

    if ($job === null) {
        return ['status' => 'BLOCKED_UNSAFE', 'reason' => 'JOB_NOT_FOUND', 'job_id' => $jobId];
    }

    $jobOwner = $job['lease_owner'] ?? null;
    $jobToken = $job['lease_token'] ?? null;
    $jobExp = $job['lease_expires_at'] ?? null;

    $ownerMatches = is_string($jobOwner) && $jobOwner === $workerId;
    $tokenPresent = is_string($jobToken) && $jobToken !== '';
    $jobExpProvable = is_string($jobExp) && $jobExp !== '' && strtotime($jobExp . ' UTC') !== false;
    $jobExpired = $jobExpProvable && immorta_is_lease_expired($jobExp);
    $slotIs99 = isset($job['slot_owner']) && (int)$job['slot_owner'] === 99;

    $fenceComparable = true;
    $fenceMatches = false;
    try {
        $fenceMatches = immorta_fence_equal($job['lease_fence'] ?? null, $workerLease['lease_fence'] ?? null);
    } catch (InvalidArgumentException $e) {
        $fenceComparable = false;
        $fenceMatches = false;
    }

    // Preuve de sûreté : existence, owner, token présent (non comparé au registre qui n'en stocke pas),
    // fence registre==job, pool VPS 99, bail job expiré de manière prouvable.
    $safe = $ownerMatches
        && $tokenPresent
        && $fenceComparable
        && $fenceMatches
        && $slotIs99
        && $jobExpired;

    if (!$safe) {
        if (!$ownerMatches) {
            $reason = 'OWNER_MISMATCH';
        } elseif (!$tokenPresent) {
            $reason = 'TOKEN_MISSING';
        } elseif (!$fenceComparable || !$fenceMatches) {
            $reason = 'FENCE_MISMATCH';
        } elseif (!$slotIs99) {
            $reason = 'SLOT_OWNER_NOT_99';
        } elseif (!$jobExpProvable) {
            $reason = 'JOB_EXPIRATION_UNPROVABLE';
        } else {
            $reason = 'JOB_LEASE_STILL_VALID';
        }

        return ['status' => 'BLOCKED_UNSAFE', 'reason' => $reason, 'job_id' => $jobId];
    }

    // Invalidation contrôlée du bail job : slot_owner=99 et lease_fence conservés.
    $updJob = $pdo->prepare(
        "UPDATE immorta_jobs
         SET lease_owner = NULL,
             lease_token = NULL,
             lease_expires_at = UTC_TIMESTAMP()
         WHERE id = :id
           AND lease_owner = :owner
           AND lease_token = :token
           AND lease_expires_at IS NOT NULL
           AND lease_expires_at <= UTC_TIMESTAMP()
           AND slot_owner = 99"
    );
    $updJob->execute([
        ':id' => $jobId,
        ':owner' => $workerId,
        ':token' => $jobToken,
    ]);
    if ($updJob->rowCount() !== 1) {
        throw new RuntimeException('LEASE_RECONCILIATION_JOB_UPDATE_FAILED');
    }

    // Nettoyage registre worker (autorisé uniquement après invalidation contrôlée réussie).
    $updW = $pdo->prepare(
        "UPDATE immorta_worker_leases
         SET active_job_id = NULL,
             lease_expires_at = NULL,
             updated_at = UTC_TIMESTAMP()
         WHERE worker_id = :worker_id
           AND active_job_id = :active_job_id"
    );
    $updW->execute([
        ':worker_id' => $workerId,
        ':active_job_id' => $jobId,
    ]);
    if ($updW->rowCount() !== 1) {
        throw new RuntimeException('LEASE_RECONCILIATION_REGISTRY_UPDATE_FAILED');
    }

    return ['status' => 'RECONCILED_SAFE', 'reason' => 'COHERENT_EXPIRED_REVOKED', 'job_id' => $jobId];
}

// -----------------------------
// Claim (B4)
// -----------------------------

/**
 * États claimables : à figer après vérification exhaustive de advance_job()/matrice.
 * Placeholder config : $config['leases']['claimable_states'] (liste blanche stricte).
 */
function immorta_get_claimable_states(array $config): array
{
    $states = $config['leases']['claimable_states'] ?? [];
    if (!is_array($states) || $states === []) {
        // Sécurité par défaut : aucun état autorisé tant que non figé explicitement
        return [];
    }
    $out = [];
    foreach ($states as $s) {
        if (is_string($s) && $s !== '') {
            $out[] = $s;
        }
    }
    return array_values(array_unique($out));
}

function immorta_handle_claim_job(PDO $pdo, array $config, string $workerId, string $requestId, array $payload): array
{
    $claimable = immorta_get_claimable_states($config);
    if ($claimable === []) {
        return ['ok' => false, 'code' => 'CLAIM_NOT_CONFIGURED', 'message' => 'Claimable states not configured', 'http' => 503];
    }
    $ttl = immorta_lease_ttl_seconds($config);

    try {
        $pdo->beginTransaction();

        // 1) Verrou registre worker
        $workerLease = immorta_ensure_worker_lease_row($pdo, $workerId);

        // 2) Worker ne doit pas avoir de job actif (bail valide)
        if (immorta_worker_has_valid_active_lease($workerLease)) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'WORKER_ALREADY_HAS_JOB', 'message' => 'Worker already has an active job', 'http' => 409];
        }

        // 2b) Bail expiré encore référencé dans le registre : réconciliation explicite (pas de prise d'un autre job sans nettoyage)
        $activeId = $workerLease['active_job_id'] ?? null;
        $wlExp = $workerLease['lease_expires_at'] ?? null;
        if ($activeId !== null && immorta_is_lease_expired(is_string($wlExp) ? $wlExp : null)) {
            $reconciliation = immorta_reconcile_expired_worker_lease($pdo, $workerId, $workerLease);

            if (($reconciliation['status'] ?? null) === 'BLOCKED_UNSAFE') {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'code' => 'LEASE_RECONCILIATION_BLOCKED',
                    'message' => 'Worker lease reconciliation blocked',
                    'http' => 409,
                    'data' => [
                        'job_id' => $reconciliation['job_id'] ?? $activeId,
                        'reason' => $reconciliation['reason'] ?? 'UNSAFE',
                    ],
                ];
            }

            if (($reconciliation['status'] ?? null) !== 'RECONCILED_SAFE') {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'code' => 'LEASE_RECONCILIATION_BLOCKED',
                    'message' => 'Worker lease reconciliation blocked',
                    'http' => 409,
                    'data' => [
                        'job_id' => $reconciliation['job_id'] ?? $activeId,
                        'reason' => $reconciliation['reason'] ?? 'UNSAFE',
                    ],
                ];
            }

            $workerLease = immorta_lock_worker_lease($pdo, $workerId);
            if ($workerLease === null) {
                throw new RuntimeException('WORKER_LEASE_ROW_UNAVAILABLE');
            }
        }

        // 2c) Vérification effective de neutralité avant sélection (registre verrouillé, état relu).
        $workerLease = immorta_lock_worker_lease($pdo, $workerId);
        if ($workerLease === null) {
            throw new RuntimeException('WORKER_LEASE_ROW_UNAVAILABLE');
        }
        if (($workerLease['active_job_id'] ?? null) !== null || immorta_worker_has_valid_active_lease($workerLease)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'LEASE_RECONCILIATION_BLOCKED',
                'message' => 'Worker lease reconciliation blocked',
                'http' => 409,
                'data' => [
                    'job_id' => $workerLease['active_job_id'] ?? null,
                    'reason' => 'REGISTRY_NOT_NEUTRAL',
                ],
            ];
        }

        // 3) Sélection job éligible pool VPS (slot_owner=99), état autorisé, retry_at ok,
        //    et libéré proprement (lease_owner/token NULL). Les baux expirés encore détenus ne sont
        //    PAS réattribués automatiquement tant que la procédure de récupération contrôlée n'est pas définie.
        $placeholders = implode(',', array_fill(0, count($claimable), '?'));
        $sql = "SELECT id, lease_fence
                FROM immorta_jobs
                WHERE slot_owner = 99
                  AND state IN ($placeholders)
                  AND (retry_at IS NULL OR retry_at <= UTC_TIMESTAMP())
                  AND lease_owner IS NULL
                  AND lease_token IS NULL
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($claimable);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $pdo->rollBack();
            return ['ok' => true, 'code' => 'NO_JOB_AVAILABLE', 'data' => ['job' => null], 'http' => 200];
        }

        $jobId = (int)$job['id'];
        $currentFence = $job['lease_fence'] ?? 0;
        $newFence = immorta_fence_increment($currentFence);
        $token = immorta_generate_uuid_v4_lower();
        $expiresAt = immorta_lease_expires_at($ttl);

        // 4) Attribution bail sur job (source de vérité) + pool 99 conservé
        $updJob = $pdo->prepare(
            "UPDATE immorta_jobs
             SET lease_owner = :owner,
                 lease_token = :token,
                 lease_expires_at = :exp,
                 lease_fence = :fence,
                 slot_owner = 99
             WHERE id = :id"
        );
        $updJob->execute([
            ':owner' => $workerId,
            ':token' => $token,
            ':exp' => $expiresAt,
            ':fence' => $newFence,
            ':id' => $jobId,
        ]);

        // 5) Synchroniser registre worker (pas de token/heartbeat dédiés dans le registre réel)
        $updW = $pdo->prepare(
            "UPDATE immorta_worker_leases
             SET active_job_id = :job_id,
                 lease_fence = :fence,
                 lease_expires_at = :exp,
                 updated_at = UTC_TIMESTAMP()
             WHERE worker_id = :worker_id"
        );
        $updW->execute([
            ':job_id' => $jobId,
            ':fence' => $newFence,
            ':exp' => $expiresAt,
            ':worker_id' => $workerId,
        ]);

        $pdo->commit();

        // Données minimales ; le worker doit re-vérifier l'état courant avant usage (replay possible)
        return [
            'ok' => true,
            'code' => 'JOB_CLAIMED',
            'http' => 200,
            'data' => [
                'job_id' => $jobId,
                'worker_id' => $workerId,
                'lease_token' => $token,
                'lease_fence' => $newFence,
                'lease_expires_at' => $expiresAt,
                'slot_owner' => 99,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -----------------------------
// Renew lease (B4)
// -----------------------------

function immorta_handle_renew_lease(PDO $pdo, array $config, string $workerId, string $requestId, array $payload): array
{
    $proof = immorta_require_lease_proof($payload);
    if (!$proof['ok']) {
        return $proof;
    }
    $ttl = immorta_lease_ttl_seconds($config);

    try {
        $pdo->beginTransaction();

        // 1) Verrou registre worker
        $workerLease = immorta_lock_worker_lease($pdo, $workerId);
        if ($workerLease === null) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'LEASE_NOT_FOUND', 'message' => 'Worker lease not found', 'http' => 404];
        }

        // 2) Verrou job
        $job = immorta_lock_job($pdo, $proof['job_id']);
        if ($job === null) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'JOB_NOT_FOUND', 'message' => 'Job not found', 'http' => 404];
        }

        // 3) Fencing + expiration
        $check = immorta_verify_fencing($workerId, $workerLease, $job, $proof);
        if (!$check['ok']) {
            $pdo->rollBack();
            return $check;
        }

        $expiresAt = immorta_lease_expires_at($ttl);

        $updJob = $pdo->prepare(
            "UPDATE immorta_jobs SET lease_expires_at = :exp WHERE id = :id"
        );
        $updJob->execute([':exp' => $expiresAt, ':id' => $proof['job_id']]);

        $updW = $pdo->prepare(
            "UPDATE immorta_worker_leases
             SET lease_expires_at = :exp, updated_at = UTC_TIMESTAMP()
             WHERE worker_id = :worker_id"
        );
        $updW->execute([':exp' => $expiresAt, ':worker_id' => $workerId]);

        $pdo->commit();

        return [
            'ok' => true,
            'code' => 'LEASE_RENEWED',
            'http' => 200,
            'data' => [
                'job_id' => $proof['job_id'],
                'worker_id' => $workerId,
                'lease_token' => $proof['lease_token'],
                'lease_fence' => immorta_fence_to_string($proof['lease_fence']),
                'lease_expires_at' => $expiresAt,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -----------------------------
// Release lease (B4)
// -----------------------------

function immorta_handle_release_lease(PDO $pdo, array $config, string $workerId, string $requestId, array $payload): array
{
    $proof = immorta_require_lease_proof($payload);
    if (!$proof['ok']) {
        return $proof;
    }

    // Option contrôlée (si autorisée plus tard) : état cible après libération, whitelist stricte.
    $requestedState = $payload['target_state'] ?? null;
    $allowedReleaseStates = $config['leases']['release_target_states'] ?? [];
    if ($requestedState !== null) {
        if (!is_string($requestedState) || !is_array($allowedReleaseStates) || !in_array($requestedState, $allowedReleaseStates, true)) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid target_state', 'http' => 400];
        }
    }

    try {
        $pdo->beginTransaction();

        $workerLease = immorta_lock_worker_lease($pdo, $workerId);
        if ($workerLease === null) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'LEASE_NOT_FOUND', 'message' => 'Worker lease not found', 'http' => 404];
        }

        $job = immorta_lock_job($pdo, $proof['job_id']);
        if ($job === null) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'JOB_NOT_FOUND', 'message' => 'Job not found', 'http' => 404];
        }

        $check = immorta_verify_fencing($workerId, $workerLease, $job, $proof);
        if (!$check['ok']) {
            $pdo->rollBack();
            return $check;
        }

        // Invalider le bail sur le job. CDC : ne pas remettre automatiquement slot_owner à 0.
        // Le job reste dans le pool VPS (slot_owner=99) avec bail invalidé ; fence conservé.
        if ($requestedState !== null) {
            $updJob = $pdo->prepare(
                "UPDATE immorta_jobs
                 SET lease_owner = NULL,
                     lease_token = NULL,
                     lease_expires_at = UTC_TIMESTAMP(),
                     state = :state
                 WHERE id = :id"
            );
            $updJob->execute([':state' => $requestedState, ':id' => $proof['job_id']]);
        } else {
            $updJob = $pdo->prepare(
                "UPDATE immorta_jobs
                 SET lease_owner = NULL,
                     lease_token = NULL,
                     lease_expires_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $updJob->execute([':id' => $proof['job_id']]);
        }
        // slot_owner non modifié (reste 99), lease_fence non modifié.

        $updW = $pdo->prepare(
            "UPDATE immorta_worker_leases
             SET active_job_id = NULL,
                 lease_expires_at = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE worker_id = :worker_id"
        );
        $updW->execute([':worker_id' => $workerId]);

        $pdo->commit();

        return [
            'ok' => true,
            'code' => 'LEASE_RELEASED',
            'http' => 200,
            'data' => [
                'job_id' => $proof['job_id'],
                'worker_id' => $workerId,
                'released' => true,
                'slot_owner' => 99,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -----------------------------
// Worker heartbeat (B4)
// -----------------------------

/**
 * Signale l'activité du worker (registre). Ne renouvelle pas implicitement le bail sans preuve explicite.
 * - Sans preuve : met à jour updated_at du registre (activité worker).
 * - Avec preuve de bail valide (job_id+token+fence) : vérifie fencing ; renouvelle le bail uniquement si
 *   payload['renew_lease'] === true (strict).
 * Toujours distinct du heartbeat d'idempotence (B3) et ne remplace pas les contrôles lease/fence des mutations.
 */
function immorta_handle_worker_heartbeat(PDO $pdo, array $config, string $workerId, string $requestId, array $payload): array
{
    $hasProof = isset($payload['job_id']) || isset($payload['lease_token']) || isset($payload['lease_fence']);
    $renewLease = array_key_exists('renew_lease', $payload) && $payload['renew_lease'] === true;

    try {
        $pdo->beginTransaction();
        $workerLease = immorta_ensure_worker_lease_row($pdo, $workerId);

        if ($hasProof) {
            $proof = immorta_require_lease_proof($payload);
            if (!$proof['ok']) {
                $pdo->rollBack();
                return $proof;
            }
            $job = immorta_lock_job($pdo, $proof['job_id']);
            if ($job === null) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'JOB_NOT_FOUND', 'message' => 'Job not found', 'http' => 404];
            }
            $check = immorta_verify_fencing($workerId, $workerLease, $job, $proof);
            if (!$check['ok']) {
                $pdo->rollBack();
                return $check;
            }

            if ($renewLease) {
                $ttl = immorta_lease_ttl_seconds($config);
                $expiresAt = immorta_lease_expires_at($ttl);
                $pdo->prepare("UPDATE immorta_jobs SET lease_expires_at = :exp WHERE id = :id")
                    ->execute([':exp' => $expiresAt, ':id' => $proof['job_id']]);
                $pdo->prepare("UPDATE immorta_worker_leases SET lease_expires_at = :exp, updated_at = UTC_TIMESTAMP() WHERE worker_id = :w")
                    ->execute([':exp' => $expiresAt, ':w' => $workerId]);
                $pdo->commit();
                return [
                    'ok' => true, 'code' => 'HEARTBEAT_OK', 'http' => 200,
                    'data' => [
                        'worker_id' => $workerId,
                        'active_job_id' => $proof['job_id'],
                        'lease_expires_at' => $expiresAt,
                        'heartbeat_at' => immorta_lease_utc_now(),
                        'lease_renewed' => true,
                    ],
                ];
            }

            $pdo->prepare("UPDATE immorta_worker_leases SET updated_at = UTC_TIMESTAMP() WHERE worker_id = :w")
                ->execute([':w' => $workerId]);
            $pdo->commit();
            return [
                'ok' => true, 'code' => 'HEARTBEAT_OK', 'http' => 200,
                'data' => [
                    'worker_id' => $workerId,
                    'active_job_id' => $proof['job_id'],
                    'lease_expires_at' => $job['lease_expires_at'] ?? null,
                    'heartbeat_at' => immorta_lease_utc_now(),
                    'lease_renewed' => false,
                ],
            ];
        }

        $pdo->prepare("UPDATE immorta_worker_leases SET updated_at = UTC_TIMESTAMP() WHERE worker_id = :w")
            ->execute([':w' => $workerId]);
        $active = $workerLease['active_job_id'] ?? null;
        $pdo->commit();
        return [
            'ok' => true, 'code' => 'HEARTBEAT_OK', 'http' => 200,
            'data' => [
                'worker_id' => $workerId,
                'active_job_id' => $active !== null ? (int)$active : null,
                'heartbeat_at' => immorta_lease_utc_now(),
                'lease_renewed' => false,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -----------------------------
// Entrée handler B4 (dispatch)
// -----------------------------

function immorta_handle_lease_action(PDO $pdo, array $config, string $action, string $workerId, string $requestId, array $payload): array
{
    switch ($action) {
        case 'claim_job':
            return immorta_handle_claim_job($pdo, $config, $workerId, $requestId, $payload);
        case 'renew_lease':
            return immorta_handle_renew_lease($pdo, $config, $workerId, $requestId, $payload);
        case 'release_lease':
            return immorta_handle_release_lease($pdo, $config, $workerId, $requestId, $payload);
        case 'worker_heartbeat':
            return immorta_handle_worker_heartbeat($pdo, $config, $workerId, $requestId, $payload);
        default:
            return ['ok' => false, 'code' => 'UNKNOWN_ACTION', 'message' => 'Action not handled', 'http' => 400];
    }
}
