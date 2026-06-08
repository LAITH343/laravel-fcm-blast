# laravel-fcm-blast

`laith343/laravel-fcm-blast`

[![Packagist Version](https://img.shields.io/packagist/v/laith343/laravel-fcm-blast)](https://packagist.org/packages/laith343/laravel-fcm-blast)
[![PHP Version](https://img.shields.io/packagist/php-v/laith343/laravel-fcm-blast)](https://packagist.org/packages/laith343/laravel-fcm-blast)
[![License](https://img.shields.io/packagist/l/laith343/laravel-fcm-blast)](LICENSE)

High-throughput Firebase Cloud Messaging sender for Laravel. Pushes **10k+ req/s** from PHP by driving a reused `curl_multi` handle pool with persistent TCP — OAuth handled by `kreait/firebase-php`, delivery handled by the engine.

This package is **backend only**. It exposes a programmatic API and a status DTO; build whatever UI/monitoring you want on top of `status()`.

## Contents

- [Requirements](#requirements)
- [Supported versions](#supported-versions)
- [Install](#install)
- [Configure](#configure)
- [Environment variables](#environment-variables)
- [Sizing for your target RPS](#sizing-for-your-target-rps)
- [Integrate](#integrate)
- [Per-request debug logging](#per-request-debug-logging)
- [Run a blast](#run-a-blast)
- [Run locally](#run-locally)
- [Run in production](#run-in-production)
- [Monitor](#monitor)
- [How it hits 10k RPS](#how-it-hits-10k-rps)

## Requirements

| Requirement | Version | Why |
|---|---|---|
| PHP | `^8.3` | Engine + typed contracts. |
| Laravel (`illuminate/*`) | `^12.0` \| `^13.0` | Queue, Eloquent, container, facades. |
| `ext-curl` | * | `curl_multi` is the delivery engine. |
| `ext-json` | * | Payload encoding. |
| `kreait/firebase-php` | `^7.0` | Service-account OAuth token minting. |
| Redis | — | Queue connection; required for the stale-job purge on start. |
| Database | Postgres / MySQL | Holds the per-run counters. The engine flushes **atomic** `col = col + delta` updates concurrently from every worker. |

> **SQLite caveat:** fine for the test suite and single-worker dev runs, but its single-writer lock serializes the concurrent counter flushes — do not use it for multi-worker production blasts. Use Postgres or MySQL there.

## Supported versions

| Package | PHP | Laravel |
|---|---|---|
| `0.x` | 8.3+ | 12.x, 13.x |

## Install

```bash
composer require laith343/laravel-fcm-blast
php artisan vendor:publish --tag=fcm-blast-config
php artisan migrate
```

## Configure

`config/fcm-blast.php`:

```php
'credentials' => env('FIREBASE_CREDENTIALS'),   // service-account JSON path or string (null = fake-token test mode)
'project_id'  => env('FIREBASE_PROJECT_ID'),
'rate_cap_per_sec' => 10000,                    // global cap, split across workers
'token_source'    => App\Fcm\MyTokenSource::class,
'message_builder' => App\Fcm\MyMessageBuilder::class,
'invalid_token_handler' => App\Fcm\PruneToken::class, // optional
'http_version'         => '2tls',               // HTTP/2 for real FCM, HTTP/1.1 for cleartext mocks
'max_host_connections' => 16,                   // small for HTTP/2 (real FCM); null = rateCap*0.3 (HTTP/1.1 mock)
```

> **Real FCM needs HTTP/2.** Over HTTP/1.1 every in-flight request needs its own TLS connection, so high concurrency to `fcm.googleapis.com` exhausts sockets (timeouts, broken pipes). `http_version => '2tls'` (the default) multiplexes thousands of requests over a handful of connections — set `max_host_connections` to something small (8-32). `2tls` automatically uses HTTP/1.1 for plain-`http` endpoints, so a local mock is unaffected and can keep its many-connections setup (`max_host_connections => null`).

## Environment variables

Every config key has an env override:

| Env var | Default | What it does |
|---|---|---|
| `FCM_BLAST_CREDENTIALS` | — | Service-account JSON path or string. Empty = fake-token mode (mock testing). |
| `FCM_BLAST_PROJECT_ID` | — | Firebase project id; builds the FCM v1 endpoint. |
| `FCM_BLAST_ENDPOINT` | — | Override endpoint. Empty = real FCM. Set to a mock URL for load testing. |
| `FCM_BLAST_RATE_CAP` | `10000` | **Global** sends/sec cap, split across workers (`10000` = 600k/min). |
| `FCM_BLAST_RATE_BURST` | 5% of rate | Max instantaneous burst per worker (paced limiter). Lower = smoother; doesn't change sustained rate. |
| `FCM_BLAST_MAX_HOST_CONNECTIONS` | `16` | TCP connections per **worker**. Null = `rateCap*0.3` (HTTP/1.1 mock). |
| `FCM_BLAST_MAX_CONCURRENT_STREAMS` | `100` | Max concurrent HTTP/2 streams per connection (FCM drops >~100). |
| `FCM_BLAST_HTTP_VERSION` | `2tls` | `2tls` (HTTP/2 over TLS, 1.1 over cleartext), `2`, or `1.1`. |
| `FCM_BLAST_MAX_RETRIES` | `5` | Attempts per token for 429/503 + transient transport errors. |
| `FCM_BLAST_QUEUE` | `fcm-blast` | Queue name the jobs run on. |
| `FCM_BLAST_CONNECTION` | `redis` | Queue connection (must be `redis` for stale-job purging). |

> `max_host_connections` is **per worker**. Total connections to FCM = `workers × max_host_connections`. In-flight capacity per worker = `max_host_connections × max_concurrent_streams` (the engine sizes its concurrency to exactly this).

## Sizing for your target RPS

Throughput is governed by **Little's Law**:

```
in-flight needed = target_RPS × avg_latency_seconds
capacity         = workers × max_host_connections × max_concurrent_streams
```

To hit a target RPS, make `capacity ≥ in-flight needed`, and keep `FCM_BLAST_RATE_CAP` at the rate you want (it caps emission so you never exceed it).

**Worked example — 10,000 RPS (600k/min):**

| avg latency | in-flight needed | example config (capacity) |
|---|---|---|
| 300 ms | 3,000 | 4 workers × 8 × 100 = 3,200 |
| 600 ms | 6,000 | 4 workers × 16 × 100 = 6,400 |
| 1,000 ms | 10,000 | 4 workers × 25 × 100 = 10,000 |

```dotenv
FCM_BLAST_RATE_CAP=10000
FCM_BLAST_MAX_HOST_CONNECTIONS=16   # per worker
FCM_BLAST_MAX_CONCURRENT_STREAMS=100
```
with **4 workers** → 6,400 in-flight, enough for 10k RPS up to ~640ms latency; `rate_cap` then holds the ceiling at 10k.

**Three real limits, in order of what you'll hit first:**
1. **Network egress** — a single host (especially residential/cellular) tolerates only so many simultaneous connections. Failures show as transport errors (timeouts/resets), now auto-retried. Keep `max_host_connections` to what your link handles; scale out across hosts for more.
2. **Latency** — higher latency needs more in-flight for the same RPS. Measure `avg_latency_ms` from a run and plug it into the formula.
3. **FCM project quota** — above it, FCM returns `429`/`503` (the `throttled` counter). Check/raise it in Google Cloud Console → IAM & Admin → Quotas → "Firebase Cloud Messaging API".

> Don't over-provision connections. Use the **smallest** `connections × streams` that covers your latency — extra connections only increase the handshake burst (more transport failures) without raising throughput, since `rate_cap` is the real ceiling.

## Integrate

Implement two contracts. The package never touches your database directly.

```php
use Laith343\FcmBlast\Contracts\TokenSource;

class MyTokenSource implements TokenSource
{
    public function count(): int { /* total active tokens */ }
    public function stream(int $offset, int $limit): \Generator { /* yield device tokens */ }
}
```

```php
use Laith343\FcmBlast\Contracts\MessageBuilder;

class MyMessageBuilder implements MessageBuilder
{
    // Return the FCM v1 "message" body; the engine injects `token` and the envelope.
    // $context is null unless your TokenSource attached one (see below).
    public function build(string $token, mixed $context = null): array
    {
        return ['notification' => ['title' => 'Hi', 'body' => 'There']];
    }
}
```

### Per-token context (localization, custom logic)

To tailor the message per user, have your `TokenSource` yield an `OutboundToken`
(token + arbitrary context) instead of a plain string. The context reaches
`build()` unchanged. It's fully optional — yielding a string keeps `$context` null,
so existing sources/builders are unaffected.

```php
use Laith343\FcmBlast\Support\OutboundToken;

// In TokenSource::stream()
yield new OutboundToken($user->fcm_token, ['locale' => $user->locale, 'name' => $user->name]);
// or, no context:
yield $user->fcm_token;
```

```php
// In MessageBuilder::build()
public function build(string $token, mixed $context = null): array
{
    $locale = $context['locale'] ?? 'en';
    return ['notification' => [
        'title' => __('push.title', [], $locale),
        'body'  => __('push.body', ['name' => $context['name'] ?? ''], $locale),
    ]];
}
```

`context` can be any value (array, DTO, model) — it's passed in-process, not serialized.

### Per-run context (which campaign am I serving?)

Per-token context covers the message; per-run context tells the resolved
`TokenSource` / `MessageBuilder` / `InvalidTokenHandler` instances *which run*
they're serving. Workers resolve those classes from the container with no
arguments, so without this they can't know the campaign. Implement
`ContextAware` and pass the payload via `withContext()`:

```php
use Laith343\FcmBlast\Contracts\ContextAware;
use Laith343\FcmBlast\Contracts\TokenSource;

class CampaignTokenSource implements TokenSource, ContextAware
{
    private int $campaignId;

    public function withRunContext(mixed $context): static
    {
        $this->campaignId = $context['campaign_id'];
        return $this;
    }

    public function count(): int { /* tokens for $this->campaignId */ }
    public function stream(int $offset, int $limit): Generator { /* ... */ }
}
```

```php
FcmBlast::withContext(['campaign_id' => 42])->start($total, $workers);
```

The context travels through the queued job, so it must be **serializable**
(arrays/scalars, or SerializesModels-friendly values) — unlike per-token
context, which stays in-process. Opt-in: classes that don't implement
`ContextAware` are untouched.

Optional — prune permanently invalid tokens (FCM 404/400):

```php
use Laith343\FcmBlast\Contracts\InvalidTokenHandler;

class PruneToken implements InvalidTokenHandler
{
    public function __invoke(string $token): void { /* deactivate $token */ }
}
```

## Per-request debug logging

Enable to capture every request's full body, context, outcome, HTTP code and
latency for later debugging:

```env
FCM_BLAST_LOG_REQUESTS=true
FCM_BLAST_LOG_RETENTION_DAYS=7        # files older than this are pruned at run start
FCM_BLAST_LOG_PATH=                   # defaults to storage/logs/fcm-blast
```

Output is one NDJSON record per request, in a daily-rotated file
(`fcm-blast-requests-YYYY-MM-DD.log`):

```json
{"ts":"2026-06-07T12:00:00+00:00","run_id":42,"token":"...","context":{"locale":"en"},"attempt":1,"http_code":200,"curl_errno":0,"outcome":"Ok","latency_ms":108,"body":{"message":{"notification":{"title":"Hi"},"token":"..."}}}
```

**Performance:** records are buffered in memory and appended in bulk on the
engine's existing 0.5s flush tick (with a hard buffer cap), so the send loop
does one file append per half-second per worker — not one write per request.
This is far cheaper than a per-request log queue (which would dispatch more
jobs than the sends themselves), so logging stays in the worker. Keep it off
in production unless actively debugging.

## Run a blast

```php
use Laith343\FcmBlast\Facades\FcmBlast;

$runId = FcmBlast::tokensFrom(MyTokenSource::class)   // optional if set in config
    ->buildMessage(MyMessageBuilder::class)
    ->onInvalidToken(PruneToken::class)               // optional
    ->validateOnly(true)                              // optional dry run: validate, no delivery
    ->start(total: 1_000_000, workers: 4);
```

`start()` returns the run id. Spin up **one queue worker process per worker** —
the process count **must** match the `workers` arg, since each job streams its
own non-overlapping slice of the tokens.

## Run locally

For development you don't need real Firebase credentials. Two modes:

**1. Fake-token mode** — leave `credentials` empty. The token provider returns a
dummy bearer instead of minting a real OAuth token, so you can exercise the full
dispatch/queue/counter pipeline without Firebase:

```dotenv
FCM_BLAST_CREDENTIALS=
FCM_BLAST_PROJECT_ID=demo
```

**2. Mock endpoint** — point the sender at a local HTTP server to load-test the
engine without hitting FCM. Over plain `http` the `2tls` setting auto-falls back
to HTTP/1.1, so let the pool grow:

```dotenv
FCM_BLAST_CREDENTIALS=
FCM_BLAST_ENDPOINT=http://127.0.0.1:8080/send
FCM_BLAST_MAX_HOST_CONNECTIONS=          # null -> rateCap*0.3 (HTTP/1.1 mock)
```

Start a worker and kick off a blast (one worker shown):

```bash
php artisan queue:work redis --queue=fcm-blast --tries=1 --timeout=1800 &
php artisan tinker
>>> Laith343\FcmBlast\Facades\FcmBlast::start(total: 1000, workers: 1);
```

For multiple local workers, start that many `queue:work` processes:

**Linux / macOS (bash/zsh)**

```bash
for i in $(seq 1 4); do
  php artisan queue:work redis --queue=fcm-blast --tries=1 --timeout=1800 &
done
```

**Windows (PowerShell)**

```powershell
1..4 | ForEach-Object {
  Start-Process php -ArgumentList "artisan","queue:work","redis","--queue=fcm-blast","--tries=1","--timeout=1800"
}
```

**Run the test suite:**

```bash
composer test
```

## Run in production

Run the workers under a process supervisor instead of by hand, and make the
process count match the `workers` you pass to `start()`.

### Supervisor (Linux)

`/etc/supervisor/conf.d/fcm-blast.conf` — `numprocs=4` must match the `workers` you pass to `start()`:

```ini
[program:fcm-blast]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app/artisan queue:work redis --queue=fcm-blast --tries=1 --timeout=1800
autostart=true
autorestart=true
stopwaitsecs=1810
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/fcm-blast.log
stopasgroup=true
killasgroup=true
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start fcm-blast:*
```

### macOS (Homebrew supervisor)

```bash
brew install supervisor
# config dir: /opt/homebrew/etc/supervisor.d/ (Apple Silicon) or /usr/local/etc/supervisor.d/ (Intel)
brew services start supervisor
```

Use the same `[program:fcm-blast]` block as above, adjusting `command` to your `artisan` path and `user` to your account.

### Windows (production)

PowerShell `Start-Process` is fine for ad-hoc runs. For a managed service, wrap each `queue:work` in [NSSM](https://nssm.cc/) or run the workers inside WSL/Docker under Supervisor as above.

## Monitor

```php
$status = FcmBlast::status($runId);   // Laith343\FcmBlast\Support\BlastStatus

$status->sent;             // attempts completed (includes retries)
$status->ok;               // 2xx
$status->invalid;          // 404/400 -> InvalidTokenHandler fired
$status->throttled;        // FCM 429/503 retry events (quota) -> watch this for quota limits
$status->transportRetries; // transient transport retry events (timeout/reset) -> watch this for network limits
$status->failed;           // permanent errors / retries exhausted
$status->rps;
$status->avgLatencyMs;
$status->progressPercent;
$status->finished;
$status->stalled;          // running but no worker has reported in ~15s (workers died)
$status->toArray();        // for JSON endpoints
```

> `throttled` vs `transportRetries` tells you *which ceiling* you're hitting: `throttled` climbing = FCM project quota (request more); `transportRetries` climbing = network/connection limits (fewer connections, scale hosts). Both are retried automatically and only become `failed` once `max_retries` is exhausted.

A `Laith343\FcmBlast\Events\FcmBlastCompleted` event fires when a run finishes — listen for it to trigger follow-up work.

## How it hits 10k RPS

- **N parallel queue workers**, each `floor(rate_cap_per_sec / workers)` RPS.
- **Reused curl handle pool + persistent TCP** — no `curl_close` per request, avoids ephemeral-port exhaustion.
- **`kreait` OAuth token cached in your cache store**, refreshed ~10 min before expiry under a lock (no per-request token fetch, no stampede).
- **Paced token-bucket rate limiter** per worker — refills continuously so sends are spread evenly across each second instead of bursting at the window edge. The burst is bounded by `rate_burst` (not by connection count), which keeps you under FCM's sub-second quota even with a large connection pool.
- **Atomic delta counters** flushed every 500 ms (`UPDATE ... SET col = col + delta`) — use Postgres/MySQL for concurrent worker writes.
- **Exponential backoff + in-process retry** for `429`/`503` **and transient transport errors** (timeouts, connection resets, send/recv failures), so network blips self-heal; **404/400 pruning** via your handler.

### Ephemeral port range (one-time host tuning)

At thousands of RPS the OS can run out of ephemeral ports for outbound connections. Widen the range once:

**Windows (admin PowerShell)**

```powershell
netsh int ipv4 set dynamicport tcp start=10000 num=55000
```

**Linux**

```bash
sudo sysctl -w net.ipv4.ip_local_port_range="10000 65535"
# persist: echo 'net.ipv4.ip_local_port_range = 10000 65535' | sudo tee /etc/sysctl.d/99-fcm-blast.conf
```

**macOS**

```bash
sudo sysctl -w net.inet.ip.portrange.first=10000
sudo sysctl -w net.inet.ip.portrange.hifirst=10000
```

The reused handle pool keeps sockets alive across requests, so this mainly matters during connection ramp-up and on hosts under other network load.
