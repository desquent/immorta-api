# IMMORTA API v1 — socle (B1 ajusté, pré-B3)

Code local de travail (**non déployé**). Aucun script historique remplacé, aucune modification BDD/prod/cron.

## Fichiers
- `index.php` — point d’entrée : `POST` uniquement, **`config.php` réel obligatoire** (pas de fallback exemple → `503`), limite taille corps (`api.max_body_bytes`, défaut 1 Mo, contrôles `Content-Length` + taille réelle → `413/400`), JSON strict, validation enveloppe, auth HMAC, **une seule connexion PDO** (`SET time_zone='+00:00'`), gestion globale exceptions/erreurs → réponse générique `500`, dispatch central réservé (handlers en fonctions séparées, à brancher). Retour `501 NOT_IMPLEMENTED` après auth+DB tant qu’aucun handler n’est branché.
- `config.example.php` — gabarit (DB, `auth.timestamp_skew_seconds`, `auth.canonical_path` figé, `auth.workers`, `api.max_body_bytes`, `api.allowed_actions`, `idempotency.in_progress_ttl_seconds`). **Copier en `config.php`** (secrets hors public) ; jamais de secrets réels/versionnés.
- `lib/bootstrap.php` — réponses JSON uniformes (`{success,request_id,code,data,error}`, terminaison `never/exit`), lecture corps brut bornée, validation enveloppe stricte + liste blanche actions, `payload` objet.
- `lib/db.php` — `immorta_db_connect()` PDO (`ERRMODE_EXCEPTION`, préparations natives, `utf8mb4`) + `SET time_zone='+00:00'` à chaque connexion. Toutes les connexions API doivent passer par cette fonction ; connexion réutilisée dans la requête.
- `lib/auth.php` — HMAC-SHA256 sur corps brut exact : en-têtes `X-Immorta-Worker-Id`, `X-Immorta-Timestamp` (Unix UTC, entier strict), `X-Immorta-Request-Id`, `X-Immorta-Signature` (**hex minuscule** `[a-f0-9]{64}`) ; canonical `METHOD\nPATH\nTIMESTAMP\nREQUEST_ID\nSHA256(RAW_BODY)` ; `PATH` = `auth.canonical_path` figé en config (**jamais** depuis header client/`REQUEST_URI`) ; contrôle skew, cohérence header/body, `hash_equals`. **Signature valide ≠ protection anti-rejeu** (B3).

## Contrat figé (pré-B3)
- **`request_id`** : UUID canonique 36 caractères (`xxxxxxxx-xxxx-Mxxx-Nxxx-xxxxxxxxxxxx`, hex minuscule), conforme `immorta_api_idempotency.request_id` `CHAR(36)`.
- **HMAC** : `METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + REQUEST_ID + "\n" + SHA256(RAW_BODY)` (méthode majuscule, PATH canonique exact, timestamp secondes, signature hex minuscule). Worker et API doivent produire la même chaîne.
- **Whitelist** : actions explicitement autorisées en config ; aucune action inconnue, aucun SQL/noms de tables/colonnes arbitraires depuis le VPS.
- **Séparation** : auth → (B3 idempotence) → dispatch → handlers ; une connexion PDO par requête.

## Non implémenté (étapes suivantes)
- **B3 Idempotence** (prioritaire, avant mutations) : `immorta_api_idempotency` (nouveau/conflit payload/in_progress/expiré, hash canonique payload côté serveur, restitution réponse, pas de rejeu aveugle ni suppression lignes `in_progress` sur simple expiration), `get_request_status` (owner-only, sans récursion idempotence). L’idempotence API ne remplace pas la réconciliation des effets externes.
- **B4+** : leases/fencing (`immorta_worker_leases`↔`immorta_jobs`, ordre verrous, pool `slot_owner=99`), claim/renew/release, `update_job` whitelist+matrice états, quota Up4ever, `update_post` CAS, finalisation durable — chaque brique testée isolément.

## Tests isolés (après accord, env dédié, jamais jobs prod/M0-M2/dashboard/crons legacy)
- Socle : `405` non-POST ; `400` JSON/enveloppe/`request_id` non canonique ; `413` corps trop grand ; `401` auth manquante/skew/signature/path/config invalide ; `503` sans `config.php` réel ou DB indispo ; `501` action autorisée + auth + DB OK.
- B3+ : cf. plan (conflit, in_progress concurrent, réponse perdue, expiration, owner, leases/fence, quota, CAS, finalisation).