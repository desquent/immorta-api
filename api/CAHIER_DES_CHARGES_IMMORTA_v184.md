# Cahier des charges — IMMORTA v184

**Statut :** version initiale établie (référence officielle de la migration)  
**Règle :** cahier des charges d'abord, développement ensuite, déploiement uniquement avec accord explicite.

## Objectif
Migrer le pipeline cron vers la seedbox (`immorta_link_v184_up4ever.php`) afin que la seedbox voie l'IP VPS `95.216.219.126` (et non mutu `111.90.156.160`) via un worker VPS + API HTTPS sur mutu (Option 2), en conservant la base sur mutu et le rollback TorBox intact.

## Architecture cible

| Fondations SQL installées | VPS — Worker IMMORTA | HTTPS / JSON — API authentifiée | Mutualisé — API PHP | PDO — connexion locale à MySQL | Base `pixelwar_12` |
|---|---|---|---|---|---|
| Jobs, baux, idempotence, quotas, intentions | Exécution traitements, pyLoad, transferts | | Authentification, règles métier, leases, accès BDD | | |

**Principe fondamental :** le VPS ne se connecte jamais directement à MySQL. Il ne connaît que l'API HTTPS du mutualisé. L'API reste l'unique autorité sur les données et les transitions d'état.

## 1. Responsabilités des deux serveurs

| Mutualisé — API PHP | VPS — Worker |
|---|---|
| Authentifier le worker | S'authentifier auprès de l'API |
| Attribuer les jobs | Demander un job |
| Gérer les leases et fences | Renouveler son bail |
| Contrôler les états autorisés | Exécuter les traitements |
| Gérer l'idempotence | Conserver les identifiants de requête |
| Gérer le quota Up4ever | Effectuer les uploads |
| Finaliser les jobs | Envoyer les résultats |
| Modifier les données BDD | Ne jamais accéder directement à MySQL |

Le worker VPS conserve la logique métier de v184 nécessaire aux traitements, mais délègue toutes les opérations de persistance à l'API.

## 2. Périmètre fonctionnel de l'API (à valider avant de figer les routes)

| Opération | Fonction |
|---|---|
| `claim_job` | Réserver un job éligible du pool `slot_owner=99` |
| `get_job` | Récupérer les données nécessaires au traitement |
| `renew_lease` | Prolonger un bail encore valide |
| `release_lease` | Libérer un job et invalider le bail |
| `update_job` | Modifier un état ou des champs autorisés |
| `update_post` | Modifier `nezo_posts.message` avec contrôle CAS |
| `quota_acquire` | Obtenir une autorisation d'upload Up4ever |
| `quota_status` | Consulter l'état d'une tentative de quota |
| `prepare_finalize` | Enregistrer une intention de finalisation |
| `commit_finalize` | Appliquer la finalisation de façon contrôlée |
| `get_request_status` | Retrouver le résultat d'une requête précédente |
| `worker_heartbeat` | Signaler que le worker est actif |

Aucune route ne doit accepter de SQL libre ni permettre de modifier arbitrairement `slot_owner`, les colonnes de lease ou les états protégés.

## 3. Contrat de communication API

- HTTPS, échanges JSON, API versionnée (ex. `https://<domaine>/api/immorta/v1/`, emplacement définitif à confirmer).
- **Authentification** : par worker `worker_id` + secret propre + signature `HMAC-SHA256` + horodatage + identifiant unique de requête. `worker_id` dans le JSON n'est pas une preuve d'identité. Secret jamais dans un fichier public.
- **Requête** : `request_id` (UUID, stable/réutilisable si résultat incertain), `worker_id`, `action`, `payload`. Signature dans les en-têtes HTTP.
- **Réponse succès** : `{ "success": true, "request_id", "code", "data": {}, "error": null }`
- **Réponse erreur** : `{ "success": false, "request_id", "code", "data": null, "error": { "message": "..." } }` — jamais de détails internes/sensibles.

## 4. Gestion des jobs et des baux

- **Claim** : authentifier → vérifier aucun job actif pour le worker → verrouiller `immorta_worker_leases` → chercher job éligible `slot_owner=99` et état autorisé → attribuer bail/jeton → incrémenter `lease_fence` → MAJ registre worker → commit. Attribution unique (pas de double claim).
- **Renouvellement** : vérifier identité, job, `lease_token`, `lease_fence`, expiration. Bail expiré ⇒ aucune modification.
- **Fencing** : un ancien worker (fence périmé) ne doit jamais écraser le travail du nouveau.
- **Verrouillage** : opérations touchant les deux tables de baux → verrouiller d'abord `immorta_worker_leases`, puis `immorta_jobs`, toujours dans cet ordre.

## 5. Idempotence et récupération

- Table `immorta_api_idempotency` : même `request_id` + même contenu → restituer résultat connu ; même `request_id` + contenu différent → conflit ; requête en cours → pas de 2e mutation parallèle ; requête expirée → vérifier état réel ; mutation au résultat incertain → jamais rejouer aveuglément.
- VPS conserve localement les identifiants des opérations non résolues. `immorta_finalize_intents` permet reprise/examen après interruption sans écraser une finalisation validée.

## 6. Quota Up4ever (règles v184 strictes)

| Paramètre | Valeur |
|---|---|
| Quota maximal 24 h | 580 uploads |
| Tentatives max | 3 |
| Timeout polling | 180 minutes |
| Dossier Up4ever | Vide |
| Verrou MySQL | `GET_LOCK('immorta_up4ever_upload_cap', 5)` |

- Contrôle côté mutualisé uniquement. Protection contre doubles demandes, enregistrement cohérent (`immorta_up4ever_uploads`, `immorta_up4ever_quota_attempts`, `immorta_api_idempotency`).
- Refus temporaire (quota plein) ≠ refus définitif de la tentative logique ; `up4ever_attempt_id` stable pendant les reprises d'une même tentative logique.

## 7. Mise à jour des publications

- `nezo_posts.message` modifié via contrôle CAS (vérifier contenu attendu avant modification).
- Distinguer : `NULL`, `''`, non vide, déjà égal à la cible.
- Jamais de "appliqué" sur la seule base d'un `ROW_COUNT()` ambigu ou d'une simple relecture.
- `nezo_topics` en lecture seule.

## 8. Sécurité et règles de fonctionnement

- PDO exclusivement côté mutualisé ; `SET time_zone = '+00:00'` à chaque connexion ; `UTC_TIMESTAMP()` cohérent.
- Requêtes préparées, validation stricte des paramètres, liste blanche des états/colonnes modifiables, aucun SQL libre.
- Aucune modification libre de `slot_owner` ; aucune attribution auto à `slot_owner=0`.
- Aucun accès public non authentifié ; journalisation requêtes/erreurs/changements de bail sans secrets.
- Ordre de verrouillage : `immorta_worker_leases` → `immorta_jobs`.

## 9. Tests obligatoires avant mise en service (périmètre isolé)

Ne pas tester sur des jobs touchés par M0, M0.5, M1, M2, `decron.php` ou le dashboard.

1. API seule (auth, signatures, JSON, erreurs, PDO, UTC)
2. Baux (claim, renew, expiration, release, fencing)
3. Concurrence (demandes simultanées, deux workers, attribution unique)
4. Idempotence (répétition, perte de réponse, conflit payload, reprise)
5. Quota (acquisition, refus temporaire, reprise, double demande, limite 580)
6. Finalisation (intention préparée, interruption, adoption, commit)
7. Worker VPS (exécution réelle sur périmètre de test, sans flux legacy)

## 10. Hors périmètre

- Aucun changement : `torbox_change.php`, `decron.php`, crons existants, dashboard, règles Dead Link.
- Aucune prise en charge automatique des jobs historiques `slot_owner=0`.
- Rollback : arrêt du worker VPS et de son cron ; flux historique indépendant.

## 11. Ordre de développement recommandé

| Étape | Élément | Objectif |
|---|---|---|
| A | Inventaire de v184 | Identifier précisément les fonctions existantes |
| B | Socle API PHP | Connexion BDD, UTC, authentification, réponses |
| C | Authentification worker | Signature HMAC et contrôle des accès |
| D | Gestion des leases | Claim, renew, release, fencing |
| E | Idempotence | Protection contre les doubles opérations |
| F | Quota Up4ever | Reproduction exacte des règles v184 |
| G | Finalisation | Intentions durables et commit sécurisé |
| H | Client VPS | Communication HTTPS avec l'API |
| I | Tests isolés | Validation avant mise en service |

## 12. Points à fixer avant le premier fichier

1. Durée du bail et fréquence de renouvellement (adaptées aux traitements/polling, marge avant expiration).
2. Concurrence : 1 seul job actif par `worker_id` (recommandé).
3. Transitions d'état : comparer à chaque branche de v184, sans inventer.
4. Reprises : distinguer reprise du même traitement vs nouvelle tentative logique.

## 13. Prochaine action

Audit du worker original `immorta_link_v184_up4ever.php` (+ script de lancement si logique particulière) : correspondance fonction v184 → opération API, données, états avant/après, protections lease/idempotence, comportement en échec. **Audit en lecture/analyse uniquement.**
