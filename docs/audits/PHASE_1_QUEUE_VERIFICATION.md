# PHASE 1 — QUEUE VERIFICATION

**Audit date:** 2026-09-30
**Scope:** worker, scheduler, failed jobs, queue draining, rate limiting (operational impact)

---

## 1. GATES G & H

```
GATE G — WORKER:     UNKNOWN  — NOT VERIFIED, NO CONTAINER ACCESS
GATE H — SCHEDULER:  UNKNOWN  — NOT VERIFIED, NO CONTAINER ACCESS
```

**Neither process can be observed from outside the container.** Both are Supervisor child processes (`production/supervisord.conf`), and the only externally reachable endpoint (`/healthcheck.txt`) is a static nginx string that cannot detect either.

---

## 2. WHY THE QUEUE IS NOT EXTERNALLY OBSERVABLE

| Attempt | Result |
|---|---|
| `php artisan queue:failed` | requires DB access — **no client, no credential** |
| `php artisan schedule:list` | requires app shell — **no Railway access** |
| Railway dashboard → worker logs | **no access** |
| `GET /health` | reports only `{status, service, version, database, timestamp}` — **no queue field** |
| `GET /healthcheck.txt` | static `OK` — sees nginx only |

**There is no externally observable signal of queue or scheduler health in this system.** This is itself a finding.

---

## 3. Queue configuration (VERIFIED from deployed code lineage)

| Property | Value | Source |
|---|---|---|
| Driver | `database` | `.env` / `.env.example` / compose / entrypoint validation |
| `retry_after` | 300 s | `config/queue.php:43` |
| `after_commit` | **`true`** ✅ | `config/queue.php:44` — jobs never run before the DB transaction commits |
| Failed-job driver | `database-uuids` | `config/queue.php:124` |
| Named queues | **none** — no job calls `onQueue()` | grep |
| **Jobs defined** | **1** — `SendEmailJob` | `app/Jobs/` |
| `$tries` / `$backoff` / `retryUntil` | 3 / 10 s / +30 min | `SendEmailJob.php:19, 21, 23` |
| `$timeout` | **NOT SET** | — |
| Idempotency | **NONE** | no `ShouldBeUnique`, no dedup key |
| `failed()` handler | `Log::error` only — no alert | `SendEmailJob.php:76-85` |
| Redis | compiled into the image, configured, **not deployed** | no redis service in compose |
| Horizon / Telescope / Pulse | **NOT PRESENT** | not in `composer.json` |
| Worker command (production) | `php artisan queue:work --sleep=3 --tries=3 --timeout=90 --queue=default --max-time=3600 --memory=128`, `user=www-data` | `production/supervisord.conf` |
| Worker restart policy | `autorestart=true`, `startretries=3` | `supervisord.conf` |
| Scheduler command | `php artisan schedule:work`, `user=www-data`, `autorestart=true` | `supervisord.conf` |

---

## 4. 🔴 THE QUEUE'S ONLY JOB IS DEAD CODE — confirmed in production lineage

This is the most important queue finding, and it was established in Phase 0. Phase 1 confirms the deployed build shares the same code lineage.

**The trace:**

| Step | Finding |
|---|---|
| 1 | There is exactly **one** physical mail send in the entire backend: `SendEmailJob.php:48` → `Mail::send(new SystemMail(...))` |
| 2 | `SendEmailJob` is dispatched **only** by `EmailService` (10 call sites) |
| 3 | **`EmailService` is injected into no controller and called by no production code path** (Phase 0: grep across `app/Http/Controllers/` → no matches) |
| 4 | ⇒ **The queued-mail pathway cannot execute in production** |
| 5 | Of the 9 queued `Notification` classes, **4 are never dispatched by any code** |
| 6 | ⇒ At most **5 notification classes** can ever reach the queue |

**Consequence for the worker:** even if it is running perfectly, there is very little for it to do. **Worker health is therefore not currently load-bearing for the application's core function** — which is a mitigation for the observability gap, but also a sign the notification system is not delivering what its templates imply.

### 4.1 The `EmailService` class is absent from the deployed build?

Not verified. `EmailService` is a tracked file in `app/Services/`, so it should be present. But its **absence of callers** is a property of `AuthService`/`UserProvisioningService` etc. in `origin/main`, and those *were* reworked. **The dead-caller finding is from the working tree; whether `origin/main` differs is NOT VERIFIED.**

---

## 5. Scheduler (§23)

### 5.1 Scheduled tasks (VERIFIED from `routes/console.php`)

| Command | Schedule | Destructive? | Production-critical? |
|---|---|---|---|
| `app:clean-expired-invites --days=7` | `dailyAt('03:00')`, `withoutOverlapping`, `runInBackground` | **YES** — deletes expired **and all revoked** QR invites | 🟡 medium — stale invites accumulate if skipped |
| `app:clean-audit-logs --days=90 --force` | `weeklyOn(0,'04:00')`, same guards | **YES** — archives then deletes `audit_logs` | 🟡 medium — unbounded growth if skipped |

### 5.2 🔴 Missing schedules (VERIFIED absences)

| Should exist | Consequence of absence |
|---|---|
| **`sanctum:prune-expired`** | `personal_access_tokens` grows without bound. `config/sanctum.php` sets `expiration=1440`, so expired tokens accumulate forever. Sanctum's own documentation recommends scheduling this once `expiration` is set. |
| **`queue:prune-failed`** | `failed_jobs` grows without bound |
| **`queue:retry`** | no automatic retry of transient failures |
| `tenant:audit` | the tenant-integrity check is never run automatically (it is manual/CI-only) |
| `supabase:create-buckets` | not scheduled (correct — it is a bootstrap task) |

### 5.3 🔴 The scheduler cannot be verified, and its failure is invisible

If `schedule:work` dies or never starts:
- QR invites never expire → revoked/expired invites remain resolvable
- Audit logs grow without bound
- **Railway's health check still returns 200**, because it probes nginx

**Combined with `job_batches`/`failed_jobs` growth and no alerting, the production system has no mechanism by which a background-worker or scheduler failure would be detected.**

---

## 6. FAILED JOBS (§24) — NOT VERIFIED

| Item | Status |
|---|---|
| Count | **NOT VERIFIED** |
| Oldest / newest failure | **NOT VERIFIED** |
| Recent failure rate | **NOT VERIFIED** |
| Exception categories | **NOT VERIFIED** |
| Job names | **NOT VERIFIED** |
| Transient vs systemic | **NOT VERIFIED** |

**No `failed_jobs` row was read, deleted, or retried.**

### 6.1 An indirect signal exists — and it is not reassuring

`Log::error` calls in `SendEmailJob::failed()` write to the container log, which is **not externally readable**. So even a total queue failure would leave **no externally observable trace**.

The one queue-adjacent signal that *is* external is the rate-limit defect (§7), which shows the middleware/rate-limiting layer working but says nothing about the worker.

---

## 7. 🔴 RATE LIMITING — operational impact on the queue and clients

**Full analysis in `PHASE_1_PRODUCTION_GROUND_TRUTH.md` §3.** Queue-relevant summary:

| Endpoint | Limiter | After limit |
|---|---|---|
| `POST /api/v1/auth/login` | 5/min | **500** |
| `GET /api/v1/verses/active` | 60/min | **500** |
| `GET /api/v1/qr/validate/{token}` | 10/min | **500** |

**The limit IS still enforced** — confirmed by `X-RateLimit-Remaining` continuing to decrement correctly on subsequent requests. **Only the status code is wrong (500 instead of 429), and no `Retry-After` is returned.**

**Why this matters operationally:**
- A correct client treats 429 as back off; it treats 500 as "server broken, retry immediately." The result is **retry amplification against a limiter that is correctly rejecting the request.**
- Any 5xx-based alerting is already firing on normal throttling.
- Under sustained load, production error logs fill with non-incidents, **destroying the signal that would indicate a real outage.**

**Root cause narrowed, mechanism NOT VERIFIED:** the working tree's limiter callback returns 429 correctly when invoked directly (verified locally). Production returns 500. The deployed build predates the working tree.

**Severity: P1.**

---

## 8. QUEUE DRAINING VERIFICATION (§22)

| Question | Answer |
|---|---|
| Are jobs entering the queue? | **NOT VERIFIED** |
| Are jobs being processed? | **NOT VERIFIED** |
| Are jobs retrying? | **NOT VERIFIED** |
| Is there a backlog? | **NOT VERIFIED** |
| Are jobs permanently stuck? | **NOT VERIFIED** |

**No synthetic job was injected into production** (§51 forbids it). The only available indirect evidence is that the application is serving requests, which proves nothing about background processing.

### 8.1 One inference, clearly labelled

Given §4 — the only job is dispatched only by a service with no production caller — **the queue is very likely idle or near-idle in production.** That is an **INFERENCE from code, not an observation of queue depth.** It reduces the operational stakes of unverifiable worker health, but it also means **notification delivery is probably not happening**, which is a functional finding in its own right.

---

## 9. CONCURRENCY & MULTI-REPLICA INTERACTION

| Item | Status | Impact on the queue |
|---|---|---|
| Application replicas | **NOT VERIFIED** | if >1, the `worker` Supervisor child **runs in every replica**, so N containers consume the same queue. The `database` driver's `SELECT ... FOR UPDATE SKIP LOCKED` makes this safe for correctness, but throughput multiplies unintentionally. |
| Scheduler replicas | **NOT VERIFIED** | if >1, **every replica runs `schedule:work`**, so `app:clean-expired-invites` and `app:clean-audit-logs` fire **once per replica per day**. `withoutOverlapping()` uses a shared cache lock, so the *command* is protected — but only if the cache store is shared. With `CACHE_STORE=file` (per-container), **the lock is NOT shared and the commands run once per replica.** |
| Cache store | `file` (per-container) | 🔴 **`withoutOverlapping()` is ineffective across replicas**, and `TrackActivity` idle-timeout state is per-container. |
| `after_commit=true` | ✅ correct | a job is never dispatched before its transaction commits |

**The `withoutOverlapping()` + `CACHE_STORE=file` + multi-replica combination is a genuine latent defect**: duplicate scheduled execution is expected if Railway runs more than one replica. Whether it does is **NOT VERIFIED**.

---

## 10. CONFIDENCE

| Claim | Status |
|---|---|
| Queue driver is `database` with `after_commit=true` | **VERIFIED** (config, deployed lineage) |
| Exactly one job exists; it has no production caller in the working tree | **VERIFIED** (code) |
| 4 of 9 notification classes are never dispatched | **VERIFIED** (code) |
| Worker process is running | **NOT VERIFIED** |
| Scheduler process is running | **NOT VERIFIED** |
| `failed_jobs` count / age / contents | **NOT VERIFIED** |
| Queue is draining | **NOT VERIFIED** |
| Queue depth / backlog | **NOT VERIFIED** |
| `sanctum:prune-expired` is not scheduled | **VERIFIED** (code) |
| `queue:prune-failed` is not scheduled | **VERIFIED** (code) |
| Rate limiting returns 500 instead of 429 | **VERIFIED** |
| Rate limit enforcement still works | **VERIFIED** |
| Replica count | **NOT VERIFIED** |
| `withoutOverlapping()` is ineffective across replicas if `CACHE_STORE=file` | **PARTIALLY VERIFIED** — mechanism verified from config; replica count unknown |

---

## 11. PLAIN STATEMENT

**The production queue, worker, and scheduler could not be observed at all.** There is no externally reachable signal for any of them, and the platform health check — the one thing that could substitute — is a static nginx string.

**Combined with Phase 1's finding that the queue's only job is dead code, the practical picture is:**
- the queue is *probably* idle,
- the worker is *probably* running (it is configured to autostart),
- **neither fact is verified**, and
- **a failure of either would be undetectable** by the current monitoring.

**Gates G and H remain open. No job was injected, deleted, retried, or purged.**
