# Audit v184 → API (lecture seule)

**Sources auditées (local)** : `immorta_link_v184_up4ever.php` (4419 l.), `script cron/run_immorta_slots170.sh`, `decron.php`, `api/CAHIER_DES_CHARGES_IMMORTA_v184.md`.  
**Périmètre** : analyse/correspondance uniquement. Aucun fichier PHP créé/modifié hors documentation, aucun déploiement, aucun changement `torbox_change.php`/`decron.php`/crons/dashboard/prod.

## 1. Script de lancement (mutu) — constats

`script cron/run_immorta_slots170.sh` (tel que fourni) :
```bash
LOG="/home/pixelwar/public_html/scripts/logs/cron_immorta.log"
SCRIPT="/home/pixelwar/public_html/scripts/torbox_change.php"
LOCK="/home/pixelwar/public_html/scripts/logs/run_immorta_slots170.lock"
exec 9>"$LOCK"; flock -n 9 || exit 0
for i in $(seq 1 1); do /usr/bin/php "$SCRIPT" "slot=$i" >> "$LOG" 2>&1 & done
wait
```
- **Cible réelle** : `torbox_change.php` (pipeline TorBox legacy actuel), **pas** `immorta_link_v184_up4ever.php`.
- **Concurrence** : commentaire annonce 5 traitements (`MAX_SLOTS=5`) mais boucle `seq 1 1` ⇒ **slot 1 uniquement** lancé par ce wrapper.
- **Verrous** : flock global wrapper (`run_immorta_slots170.lock`) + le worker gère ses propres locks par slot `logs/immorta_link.lock.{N}` (`LOCK_EX|LOCK_NB`, PID:ts, non supprimés si périmés).
- **Lancement v184** : v184 accepte `?slot=N` (web) ou argv `slot=N` (CLI) et `MAX_SLOTS=5`. Migration VPS ⇒ nouveau wrapper/cron dédié (script/chemins/env différents), séparé du legacy (rollback = arrêt worker VPS + cron VPS).

`decron.php` (rollback local) : simule CLI `slot` (1..5, défaut 1) et `require scripts/torbox_change.php` (protégé token + `?voir=1` pour logs).

## 2. Modèle runtime v184 (tel qu’implémenté)

- **Entrée** : `slot` résolu (demandé ou fallback 1..5), un slot par exécution ; shutdown libère le slot ; `set_time_limit(0)`.
- **DB** : PDO local MySQL `pixelwar_12` (`DB_HOST localhost`), `ERRMODE_EXCEPTION`, `SET time_zone='+00:00'`. Migrations idempotentes au boot (`CREATE TABLE IF NOT EXISTS`, nombreux `ALTER TABLE immorta_jobs ADD COLUMN/INDEX`).
- **Config** : `immorta_config` override (Up4ever, MixLoads slots 1/2, FileAL/FileQ slots, seedbox proxy) avec fallback constantes. Secrets/API keys présents dans le script/config (à traiter comme sensibles ; ne pas exposer/logguer côté API).
- **Claim/ownership (slot, non lease)** : `get_active_job($pdo,$my_slot)` reprend un job actif du slot (`state NOT IN done/error/renewed/paused`, `slot_owner=$my_slot`, `retry_at` NULL ou passé, priorité états upload/poll) sinon claim atomique en tx : `SELECT id … WHERE slot_owner=0 AND (retry_at IS NULL OR retry_at<=NOW()) ORDER BY id ASC LIMIT 1 FOR UPDATE` puis `UPDATE immorta_jobs SET slot_owner=? WHERE id=?`.
- **`update_job`** : auto-libère `slot_owner=0` si passage vers `new|done|error|renewed` (et sur `retry_at` posé hors états actifs `mixloads_uploading, up4ever_uploading, multiup_remote_poll`) ; à `done|error` nettoie Nextcloud share/fichier et Offcloud request si présents ; mise à jour générique par whitelist implicite des clés passées (aucune validation centrale côté worker).
- **Création/découverte M1** : réconciliation des portions manquantes par post (1 job par lien/hide, `source_url/hash/hide_index`, `job_type` m1/up4ever selon source), claim de nouveau post sous tx + `SELECT … FOR UPDATE`, création job(s) `slot_owner=$my_slot`, insertion idempotente via hash/existence. Auto-renew (si pas de nouveaux posts éligibles) : verrou `UPDATE … state='renewing' WHERE id=? AND state='done'` (rowCount==1) puis création job `new`.
- **Exécution** : `advance_job(&$pdo,$job)` = machine d’états (switch) avec effets externes + `update_job` ; le flux principal traite le job actif/réclamé du slot.

## 3. Tables/champs BDD touchés par v184

| Table | Usage v184 | Remarque migration |
|---|---|---|
| `immorta_jobs` | cœur pipeline (claim, état, retries, URLs/codes/liens, pid/files, `slot_owner`, `source_*`, up4ever/mixloads/fileal/fileq/multiup/onfichier, timestamps, `done_at`) | ajouter contrôle lease/fence/états/champs autorisés côté API ; `slot_owner=99` réservé pool VPS |
| `immorta_config` | lecture config/slots/clés/proxy | lecture via API (ou config worker non-DB selon décision), pas d’écriture arbitraire |
| `immorta_link_errors` | blocage/retry/erreurs permanentes par `post_id` (`retry_after`, type/message, `fail_count`) | mutations via API avec règles explicites |
| `immorta_up4ever_uploads` | gate quota 24h local (`GET_LOCK('immorta_up4ever_upload_cap',5)`, purge 25h, insert/count fenêtre) | à remplacer/cadrer par `quota_acquire/status` + `immorta_up4ever_quota_attempts` + idempotence |
| `immorta_up4ever_quota_attempts` | (migration) non utilisé par v184 | cible quota API |
| `immorta_api_idempotency`, `immorta_finalize_intents`, `immorta_worker_leases` | non utilisés par v184 | socle API/leases/idempotence/finalisation |
| `nezo_posts` | lecture message (source/miroirs), **écriture** `UPDATE nezo_posts SET message=? WHERE id=?` (injection/remplacement miroirs, post_update/M2) | doit passer par `update_post` CAS (NULL/''/non-vide/égal) ; pas de `applied` sur `ROW_COUNT` ambigu |
| `nezo_topics` | lecture `forum_id`/jointure éligibilité | **lecture seule** |
| `immorta_m2_checks` etc. | hors logique cœur observée ici | hors périmètre sauf besoin prouvé |

## 4. Machine d’états v184 (inventaire observé) et transitions clés

> Transitions à **confirmer exhaustivement** branche par branche avant figer la matrice API (CDC §12). États listés d’après `case`/usages repérés.

| État | Rôle (résumé) | Sorties typiques | Protection API requise |
|---|---|---|---|
| `new` | résolution source (`source_url` ou extraction post), pré-check alive (1fichier/ddownload), routage | `multiup_remote_start` (source up4ever) ou `pyload_waiting` (ajout pyLoad) ou `error`/retry `new` | lease + idempotence création effet externe (pyLoad) ; pas de double-dispatch |
| `pyload_waiting` | poll package pyLoad, partage Nextcloud, source seedbox | avancement vers upload/mirror, retry, ou erreur/reset selon branche | idempotence/reprise poll ; mutations champées |
| `offcloud_waiting` | poll Offcloud (chemin legacy/alternatif) | suite pipeline/retry | idem |
| `torbox_waiting` | poll TorBox (chemin legacy/alternatif) | suite pipeline/retry/timeout | idem |
| `up4ever_start` | choix source (TorBox/MixLoads/direct/seedbox), gate quota 580/24h, init remote upload | `up4ever_uploading`, `post_update` ou fallback `mixloads_start`/`fileal_start` selon job/taille/source, retry | `quota_acquire` côté API, idempotence init, pas de double upload |
| `up4ever_uploading` | poll/corrélation code+taille/nom, timeout 180 min, retries (max 3) | `post_update`/fallback ou relance `up4ever_start` | reprise même tentative logique (`up4ever_attempt_id` stable) vs nouvelle tentative |
| `mixloads_start` | init upload MixLoads (slots/rotation, slot2/M2 paths, preflight, oversize) | `mixloads_uploading`, retry, fallback/erreur | idempotence effets externes, limite taille MixLoads (32 Go) |
| `mixloads_uploading` | poll disponibilité/intégrité/troncature, gestion code/slot | suite vers multiup/fileal/post_update ou relance | idem |
| `parallel_uploading` | compat rétro (redirige) | `mixloads_uploading` ou `mixloads_start` | matrice de compat explicite |
| `pixeldrain_*` (compat) | redirections legacy | `mixloads_*` | idem |
| `fileal_start` / `fileal_uploading` | miroir/étape FileAL | suite/retry | lease + idempotence |
| `fileq_start` / `fileq_uploading` | miroir/étape FileQ | suite/retry | idem |
| `up4ever_to_onfichier_start` | régénération 1fichier depuis source Up4ever (M2) | polling/étapes onfichier | CAS post + idempotence |
| `onfichier_reupload_start` / `onfichier_reupload_polling` | remote upload 1fichier et suivi | MAJ post/état final ou retry | idem |
| `of_to_ml_start` / `of_to_ml_uploading` | remplacement 1fichier mort par MixLoads 3 (M2) | MAJ post + `done` ou retry | CAS post |
| `multiup_remote_start` | sélection source (TorBox/MixLoads direct/public/Nextcloud/Up4ever direct/source), submit Multiup, hosts/retries | `multiup_remote_poll`, retry, `multiup_failed`, ou `post_update` | idempotence submit remote, pas de re-soumission aveugle |
| `multiup_remote_poll` | check-file, not_found/resubmit/timeout, hosts ready | `post_update` (avec/sans lien), re-soumission contrôlée | reprise sûre |
| `post_update` | publication finale des miroirs dans `nezo_posts.message`, notifications, marquage | `done` (ou chemin erreur selon cas) | **CAS obligatoire**, intention/finalisation durable, nettoyage NC/offcloud au done/error |
| `error`, `done`, `renewed`, `renewing`, `paused`, `multiup_failed` (et autres rencontrés) | terminaux/spéciaux/compat | selon règles v184 | liste blanche stricte, pas d’auto-reset `slot_owner=0` hors règles API |

**Non-finalités actives (slot conservé en retry/poll)** : notamment `mixloads_uploading`, `up4ever_uploading`, `multiup_remote_poll` (cf. `get_active_job`/`update_job`).

## 5. Effets externes (à exécuter côté worker, résultats persistés via API)

- **Seedbox** : pyLoad (`add_package`/poll/data/restart/delete), Nextcloud (share create/delete, file delete), Offcloud (add/status/explore/remove), proxy seedbox via config.
- **Téléchargement/sources** : TorBox (webdownload/list/link/delete, lockout), 1fichier/DDownload checks/remote upload, Up-4ever (remote upload, file/info, direct link, find recent), MixLoads, FileAL, FileQ, Multiup.
- **Publication** : modification `nezo_posts.message` (miroirs TorBox/MixLoads/Up4ever/Multiup/1fichier etc.) ; Telegram notify.
- **Fichiers locaux/logs** : `scripts/logs/` (cron log, locks, `fatal_error.log`, `immorta_status.json`, fichiers start/result divers).

## 6. Correspondance v184 → opérations API (cible)

| Fonction/rôle v184 | Données lues/écrites | Opération API cible | États avant→après (indicatif) | Protections / échecs |
|---|---|---|---|---|
| `get_active_job` + claim tx `FOR UPDATE` | `immorta_jobs` (éligibilité `slot_owner=99`, état, retry), `immorta_worker_leases` | `claim_job` (+ `get_job`) | éligible → attribué (bail/fence/token) | 1 job actif/`worker_id`, ordre lock `worker_leases→jobs`, attribution unique |
| Boucle slot/reprise traitement | job courant + bail | `renew_lease`, `worker_heartbeat` | inchangé | refus si bail/fence/token invalide ou expiré |
| `update_job` (champs/state/retry/URLs/codes/compteurs) | sous-ensemble colonnes autorisées `immorta_jobs` | `update_job` | conforme matrice v184 | whitelist états+colonnes, fence/token, pas de SQL libre, pas de modif libre `slot_owner` |
| Abandon/libération contrôlée | bail + job | `release_lease` | actif → état libérable autorisé (à définir) | invalide bail ; `release_lease` conserve propriété pool selon règle CDC |
| `log_error` / `clear_error` / skip | `immorta_link_errors` | `update_job`/op dédiée (à figer) ou extension contrôlée | n/a | règles retry permanent/temporaire explicites |
| Gate Up4ever `up4ever_reserve_upload_slot` (`GET_LOCK` + fenêtre 24h) | `immorta_up4ever_uploads` (+ tentative) | `quota_acquire`, `quota_status` | n/a | 580/24h, refus temporaire ≠ définitif, `up4ever_attempt_id` stable, idempotence, tx+verrou cohérent |
| Écritures `nezo_posts.message` | `nezo_posts` | `update_post` (CAS) | n/a | attendu NULL/''/non-vide/égal, pas de succès sur `ROW_COUNT` ambigu/seule relecture, `nezo_topics` RO |
| `post_update` / étapes finales M2 | `immorta_jobs`, `immorta_link_errors`, `nezo_posts`, effets externes terminés | `prepare_finalize` → `commit_finalize` | pré-final → `done`/état final autorisé | intention durable récupérable, adoption sous tx+`prepared`+fence+cible, commit idempotent |
| Reprise après incertitude réseau | résultat requêtes précédentes | `get_request_status` + idempotence locale worker | inchangé | même `request_id`+même payload ⇒ même résultat ; payload différent ⇒ conflit ; pas de rejeu aveugle |
| Nettoyages NC/Offcloud à `done|error` (dans `update_job`) | `nextcloud_*`, `offcloud_request_id` | soit `update_job` déclencheur API, soit étape finalize explicite | final | idempotent, après commit logique autorisé |

## 7. Règles/protections à ajouter (écarts v184 vs CDC)

- **Leases/fencing** : `lease_owner/token/expires_at/fence` (colonnes prêtes), registre `immorta_worker_leases`, renouvellement, refus ancien fence.
- **Idempotence forte** : `request_id` stable par opération mutante, stockage/récupération `immorta_api_idempotency`, gestion in-flight/expired/conflit.
- **Finalisation durable** : `immorta_finalize_intents` (prepare/commit/adoption) pour éviter double finalisation ou perte après interruption.
- **Autorité API** : whitelist stricte états/colonnes/transitions (issue du code réel), validation paramètres, requêtes préparées, UTC systématique, aucune mutation arbitraire de `slot_owner`/leases, pas de SQL libre.
- **Concurrence** : 1 job actif par `worker_id` (recommandé CDC), sérialisation claim par worker, ordre de verrous `immorta_worker_leases` → `immorta_jobs`.
- **Sécurité** : HMAC-SHA256 + horodatage + anti-rejeu/id requête, secret jamais public, pas de fuite interne en réponse/log.

## 8. Points ouverts à figer avant développement (CDC §12 + audit)

1. **Wrapper/entrée migration** : v184 n’est pas lancé par le cron actuel (`torbox_change.php` + `seq 1 1`) ; définir wrapper VPS, concurrence, chemins, et mapping `slot` worker vs pool `slot_owner=99`.
2. **Matrice d’états/transitions** : extraire/valider **toutes** les branches de `advance_job` (y compris compat, M2, erreurs, retries, `multiup_failed`, `renewing/renewed/paused`) pour la liste blanche API.
3. **Durées bail/renewal** : calibrer sur traitements longs (pyLoad/Up4ever polling 180 min, MixLoads/Multiup/onfichier) avec marge.
4. **CAS `update_post`** : sémantique exacte `applied` vs `already_applied` sans `expected_md5` quand `expected_is_null=true` ; canonicalisation `payload_hash` côté serveur.
5. **Quota/adoption/reprises** : refus capacité temporaire vs refus durable `(job_id,quota_epoch)` sous même verrou+tx InnoDB ; adoption sous tx+`prepared`+fence+`target_state` ; distinguer reprise même traitement vs nouvelle tentative logique.
6. **États claimables pool VPS** : confirmer la liste exacte (et traitement des jobs `slot_owner=0` historiques : hors périmètre automatique, mais règle de bascule à définir).
7. **Migrations runtime au boot** : v184 fait des `ALTER/CREATE` en exécution ; côté API/worker cible, figer le schéma (déjà posé) et éviter DDL opportuniste en prod.
8. **Gestion `immorta_link_errors`/nettoyages** : formaliser comme opérations API atomiques/idempotentes liées aux transitions.

## 9. Conclusion d’étape

- Étapes CDC réalisées côté doc : **#5 réconcilié**, **audit lecture seule v184 + script de lancement** effectué (avec constat wrapper legacy/slot1).
- Prochaines étapes (avec validation explicite) : produire/valider la **matrice exhaustive états/transitions** et fermer les points ouverts ci-dessus, puis démarrer le **socle API (B)** et la chaîne lease/idempotence/quota/finalisation selon l’ordre CDC, en tests isolés. Pas de modification/déploiement production sans accord.