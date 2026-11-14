<?php
declare(strict_types=1);

/**
 * Handler M0 de vérification.
 * Aucun effet métier, aucune lecture/écriture DB, aucun accès provider/worker/lease.
 * Le payload est volontairement ignoré par le résultat ; il reste hashé par l'idempotence.
 */
function immorta_handle_m0_probe(array $payload): array
{
    return [
        'probe' => true,
        'action' => 'm0_probe',
    ];
}
