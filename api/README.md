# `api/` — API PHP IMMORTA (mutualisé, cible)

Dossier de travail pour la migration IMMORTA v184 (Option 2 : worker VPS + API HTTPS sur mutu).

## Références
- `CAHIER_DES_CHARGES_IMMORTA_v184.md` — spécification officielle (architecture, opérations, contrat, sécurité, tests).

## Contraintes clés
- Le VPS ne se connecte jamais directement à MySQL ; l'API est l'unique autorité sur les données/états.
- PDO local uniquement, `SET time_zone='+00:00'`, `UTC_TIMESTAMP()`, requêtes préparées, liste blanche états/colonnes.
- Pool cible : `slot_owner=99` réservé VPS (jamais auto-reset à 0).
- Aucun changement de `torbox_change.php`, `decron.php`, crons/dashboard legacy sans validation explicite.

## État
- Schéma DB migration (tables idempotency/finalize/quota/leases, colonnes lease/fence/quota sur `immorta_jobs`) posé sur `pixelwar_12` ; **post-ALTER #5 (`immorta_jobs`) validé** sur la base actuelle (résultats fournis : `SHOW CREATE TABLE` + colonnes/types/défauts/ordre + index `idx_lease_expires_at`/`idx_lease_owner`/`idx_up4ever_attempt` + agrégats 3797 lignes, champs lease/quota/`lease_fence_nonzero`/`slot_owner_99` = 0, structure existante préservée).
- **Audit lecture seule v184 réalisé** (`AUDIT_V184_VERS_API.md`) : script de lancement `script cron/run_immorta_slots170.sh` (legacy `torbox_change.php`, wrapper `seq 1 1`), modèle runtime/claim/états/effets externes/quota/CAS et correspondance v184→API ; matrice exhaustive transitions et points ouverts à figer avant développement.
- Développement API/worker : à démarrer après validation de la matrice états/transitions et des points ouverts (bail/renewal, CAS/quota/adoption/idempotence, états claimables, wrapper VPS). Pas de déploiement sans accord explicite.
