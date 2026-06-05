# laravel-fcm-blast

High-throughput Firebase Cloud Messaging sender for Laravel. Pushes **10k+ req/s** from PHP by driving a reused `curl_multi` handle pool with persistent TCP — OAuth handled by `kreait/firebase-php`, delivery handled by the engine.

This package is **backend only**. It exposes a programmatic API and a status DTO; build whatever UI/monitoring you want on top of `status()`.

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
```

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
    public function build(string $token): array
    {
        return ['notification' => ['title' => 'Hi', 'body' => 'There']];
    }
}
```

Optional — prune permanently invalid tokens (FCM 404/400):

```php
use Laith343\FcmBlast\Contracts\InvalidTokenHandler;

class PruneToken implements InvalidTokenHandler
{
    public function __invoke(string $token): void { /* deactivate $token */ }
}
```

## Run a blast

```php
use Laith343\FcmBlast\Facades\FcmBlast;

$runId = FcmBlast::tokensFrom(MyTokenSource::class)   // optional if set in config
    ->buildMessage(MyMessageBuilder::class)
    ->onInvalidToken(PruneToken::class)               // optional
    ->validateOnly(true)                              // optional dry run: validate, no delivery
    ->start(total: 1_000_000, workers: 4);
```

Run one queue worker process per worker (the process count **must** match the `workers` arg). Pick the command for your platform:

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

For production, run the workers under a process supervisor instead of by hand.

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

$status->sent;            // attempts completed (includes throttled retries)
$status->ok;              // 2xx
$status->invalid;         // 404/400 -> InvalidTokenHandler fired
$status->throttled;       // 429/503 retried with backoff
$status->failed;          // other errors / retries exhausted
$status->rps;
$status->avgLatencyMs;
$status->progressPercent;
$status->finished;
$status->toArray();       // for JSON endpoints
```

A `Laith343\FcmBlast\Events\FcmBlastCompleted` event fires when a run finishes — listen for it to trigger follow-up work.

## How it hits 10k RPS

- **N parallel queue workers**, each `floor(rate_cap_per_sec / workers)` RPS.
- **Reused curl handle pool + persistent TCP** — no `curl_close` per request, avoids ephemeral-port exhaustion.
- **`kreait` OAuth token cached in your cache store**, refreshed ~10 min before expiry under a lock (no per-request token fetch, no stampede).
- **Sliding-window rate limiter** (O(1) amortized) per worker.
- **Atomic delta counters** flushed every 500 ms (`UPDATE ... SET col = col + delta`) — use Postgres/MySQL for concurrent worker writes.
- **429/503 exponential backoff + in-process retry**; **404/400 pruning** via your handler.

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
