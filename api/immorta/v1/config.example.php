<?php
declare(strict_types=1);

/**
 * Configuration exemple — IMMORTA API v1 (mutualisé).
 * Copier en config.php sur le serveur (hors diffusion publique des secrets).
 * Ne pas commiter config.php avec des secrets réels.
 * IMPORTANT : l'API refuse de démarrer sans config.php réel (cet exemple n'est pas chargé en production).
 */
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'pixelwar_12',
        'user' => 'DB_USER',
        'pass' => 'DB_PASS',
        'charset' => 'utf8mb4',
    ],
    // Fenêtre de validité horodatage (secondes, anti-rejeu temporel)
    'auth' => [
        'timestamp_skew_seconds' => 300,
        // Chemin canonique HMAC figé selon le déploiement (ne jamais faire confiance à un header client).
        // Doit correspondre exactement au PATH signé par le worker.
        'canonical_path' => '/api/immorta/v1/index.php',
        // UUID (request_id) et signature HMAC : hexadécimal minuscule côté worker (contrat figé).
        // worker_id => secret HMAC (un secret par worker)
        'workers' => [
            // 'vps-worker-01' => 'CHANGE_ME_SECRET',
        ],
    ],
    'api' => [
        // Limite taille corps HTTP avant décodage JSON (octets)
        'max_body_bytes' => 1048576,
        // Le probe M0 est volontairement désactivé par défaut.
        'm0_probe_enabled' => false,
        // Liste blanche de l'enveloppe ; le dispatch applique en plus le registre M0.
        'allowed_actions' => [
            'm0_probe',
            'claim_job',
            'get_job',
            'renew_lease',
            'release_lease',
            'update_job',
            'update_post',
            'quota_acquire',
            'quota_status',
            'prepare_finalize',
            'commit_finalize',
            'get_request_status',
            'worker_heartbeat',
        ],
    ],
    'idempotency' => [
        // Durée de vie d'une entrée en cours avant considération "expirée" (secondes) — pas de rejeu aveugle
        'in_progress_ttl_seconds' => 600,
    ],
];
