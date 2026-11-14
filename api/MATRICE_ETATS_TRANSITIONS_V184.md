# Matrice états / transitions — `immorta_link_v184_up4ever.php`

**Statut :** audit lecture seule du code local v184 (4419 l.) + `script cron/run_immorta_slots170.sh`. Document de référence ; **matrice non figée** tant que les branches secondaires/helpers ne sont pas toutes revérifiées.
**Légende :** `Vérifiée` = confirmée dans le code lu. `À confirmer` = transition/effet dépend d’une branche/helper non entièrement tracée ou d’un comportement runtime externe. `Exclue` = hors périmètre cible VPS (pool `slot_owner=99` / worker API) — à traiter explicitement avant claim.
**Règles CDC rappel :** pas de MySQL direct VPS ; `slot_owner` numérique historique ≠ propriété (cible: pool 99 + `worker_id`/lease/fence) ; pas de DDL boot ; effets externes idempotents/réconciliables ; `post_update` = finalisation (CAS, intention, commit, nettoyage) ; pas de modif `torbox_change.php`/`decron.php`/crons/dashboard/prod.

## 1. Lancement / ownership (hors `advance_job`)

| État initial | Conditions | Traitement | État suivant | Retry | Effet externe | Persistance | Protection API | Reprise | Statut |
|---|---|---|---|---|---|---|---|---|---|
| (process) | Slot demandé `?slot`/argv `slot=N` (1..`MAX_SLOTS=5`) ou fallback 1..5 | Prise `flock` non-bloquant `immorta_link.lock.{slot}` (PID:ts), lock périmé journalisé non supprimé, shutdown libère | slot actif ou exit | n/a | FS locks/logs | fichiers lock | cron/wrapper VPS dédié (pas legacy) | redémarrage cron | Vérifiée |
| non final, `slot_owner=$my_slot` | `get_active_job`: job actif du slot, `state NOT IN (done,error,renewed,paused)`, `retry_at IS NULL OR <= NOW()`, priorité `mixloads_uploading,up4ever_uploading,multiup_remote_poll` | reprise du job du slot | inchangé | n/a | — | lecture `immorta_jobs` | claim/lease API sur pool 99, `worker_id` unique | idempotent (relecture état) | Vérifiée |
| `slot_owner=0`, éligible | aucun job actif slot ; tx `SELECT id … slot_owner=0 AND retry_at ok … FOR UPDATE` puis `UPDATE slot_owner=?` | claim atomique d’un job libre | inchangé (owner=slot) | n/a | — | `immorta_jobs.slot_owner` | `claim_job` (leases→jobs, fence/token, 1 job/worker) | tx atomique | Vérifiée |
| (aucun) | `advance_job` appelé sur job actif ; `update_job` passe vers `new,done,error,renewed` OU `retry_at` posé hors états actifs upload/poll | libération slot (`slot_owner=0` défaut), nettoyage Nextcloud share/fichier + Offcloud remove si `done|error` et IDs présents | selon transition | selon transition | Nextcloud delete, Offcloud remove | `immorta_jobs` (+ side effects) | `release_lease`/finalize contrôlé, pas auto-reset arbitraire | nettoyage doit être idempotent | Vérifiée |
| (discovery M1) | réconciliation portions manquantes post (liens/hide >=2) ; création si `source_hash` absent | `immorta_reconcile_missing_source_jobs` insert `state='new'`, `slot_owner=0`, `source_*` (job_type up4ever si lien up4ever sinon type passé) | `new` | n/a | — | `immorta_jobs` | création via API/pool 99, idempotence hash | doublon évité par check hash + catch duplicate | Vérifiée |
| (discovery M1) | pas de job actif ; pas de priorité auto-requeue bloquante ; post éligible trouvé (tx + `FOR UPDATE`) | extraction liens ; insert jobs `new` (un par lien, hash, hide_index, slot_owner=slot) ou fallback/`MIRROR_ONLY` dans `immorta_link_errors` | `new` (créés) | n/a | — | `immorta_jobs`, `immorta_link_errors` | attribution pool 99/lease, règles éligibilité figées | idempotent par hash/existence | Vérifiée |
| `done` (renew candidat) | pas de nouveaux posts ; job `done` + `multiup_link`, ancienneté >=20j, pas d’autre job actif post | tx logique: `UPDATE state='renewing' WHERE id=? AND state='done'` (rowCount==1) puis insert nouveau job `('new', job_type)` puis ancien → `renewed` | `renewing`→`renewed` (ancien), nouveau `new` | n/a | — | `immorta_jobs` | état transitoire explicite, idempotence création | safe si rowCount!=1 | Vérifiée |
| `done` (orphelins/requeue) | conditions M0 (sans MixLoads, sans Multiup, slot2 manquant, délais/compteurs) et pas de nouveaux posts prioritaires | `update_job` remet état cible et compteurs puis `advance_job` immédiat (ou insert `m1_slot2`) | `mixloads_start` / `multiup_remote_start` / `mixloads_start`(m1_slot2 créé) | compteurs `pd_requeue_count/mu_requeue_count` bornés | selon état cible | `immorta_jobs` | claimable/policy à confirmer (jobs historiques) | garde-fous compteurs/délais | Vérifiée |

## 2. Machine d’états `advance_job` (switch)

| État initial | Conditions | Traitement | État suivant | Retry | Effet externe | Persistance | Protection API | Reprise | Statut |
|---|---|---|---|---|---|---|---|---|---|
| `new` | post introuvable | — | `error` (`Post introuvable`) | non | — | `immorta_jobs.state/error_msg` | terminal contrôlé | n/a | Vérifiée |
| `new` | aucune URL source exploitable | `log_error(URL_NOT_FOUND)` | `error` | non (permanent) | — | `immorta_jobs`, `immorta_link_errors` | règle erreur permanente | n/a | Vérifiée |
| `new` | source 1fichier morte (`onfichier_check_alive` false) | `log_error(DEAD_LINK)` | `error` | non | HTTP/API 1fichier | jobs+errors | idempotent | n/a | Vérifiée |
| `new` | source ddownload morte | `log_error(DEAD_LINK)` | `error` | non | HTTP ddownload | jobs+errors | idempotent | n/a | Vérifiée |
| `new` | `source_type==up4ever` | extrait code, set filename/size | `multiup_remote_start` | n/a | — | `state,filename,expected_size` | transition autorisée | sûr | Vérifiée |
| `new` | source 1fichier/ddownload vivante, ajout pyLoad échoue | `log_error(PYLOAD_ADD_FAILED, retry_hours=0)` | `new` (`retry_at` +5min, reset `torbox_error_count`) | implicite par retry_at | pyLoad `add_package` | `state,retry_at,error_msg,torbox_error_count` | **idempotence effet externe** (paquet possiblement créé mais réponse perdue) | risque doublon paquet → réconciliation requise | Vérifiée |
| `new` | ajout pyLoad OK | `pyload_restart_package` | `pyload_waiting` | n/a | pyLoad add+restart | `pyload_package_id`, reset champs cdn/erreurs/retry | idempotence/reprise | poll ultérieur | Vérifiée |
| `pyload_waiting` | `pyload_package_id` manquant | — | `error` | non | — | `state,error_msg` | — | n/a | Vérifiée |
| `pyload_waiting` | package introuvable/vide, `missing_retries>=10` | reset | `new` (`retry_at` +30s, clear pkg, reset compteur) | oui (recréation) | pyLoad `package_data` | `state,pyload_package_id,error_msg,retry_at,torbox_error_count` | éviter boucle/recréations aveugles | incertain si effets partiels | Vérifiée |
| `pyload_waiting` | package introuvable/vide, <10 | — | `pyload_waiting` (`retry_at` +60s, compteur++) | oui | pyLoad | `retry_at,torbox_error_count` | — | poll | Vérifiée |
| `pyload_waiting` | status pyLoad échec (8/4/9) | `log_error(PYLOAD_ERROR)`, `pyload_delete_package` | `error` | non | pyLoad delete | `state,error_msg,pyload_package_id` | idempotent delete | n/a | Vérifiée |
| `pyload_waiting` | status != 0 (pas fini) | — | `pyload_waiting` (`retry_at` +60s) | oui | pyLoad | `retry_at` | — | poll | Vérifiée |
| `pyload_waiting` | fini mais création partage Nextcloud échoue (tous candidats) | — | `pyload_waiting` (`retry_at` +60s) | oui | pyLoad, Nextcloud share create | `retry_at` | idempotence création share | possible partage orphelin si réponse perdue (À confirmer) | Vérifiée |
| `pyload_waiting` | fini + partage Nextcloud OK | détermine taille/nom | `up4ever_start` | n/a | Nextcloud | `source_url,nextcloud_share_*,filename,expected_size` | enregistrer IDs avant suite | reprendre depuis état+IDs | Vérifiée |
| `offcloud_waiting` | request id manquant | — | `error` | non | — | job | — | n/a | Vérifiée |
| `offcloud_waiting` | status introuvable | — | `offcloud_waiting` (+60s) | oui | Offcloud | retry | poll | Vérifiée |
| `offcloud_waiting` | status error | `offcloud_cloud_remove` | `error` | non | Offcloud remove | `error_msg,offcloud_request_id` | idempotent remove | n/a | Vérifiée |
| `offcloud_waiting` | pas downloaded | — | `offcloud_waiting` (+60s) | oui | Offcloud | retry | poll | Vérifiée |
| `offcloud_waiting` | downloaded, explore vide | — | `offcloud_waiting` (+60s) | oui | Offcloud explore | retry | poll | Vérifiée |
| `offcloud_waiting` | downloaded + URL directe | — | `up4ever_start` | n/a | Offcloud | `source_url,offcloud_request_id,filename` | — | sûr | Vérifiée |
| `torbox_waiting` | API liste indisponible | — (return) | inchangé (re-tenté run suivant) | implicite | TorBox | aucune | — | poll | Vérifiée |
| `torbox_waiting` | download introuvable >=6 essais | `log_error(DEAD_LINK)` | `error` | non | TorBox | `error_msg,torbox_error_count` | — | n/a | Vérifiée |
| `torbox_waiting` | download introuvable <6 | — | `new` (+5min, clear torbox id, compteur++) | oui | TorBox | job | reset contrôlé | possible perte contexte (À confirmer) | Vérifiée |
| `torbox_waiting` | completed/cached/done/progress>=1 et PC >200GB | `log_error(FILE_TOO_LARGE)` | `error` | non | TorBox | job | — | n/a | Vérifiée |
| `torbox_waiting` | completed/cached/done/progress>=1 (OK) | calcule next (`up4ever_start` si enabled, sinon `fileal_start` si pc sinon `mixloads_start`) | next | n/a | TorBox link/list | `state,filename,expected_size` reset cdn | — | sûr | Vérifiée |
| `torbox_waiting` | failed/error | delete download, `log_error(TORBOX_ERROR,1h)` | `new` (+1h, clear id) | oui | TorBox delete | job/errors | idempotent delete | — | Vérifiée |
| `torbox_waiting` | stagnation >= `TORBOX_STAGNANT_TIMEOUT` | delete + log | `new` (+1h) | oui | TorBox | job/errors | — | — | Vérifiée |
| `torbox_waiting` | timeout attente (adaptatif ou fixe) | delete + log | `new` (+1h) | oui | TorBox | job/errors | — | — | Vérifiée |
| `torbox_waiting` | en cours | MAJ progression si changée | (break, reste `torbox_waiting`) | poll | TorBox | `torbox_last_progress/at` | — | poll | Vérifiée |
| `streamtape_start`, `streamtape_uploading` | compat | — | `post_update` | n/a | — | job | **À confirmer** claimable/exclure pool VPS (legacy) | — | Vérifiée (redir) |
| `pixeldrain_start` | compat | set cdn null | `mixloads_start` | n/a | — | job | À confirmer | — | Vérifiée |
| `up4ever_start` | Up4ever disabled, ou `up4ever_retry_count>=UP4EVER_MAX_RETRIES` | fallback `$next_state` (selon job_type/m2/taille/has_mixloads : `post_update`/`fileal_start`/`mixloads_start`) | `$next_state` | stop retries (reset compteur) | — | `state,(retry counters)` | non bloquant par design | sûr | Vérifiée |
| `up4ever_start` | aucune source exploitable (après TorBox/MixLoads/direct/source_url) | si source_url page origine → fallback next; sinon retry | `up4ever_start` (+5min) ou `$next_state` | `up4ever_retry_count++` (borne 3) | TorBox/MixLoads API possibles | job | — | sûr | Vérifiée |
| `up4ever_start` | quota local plein/verrou non acquis | `up4ever_reserve_upload_slot` → wait | `up4ever_start` (`retry_at` = now+wait) | attente (pas échec définitif) | DB `GET_LOCK` + `immorta_up4ever_uploads` | insert/count/quota table | **remplacer par `quota_acquire` API** (atomique, idempotent, `up4ever_attempt_id` stable) | sûr si slot réservé une fois (À confirmer unicité) | Vérifiée |
| `up4ever_start` | init remote upload KO | — | `up4ever_start` (+wait/retry) | `up4ever_retry_count++` | Up4ever API | job | idempotence init / ne pas doubler upload | **risque doublon si accepté côté fournisseur sans réponse** (À confirmer) | Vérifiée |
| `up4ever_start` | init remote OK (code ou pending) | enregistre démarrage/corrélation | `up4ever_uploading` (+60s) | n/a | Up4ever API | `up4ever_code/url/started_at/source_name/remote_ref,retry_at,state` | conserver `request_id`/attempt | poll/reprise corrélation | Vérifiée |
| `up4ever_uploading` | disabled | — | `$next_state` | n/a | — | job | — | sûr | Vérifiée |
| `up4ever_uploading` | `elapsed > UP4EVER_POLL_TIMEOUT` (180 min) | relance | `up4ever_start` (+120s, retry++) | oui (borne start) | — | clear code/url, retry | distinguer reprise vs nouvelle tentative logique | risque re-upload (À confirmer) | Vérifiée |
| `up4ever_uploading` | code absent, corrélation trouvée | — | reste `up4ever_uploading` (code/url set) | poll | Up4ever list/info | `up4ever_code,url` | idempotent | — | Vérifiée |
| `up4ever_uploading` | code absent >30min non corrélé | fallback sans Up4ever | `$next_state` (clear up4ever) | n/a | Up4ever | job | pas de relance boucle si miroir déjà géré ailleurs | À confirmer selon chemins | Vérifiée |
| `up4ever_uploading` | code absent <30min | — | `up4ever_uploading` (+>=60s/retry-after) | poll | Up4ever | `retry_at` | — | — | Vérifiée |
| `up4ever_uploading` | file/info indisponible | — | `up4ever_uploading` (+60s) | poll | Up4ever | retry | — | — | Vérifiée |
| `up4ever_uploading` | non disponible (traitement distant) | — | `up4ever_uploading` (+wait) | poll | Up4ever | retry | — | — | Vérifiée |
| `up4ever_uploading` | disponible mais taille <90% attendu | relance | `up4ever_start` (+120s, retry++) | oui | Up4ever | clear code/url | éviter re-upload inutile | À confirmer | Vérifiée |
| `up4ever_uploading` | nom ne correspond pas | tente recorrélation ; si KO ou toujours KO/taille KO → attente/relance `up4ever_start` | `up4ever_uploading` (retry) ou `up4ever_start` | poll/retry | Up4ever | code/url/retry | précision corrélation critique | À confirmer faux positifs | Vérifiée |
| `up4ever_uploading` | disponible, contrôles OK | — | `$next_state` | n/a | Up4ever | `up4ever_code,url` figés, reset retry | — | sûr | Vérifiée |
| `mixloads_start` | oversize > `MIXLOADS_MAX_FILE_SIZE` et Up4ever enabled | route sans MixLoads | `up4ever_start` ou `post_update` (si miroir déjà là) | n/a | — | job | — | Vérifiée |
| `mixloads_start` (m1_slot2) | source manquante | — | `error` | non | — | job | À confirmer périmètre VPS slot2 | n/a | Vérifiée |
| `mixloads_start` (m1_slot2) | max retries atteint | — | `error` | non | — | job | — | n/a | Vérifiée |
| `mixloads_start` (m1_slot2) | init upload slot2 KO | — | (reste) `mixloads_start` (+10min, retry++) | `cdn_retry_count` < `MIXLOADS_MAX_RETRIES` | MixLoads | retry | idempotence upload | À confirmer | Vérifiée |
| `mixloads_start` (m1_slot2) | init OK | — | `mixloads_uploading` | n/a | MixLoads | `cdn_url` slot:code, timers | — | poll | Vérifiée |
| `mixloads_start` (normal m1/m2) | max retries | — | `multiup_remote_start` (clear cdn) | stop | — | job | fallback | Vérifiée |
| `mixloads_start` (normal) | pas de source TorBox/direct valide ou preflight KO | — | `mixloads_start` (+5min, retry++) | oui | TorBox HEAD/link | retry | — | — | Vérifiée |
| `mixloads_start` (normal) | init upload KO/rotation impossible | — | `mixloads_start` (+5min, retry++) | oui | MixLoads | retry | — | — | Vérifiée |
| `mixloads_start` (normal) | init OK | — | `mixloads_uploading` | n/a | MixLoads | `cdn_url`, start file | — | poll | Vérifiée |
| `parallel_uploading` | `cdn_url` mixloads | redir compat | `mixloads_uploading` | n/a | — | job | Exclure/compat à confirmer | — | Vérifiée |
| `parallel_uploading` | sinon | reset | `mixloads_start` | n/a | — | job | — | — | Vérifiée |
| `pixeldrain_uploading` | cdn contient mixloads | — | `mixloads_uploading` | n/a | — | job | compat | — | Vérifiée |
| `pixeldrain_uploading` | sinon | reset | `mixloads_start` | n/a | — | job | — | — | Vérifiée |
| `mixloads_uploading` | timeout global (elapsed > `MIXLOADS_POLL_TIMEOUT+5`) | choix next (`up4ever_start`/`post_update`/`multiup_remote_start` selon contexte) | next (reset cdn/retry) | stop upload courant | MixLoads info/list | job, unlink start | éviter relance Up4ever si miroir déjà valide (cas 404 géré) | À confirmer branches | Vérifiée |
| `mixloads_uploading` | code PENDING/inconnu trouvé via liste | persiste code | reste `mixloads_uploading` | poll | MixLoads list | `cdn_url` | — | — | Vérifiée |
| `mixloads_uploading` | code PENDING/inconnu introuvable | — | reste (attente) | poll | MixLoads | status json | — | — | Vérifiée |
| `mixloads_uploading` | file/info inaccessible | — | reste (attente) | poll | MixLoads | status json | — | — | Vérifiée |
| `mixloads_uploading` | 404 avant grâce | — | reste + `retry_at` +120s | poll | MixLoads | retry | — | — | Vérifiée |
| `mixloads_uploading` | 404 après grâce | route fallback (`post_update` si up4ever_url déjà présent, sinon up4ever_start ou multiup) | next | n/a | MixLoads | clear cdn, error_msg info | **éviter boucle re-upload Up4ever** | Vérifiée |
| `mixloads_uploading` | pas OK mais `uploading` | — | reste (attente) | poll | MixLoads | status | — | — | Vérifiée |
| `mixloads_uploading` | tronqué/échec réel et elapsed>10min | relance | `mixloads_start` (+120s, retry++) | `MIXLOADS_MAX_RETRIES` (selon start) | MixLoads | reset cdn | idempotence | — | Vérifiée |
| `mixloads_uploading` | tronqué/échec réel et elapsed<=10min | — | reste (attente) | poll | MixLoads | status | — | — | Vérifiée |
| `mixloads_uploading` | complet | choisit next (`post_update` si m1_slot2, sinon `fileal_start` ou `up4ever_start`) | next | n/a | MixLoads | `cdn_url`=lien public, cleanup start | — | sûr | Vérifiée |
| `fileal_start`, `fileal_uploading` | FileAL désactivé (code actuel) | bypass | `multiup_remote_start` | n/a | — | reset fileal retry | À confirmer si réactivation future | — | Vérifiée |
| `fileq_start` | retries >= max | skip | `multiup_remote_start` | stop | — | reset compteur | — | — | Vérifiée |
| `fileq_start` | aucune source | — | `fileq_start` (+5min, retry++) | oui | TorBox/MixLoads | retry | — | — | Vérifiée |
| `fileq_start` | init slot upload KO | — | `fileq_start` (+5min, retry++) | oui | FileQ | retry | idempotence | — | Vérifiée |
| `fileq_start` | init OK | start timer | `fileq_uploading` (+60s) | n/a | FileQ | `fileq_code` (ou PENDING) | — | poll | Vérifiée |
| `fileq_uploading` | timeout poll | skip | `multiup_remote_start` | stop | — | cleanup, reset | — | — | Vérifiée |
| `fileq_uploading` | code PENDING | — | reste (attente) | poll | FileQ | — | — | — | Vérifiée |
| `fileq_uploading` | check non OK et `uploading` | — | reste | poll | FileQ | — | — | — | Vérifiée |
| `fileq_uploading` | check non OK, pas uploading, retries max | skip | `multiup_remote_start` | stop | FileQ | cleanup | — | — | Vérifiée |
| `fileq_uploading` | check non OK, retries restants | — | `fileq_start` (retry++) | oui | FileQ | clear code | — | — | Vérifiée |
| `fileq_uploading` | OK | — | `multiup_remote_start` | n/a | FileQ | `fileq_url` | — | — | Vérifiée |
| `up4ever_to_onfichier_start` | Up4ever indispo/code absent | fallback | `onfichier_reupload_start` si `mixloads_link` sinon `done` (`done_at`) | n/a | Up4ever API | state/done | `done` sans post_update complet : **À confirmer** cohérence finale | — | Vérifiée |
| `up4ever_to_onfichier_start` | direct_link KO, retries restants | — | `up4ever_to_onfichier_start` (+5min/Retry-After, retry++) | oui (<3) | Up4ever | job | — | — | Vérifiée |
| `up4ever_to_onfichier_start` | direct_link KO, retries épuisés | fallback MixLoads/non bloquant | `onfichier_reupload_start` ou `done` | stop | Up4ever | reset retry | À confirmer | — | Vérifiée |
| `up4ever_to_onfichier_start` | direct_link OK | — | `onfichier_reupload_start` | n/a | Up4ever | `up4ever_direct_url` | — | — | Vérifiée |
| `onfichier_reupload_start` | pas de source (Up4ever direct/MixLoads/TorBox) | skip vers multiup + `advance_job` immédiat | `multiup_remote_start` | n/a | — | state | récursion contrôlée (À confirmer profondeur) | — | Vérifiée |
| `onfichier_reupload_start` | init remote upload KO, retries<=max | — | `onfichier_reupload_start` (+5min) | compteur `theuser_retry_count` | 1fichier | retry | idempotence soumission externe | risque doublon | Vérifiée |
| `onfichier_reupload_start` | init KO après épuisement source Up4ever | bascule source | `onfichier_reupload_start` (+60s, clear direct, reset compteur) | changement stratégie | — | job | — | — | Vérifiée |
| `onfichier_reupload_start` | init KO après épuisement MixLoads (TorBox dispo) | bascule source | `onfichier_reupload_start` (+60s) | — | — | job | — | — | Vérifiée |
| `onfichier_reupload_start` | init KO toutes sources épuisées | skip + `advance_job` | `multiup_remote_start` | stop | 1fichier | reset compteur | — | — | Vérifiée |
| `onfichier_reupload_start` | init OK | — | `onfichier_reupload_polling` (+60s) | n/a | 1fichier remote upload | `onfichier_request_id` | **idempotence critique** | poll | Vérifiée |
| `onfichier_reupload_polling` | request_id manquant | skip + `advance_job` | `multiup_remote_start` | n/a | — | job | — | — | Vérifiée |
| `onfichier_reupload_polling` | API poll erreur | — | reste `onfichier_reupload_polling` (+120s) | poll | 1fichier | retry_at | — | — | Vérifiée |
| `onfichier_reupload_polling` | pas terminé, timeout dépassé (`ONFICHIER_POLL_TIMEOUT`) | skip + `advance_job` | `multiup_remote_start` (clear request) | stop | 1fichier | job | — | — | Vérifiée |
| `onfichier_reupload_polling` | pas terminé, dans délai | — | reste (+90s) | poll | 1fichier | retry_at | — | — | Vérifiée |
| `onfichier_reupload_polling` | terminé avec nouvelle URL | remplace lien 1fichier dans `nezo_posts.message`, puis avance | `multiup_remote_start` (+ `advance_job`) | n/a | 1fichier, **écriture post** | `nezo_posts.message`, `immorta_jobs` | **CAS `update_post` requis** (actuellement `UPDATE` direct) ; idempotence remplacement | interruption = état partiel possible | Vérifiée |
| `onfichier_reupload_polling` | terminé sans URL exploitable (KO côté 1fichier) | continue | `multiup_remote_start` (+ `advance_job`) | n/a | 1fichier | job | — | — | Vérifiée |
| `of_to_ml_start` | pas de source MixLoads | abandon réparation | `done` (`done_at`) | n/a | — | job | `done` sans passage `post_update` : **À confirmer** invariants | — | Vérifiée |
| `of_to_ml_start` | upload KO, retries <=3 | — | `of_to_ml_start` (+5min) | `theuser_retry_count` | MixLoads | retry | idempotence | — | Vérifiée |
| `of_to_ml_start` | upload KO, retries épuisés | abandon | `done` | stop | MixLoads | job | À confirmer | — | Vérifiée |
| `of_to_ml_start` | upload OK | — | `of_to_ml_uploading` (+60s) | n/a | MixLoads | `cdn_url`, start file | — | poll | Vérifiée |
| `of_to_ml_uploading` | code manquant | abandon | `done` | n/a | — | job | À confirmer | — | Vérifiée |
| `of_to_ml_uploading` | timeout | abandon | `done` | stop | — | cleanup | À confirmer | — | Vérifiée |
| `of_to_ml_uploading` | info inaccessible ou pas OK | — | reste (attente) | poll | MixLoads | — | — | — | Vérifiée |
| `of_to_ml_uploading` | prêt | remplace lien 1fichier par MixLoads 3 dans post | `done` (`done_at`, set `cdn_url`) | n/a | MixLoads, **écriture post** | `nezo_posts.message`, job | **CAS requis** ; pas de `post_update` standard | interruption possible | Vérifiée |
| `multiup_remote_start` | Multiup désactivé | skip | `post_update` | n/a | — | job | — | — | Vérifiée |
| `multiup_remote_start` | taille > limite (200GB si pc sinon `MULTIUP_MAX_GB`) | skip | `post_update` | n/a | — | job | — | — | Vérifiée |
| `multiup_remote_start` | retries start >= `MULTIUP_START_MAX_RETRIES` | — | `multiup_failed` | stop (manuel/dashboard) | — | `error_msg`, reset retry | **état spécial hors flux auto** (à exclure/traiter explicitement en claim) | Vérifiée |
| `multiup_remote_start` | source MixLoads tronquée/invalide détectée | reset amont | `mixloads_start` (reset cdn/retry) | n/a | MixLoads check | job | évite upload corrompu | — | Vérifiée |
| `multiup_remote_start` | aucune source valide | — | `multiup_failed` | stop | — | `error_msg` | — | — | Vérifiée |
| `multiup_remote_start` | submit sans réponse ou erreur API ou pas de lien | — | reste `multiup_remote_start` (+5min, retry++) | oui (borne 5) | Multiup remote upload, hosts/account APIs, TorBox/MixLoads/Up4ever selon source | `cdn_retry_count,error_msg` | **idempotence soumission** (upload peut être accepté sans réponse) | risque doublon lien/soumission (À confirmer) | Vérifiée |
| `multiup_remote_start` | submit OK + lien | — | `multiup_remote_poll` | n/a | Multiup | `multiup_link`, reset compteurs | conserver `multiup_link` comme clé corrélation | poll | Vérifiée |
| `multiup_remote_poll` | timeout attente >= `MULTIUP_REMOTE_TIMEOUT_HOURS` | abandon miroir Multiup | `post_update` (clear `multiup_link`) | stop | — | job | — | — | Vérifiée |
| `multiup_remote_poll` | `check_file` sans réponse | — | (return, reste) | poll prochain run | Multiup check | — | — | — | Vérifiée |
| `multiup_remote_poll` | `completed` + hosts prêts | — | `post_update` | n/a | Multiup | reset compteurs | — | — | Vérifiée |
| `multiup_remote_poll` | `completed` + 0 hôte, retries restants | — | reste + `retry_at` +5min (`cdn_retry_count++`) | oui (24) | Multiup | retry | — | — | Vérifiée |
| `multiup_remote_poll` | `completed` + 0 hôte, retries épuisés | — | `post_update` sans Multiup | stop | Multiup | reset | — | — | Vérifiée |
| `multiup_remote_poll` | `not_found`, compteur <= max | — | reste + `retry_at` +3min | oui (60) | Multiup | `cdn_retry_count` | — | — | Vérifiée |
| `multiup_remote_poll` | `not_found` persistant, resubmit <= max | resoumission | `multiup_remote_start` (clear lien, reset retry, `multiup_pid`=resub) | oui (3 resub) | Multiup | job | **nouvelle soumission externe** ; idempotence | — | Vérifiée |
| `multiup_remote_poll` | `not_found` après resub max | — | `post_update` sans Multiup | stop | Multiup | clear lien | — | — | Vérifiée |
| `multiup_remote_poll` | `error`, resubmit possible | — | `multiup_remote_start` | oui | Multiup | job | — | — | Vérifiée |
| `multiup_remote_poll` | `error`, resub épuisé | — | `post_update` sans Multiup | stop | Multiup | clear lien | — | — | Vérifiée |
| `multiup_remote_poll` | autre statut (uploading/inconnu) | — | reste + `retry_at` +3min | poll | Multiup | retry_at | — | — | Vérifiée |
| `post_update` (job_type `m1_slot2`) | miroir MixLoads slot2 présent/injectable | MAJ `cdn_url_2` job principal done + injection/remplacement `[Miroir MixLoads 2]` dans post | `done` (`done_at`) | n/a | **écriture `nezo_posts`** | `immorta_jobs.cdn_url_2` (main), `nezo_posts.message`, job slot2 | **CAS/prepare+commit requis**, idempotence texte | réexécution peut réécrire différemment (À confirmer) | Vérifiée |
| `post_update` (job_type `m1_slot2`) | URL slot2 invalide | skip maj post | `done` | n/a | — | job | À confirmer perte miroir | — | Vérifiée |
| `post_update` (principal) | construction message (injection par source_url/hash/hide ou fallback replace/append), présence miroirs variables | calcule `new_message`, `UPDATE nezo_posts SET message=?`, supprime download TorBox si id, notifie Telegram si non-m2, écrit status | `done` (`done_at`, set `torbox_link`) | n/a | TorBox delete, Telegram, **écriture `nezo_posts`** | `nezo_posts.message`, `immorta_jobs.state/done_at/torbox_link`, (cleanup NC/Offcloud déclenché par `update_job` sur done) | **phase finalisation critique** : CAS, `prepare_finalize/commit_finalize`, ordre effets, pas double publication/nettoyage incohérent, idempotence | interruption entre étapes ⇒ incohérence possible (message posté / job non done / nettoyage partiel) | Vérifiée |
| `default` (état inconnu) | tout état non géré | reset | `new` | n/a | — | `state='new'` | dangereux : peut ré-exécuter effets externes ; API doit refuser états inconnus | À confirmer occurrences | Vérifiée |

## 3. États / familles — classification provisoire (VPS)

| Famille | États recensés | Traitement | Remarque claim/pool 99 |
|---|---|---|---|
| Entrée | `new` | résolution/routage | **À confirmer** éligible (actuellement créé/claimé par slot) |
| Seedbox | `pyload_waiting` | polling + share | éligible si bail/fence OK |
| Services distants | `offcloud_waiting`, `torbox_waiting` | polling | idem |
| Up4ever | `up4ever_start`, `up4ever_uploading` | upload/suivi + quota | idem + `quota_acquire` |
| MixLoads | `mixloads_start`, `mixloads_uploading` | upload/suivi | idem |
| Compat/legacy | `parallel_uploading`, `pixeldrain_start`, `pixeldrain_uploading`, `streamtape_start`, `streamtape_uploading` | redirection | **À confirmer** (prob. non claimables, seulement reprise/normalisation) |
| Miroirs | `fileal_start`, `fileal_uploading`, `fileq_start`, `fileq_uploading` | upload/suivi/bypass | idem |
| 1fichier/M2 | `up4ever_to_onfichier_start`, `onfichier_reupload_start`, `onfichier_reupload_polling` | régénération + écriture post | CAS/idempotence requis |
| M2 lien | `of_to_ml_start`, `of_to_ml_uploading` | remplacement lien | CAS/idempotence ; fin en `done` direct (**À confirmer**) |
| Multiup | `multiup_remote_start`, `multiup_remote_poll` | soumission/suivi | idempotence externe |
| Publication | `post_update` | finalisation | **CAS + intention + commit** |
| Terminaux | `done`, `error`, `renewed` | fin | non claimables (sauf politiques spéciales) |
| Transitoire/spécial | `renewing` | verrou renew | non claimable |
| Manuel/spécial | `multiup_failed` | arrêt/relance dashboard | **À confirmer** exclusion claim auto |
| Autre (non vu en case) | `paused` (exclu du claim v184) | — | **Exclue/À confirmer** politique |

## 4. Points à confirmer avant de figer la matrice

- Conditions/sorties exactes de chaque helper (`up4ever_reserve_upload_slot`, checks MixLoads/Up4ever/Multiup, `mixloads_find_slot_and_upload`, preflights, résolution sources) et effets de bord non persistés.
- Transitions déclenchées hors `switch` principal observé dans tous les cas de figure (rappels récursifs `advance_job`, M2/auto-requeue créant jobs dans d’autres états initiaux, réconciliation).
- Compteurs réutilisés (`cdn_retry_count`, `up4ever_retry_count`, `fileq_retry_count`, `torbox_error_count`, `theuser_retry_count`, `multiup_pid` comme compteur resub) et remises à zéro par branche.
- Chemins `done` directs sans `post_update` (`up4ever_to_onfichier_start`, `of_to_ml_*`) : invariants publication/nettoyage.
- Sémantique exacte des écritures `nezo_posts` (toutes occurrences, pas seulement `post_update`) et besoin CAS + idempotence texte.
- États réellement claimables du pool VPS `slot_owner=99` vs états legacy/compat/manuels/historiques.
- Stratégie de réconciliation avant resoumission externe (Up4ever remote, MixLoads upload, Multiup submit, 1fichier remote, pyLoad add/share) en cas de résultat incertain.
- Wrapper VPS définitif (chemins/logs/verrous/env, pas d’appel `torbox_change.php`/`decron.php`) et concurrence `worker_id`.
- Durées bail/renewal adaptées aux attentes longues (poll 180 min, Multiup 6h, etc.) et fréquence heartbeat/renew.