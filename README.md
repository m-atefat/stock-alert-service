# Price Alert System

A user sets a target price. When the market crosses it, they get exactly one
email, and the alert never fires again.

That sentence is the whole product. Everything below exists to make the words
**"exactly one"** and **"never again"** survive a crashed worker, a dropped
message, a flushed Redis, and a broker outage.

**Stack:** Laravel 12 · PHP 8.4 · PostgreSQL 17 · Redis 7 · RabbitMQ 3.13
**Instrument:** XAUUSD (gold/USD) · **Channel:** email

---

## Quick start

Requires Docker and Docker Compose. Nothing else — no local PHP, no Composer.

```bash
cp .env.example .env
docker compose up -d --build
```

First boot takes a few minutes. `docker-entrypoint.sh` runs `composer install`,
generates `APP_KEY`, applies migrations, and declares the RabbitMQ topology —
no manual steps. The `php` and `supervisor` containers share a bind mount and
start together, so that work is serialised with `flock`: whichever arrives
first does it while the other blocks.

| | URL |
|---|---|
| API | http://localhost:8000 |
| Mailpit (inbox) | http://localhost:8025 |
| RabbitMQ management | http://localhost:15673 — `guest` / `guest` |

Check it came up:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/up      # 200
docker compose exec supervisor \
  supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status       # 4x RUNNING
```

> `supervisorctl` needs the explicit `-c`. Without it, it resolves the distro's
> default config and looks for a socket that does not exist.

---

## Try it

The demo needs no API key and no external service.

**1 — token**

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/register \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Demo","email":"demo@example.test","password":"password123"}' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["token"];')
```

**2 — the market is at 4300**

```bash
docker compose exec php php artisan price:tick --price=4300
```

`--price` swaps the provider for a fixed quote. Everything downstream — cache,
RabbitMQ, matching, claim, delivery — runs exactly as in production.

**3 — "tell me when it hits 4350"**

```bash
curl -s -X POST http://localhost:8000/api/alerts \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"target_price":"4350.00000000"}'
```

Returns `201` with `"direction":"above"`. Direction is **inferred**, never
client-supplied: whichever side of the current price the target lands on is the
side that makes it a genuine future event.

**4 — price moves, but not far enough. No email.**

```bash
docker compose exec php php artisan price:tick --price=4320
```

**5 — it crosses. Email.**

```bash
docker compose exec php php artisan price:tick --price=4360
```

Read it at http://localhost:8025.

**6 — prove the guarantees**

```bash
docker compose exec postgres psql -U price_alerts -d price_alerts -c "
SELECT a.target_price, a.triggered_price, a.status AS alert,
       n.status AS notif, n.attempts
FROM price_alerts a JOIN alert_notifications n ON n.alert_id = a.id
ORDER BY a.id DESC LIMIT 1;"
```

`alert=2` (triggered), `notif=2` (sent), `attempts=1`. Now tick again at 4400 —
**no second email.** The alert was removed from the Redis index and
compare-and-set in Postgres. It cannot fire twice.

---

## Architecture

```
      HTTP                    ┌──────────── Postgres ─────────────┐
  ┌──────────┐                │  price_alerts   (source of truth) │
  │  nginx   │                │  alert_notifications (outbox +    │
  │  php-fpm │───── CRUD ────▶│                       ledger)     │
  └──────────┘                └───────────────────────────────────┘
       │ writes the ZSET synchronously, in the same request
       ▼
  ┌──────────────────── Redis ────────────────────┐
  │ alerts:{XAUUSD}:above   ZSET score=target     │   index, never truth
  │ alerts:{XAUUSD}:below   ZSET score=target     │
  │ price:current:XAUUSD    last quote (60s TTL)  │
  └───────────────────────────────────────────────┘
       ▲                                   │
       │ ZRANGEBYSCORE + ZREM (one Lua)    │
       │                                   ▼
  price:tick ──▶ PriceIngestor ──▶ [prices] ──price.*──▶ prices.ticks
                                                             │
                                            match-consumer ──┤
                                                             ▼
                                                      AlertClaimer
                                       ┌─────────────────────┴──────────┐
                       full batch?     │                                │
                  [prices] prices.drain◀┘                    [notifications]
                             │                                  notify.mail
                    drain-consumer                                   │
                                                            mail-consumer
                                                                     │
                                                                   SMTP
```

### The hot path

A tick becomes an email in five steps:

1. **`price:tick`** fetches a quote, writes it to Redis, dispatches `PriceTicked`.
   The command does nothing else — no alert evaluation, no DB reads.
2. **`match-consumer`** runs one Lua script per direction:
   `ZRANGEBYSCORE` the crossed range, then `ZREM` the ids it found — atomically.
   Removal *is* the claim. An id can never be claimed twice.
3. **`AlertClaimer`** opens one transaction for the whole batch:
   `UPDATE … WHERE status = active RETURNING id` (identities, not a row count),
   then a bulk `INSERT … ON CONFLICT DO NOTHING RETURNING id`.
4. After commit, one `Queue::bulk()` publishes a delivery job per notification.
5. **`mail-consumer`** acquires the notification row with a compare-and-set,
   sends, and records the outcome.

See **Benchmarks** below for measured cost at scale.

### Why a sorted set

The naive design scans every active alert on every tick: `O(N)` per tick, and
with a 1-second cadence that is `O(N)` per second forever.

Scoring alerts by target price in a ZSET turns "which alerts did this price
cross?" into a range query. One `ZRANGEBYSCORE` answers it in `O(log N + M)`.

It must be **one** Redis round trip, not two, or two consumers racing the same
tick both read the same ids before either removes them, and the user gets two
emails. `ZRANGEBYSCORE` + `ZREM` therefore live in a single Lua script
(`app/Redis/Scripts/match_alerts.lua`), which Redis executes atomically.

The `{XAUUSD}` hash tag pins both ZSETs to one slot, so the script stays valid
under Redis Cluster and `alerts:rebuild` can `RENAME` across them.

**Redis is an index, never truth.** Flush it and `alerts:rebuild` replays every
active alert from Postgres into a shadow key, then swaps it in with one atomic
`RENAME`.

### Exactly once, over at-least-once transport

RabbitMQ redelivers. Three independent guards make the *effect* exactly-once:

| guard | where | stops |
|---|---|---|
| `ZREM` inside the Lua claim | Redis | two consumers claiming one tick |
| `UPDATE … WHERE status = active RETURNING id` | Postgres | a redelivered tick re-triggering |
| `UNIQUE (alert_id)` on `alert_notifications` | Postgres | a second delivery row ever existing |

They protect different things and all three are load-bearing. There is
deliberately **no** Redis "already fired" key: Postgres cannot evict one, and it
would be a weaker duplicate of a guarantee the schema already enforces.

### The outbox

`AlertClaimer` writes the notification row **inside** the claim transaction,
before anything is published. So a publish lost to a broker outage leaves a
durable `pending` row, and `notifications:redispatch-pending` replays it.

The alternative — publish first, record later — has no record of the intent if
the process dies, and the alert is silently lost.

### Delivery is one-shot

`SendNotificationJob` acquires its row with a compare-and-set and uses the
**`attempts` column**, not the broker's redelivery header, as the try budget:

```php
->whereIn('status', [Pending, Sending])
->where('attempts', '<', $this->tries)
```

This matters. The transport's `attempts()` is derived from an AMQP header that
is only written on an explicit `release()` — a broker redelivery of an unacked
message carries the *original* header. A worker killed mid-send therefore comes
back looking like attempt 1 forever. Reading the row instead makes the budget
real: two attempts, then `failed`, terminal.

`Sending` is included so a crash redelivery can re-acquire rather than strand
the row. Every write past the acquire goes through one guard that refuses to
overwrite a terminal row, so a straggler can never turn `failed` into `sent`.

---

## Benchmarks

Reproduce with:

```bash
docker compose exec php php artisan alerts:benchmark \
  --scales=1000,10000,100000,1000000 --iterations=30 --e2e=20
```

### Claim cost as the index grows

| alerts indexed | tick matching nothing | tick claiming 100 |
|---:|---:|---:|
| 1,000 | 0.06 ms | 0.18 ms |
| 10,000 | 0.02 ms | 0.08 ms |
| 100,000 | 0.02 ms | 0.07 ms |
| **1,000,000** | **0.05 ms** | **0.21 ms** |

**A thousand alerts and a million alerts cost the same.** That is the whole
argument for the sorted set, measured rather than asserted: matching is
`O(log N + M)`, so cost tracks how many alerts *fired*, not how many exist. The
spread across these rows is measurement noise, not a trend — a naive `O(N)` scan
would have grown by three orders of magnitude down this table.

Both columns are p50 of 30 timed claims against a live Redis. The 1,000-alert
row rests on fewer samples than the rest: the benchmark only records an
iteration where a *full* batch of 100 came back, and at that scale the Above
side holds 500 alerts, so it exhausts after five. Partial claims are discarded
rather than averaged in as fast empty calls.

"in index" is verified against "seeded" on every run, so a divergence between
what was measured and what was actually indexed shows up instead of being
assumed.

### End-to-end latency

Measured from `PriceTicked` dispatch to `alert_notifications.sent_at` — the
whole path: RabbitMQ, the Lua claim, the Postgres transaction, the second hop
to `notify.mail`, and the SMTP send.

| | |
|---:|---:|
| p50 | **724 ms** |
| p95 | 1,301 ms |
| p99 | 1,364 ms |
| max | 1,364 ms |

20 samples, on a laptop Docker stack with all six services and both consumers on
one machine. Mail goes to the bundled Mailpit, so this measures the pipeline and
not a real ESP's network latency or rate limit.

Timing comes from `sent_at` in the database rather than by polling the mail
server. An earlier version polled Mailpit's `total` field, which reports mailbox
size rather than whether a specific message arrived — so the check passed
unconditionally and measured nothing.

---

## Failure model

| failure | detected by | behaviour |
|---|---|---|
| Redis flushed | empty index | `alerts:rebuild` replays from Postgres; `alerts:reconcile` also catches crossed alerts within 60 s |
| Claim succeeds, DB write lost | alert still `active` | `alerts:reconcile` re-claims it |
| Commit succeeds, publish lost | row stuck `pending` | `notifications:redispatch-pending` re-dispatches |
| Worker killed mid-send | row stuck `sending` | redelivery re-acquires while budget remains, else terminal `failed` |
| Duplicate tick delivery | — | index already drained; CAS matches nothing; no-op |
| Mail provider rejects | job exception | 2 attempts, then terminal `failed`. Never becomes `sent` |
| Job exhausts retries | — | dead-lettered; `rabbitmq:replay-dlq` puts it back |
| Provider down / rate-limited | `PriceProviderException` | tick skipped, logged once on the transition, alerts stay `active` |
| Huge sweep | full batch | chunk-and-requeue onto `prices.drain`, so a long drain never blocks fresh ticks |

`alerts:reconcile` is **load-bearing, not a backstop**. It is the only recovery
path for a claim lost because Redis was unavailable, and for the gap between the
ZSET claim and the database write.

---

## Data model

```
price_alerts
  id, user_id, symbol, target_price numeric(18,8), direction, status,
  triggered_price, triggered_at, timestamps

  UNIQUE (user_id, symbol, target_price, direction) WHERE status = active
  INDEX  (symbol, direction, target_price)          WHERE status = active

alert_notifications                       -- outbox + delivery ledger
  id, alert_id UNIQUE → price_alerts ON DELETE CASCADE,
  status, attempts, sent_at, error, timestamps

  INDEX (created_at) WHERE status = pending
```

Both partial indexes are the concrete reason this is PostgreSQL and not MySQL.
"Unique **while active**" is exactly the constraint the product needs — a user
may re-create an alert at a price they previously triggered — and Postgres
expresses it directly.

Prices are `numeric(18,8)` and compared with **bcmath**, never floats. ZSET
scores are the price scaled by 1e8 to an integer, with an explicit guard at
2^53 — beyond that a double cannot hold the value exactly and the boundary case
(`target == price` must fire) would break silently.

---

## Commands

| command | schedule | purpose |
|---|---|---|
| `price:tick [--price=]` | opt-in | fetch a quote and dispatch the tick |
| `alerts:reconcile` | every minute | re-claim active alerts already crossed |
| `notifications:redispatch-pending` | every minute | replay notifications whose publish was lost |
| `alerts:prune` | daily 03:00 | drop triggered alerts past retention |
| `alerts:rebuild` | manual | rebuild the Redis index from Postgres |
| `alerts:benchmark` | manual | measure claim cost as the index grows |
| `rabbitmq:declare-topology` | on boot | declare exchanges, queues, DLX |
| `rabbitmq:replay-dlq <queue>` | manual | move dead-lettered messages back |

### Processes

`docker/php/supervisord.conf` runs four:

| program | consumes |
|---|---|
| `match-consumer` | `prices.ticks` — claims crossed alerts |
| `drain-consumer` | `prices.drain` — continues a large sweep |
| `mail-consumer` | `notify.mail` — sends |
| `scheduler` | `schedule:work`, evaluating sub-minute tasks |

`--sleep=0.1` on the consumers is deliberate. Omitting it inherits a **3-second**
default, which measured p50 2371 ms end-to-end against 391 ms with the flag.

---

## Price feed

`price:tick` is **not scheduled by default.** GoldAPI.io's free tier allows
roughly **100 requests per month**. A one-second cadence needs 2.6 million; even
one call every fifteen minutes needs ~2,900. No cadence both keeps the price
fresh and fits the quota, so polling is opt-in:

```bash
PRICE_TICK_ENABLED=true     # then: docker compose restart supervisor
```

Use `--price=` for demos and tests — it needs no key and no quota.

Two consequences worth knowing:

- The cached price has a **60-second TTL**. With no ticking, `POST /api/alerts`
  returns `503 PRICE_UNAVAILABLE`, because accepting an alert against an unknown
  price cannot infer a direction.
- `alerts:reconcile` has nothing to reconcile against and logs that it skipped.

---

## Testing

```bash
docker compose exec php php artisan test        # 93 passing
docker compose exec php ./vendor/bin/pint --test
```

The suite runs against **real** Postgres, Redis and RabbitMQ — no in-memory
fakes for the parts where the bugs live. Several tests publish a real AMQP
message and consume it back with consumer priority so they win the race against
the live worker on the same queue.

Coverage is weighted toward the guarantees rather than the happy path:
concurrent claims producing one row, a redelivery after `failed` staying
`failed`, a crash-stranded `sending` row reaching a terminal state, a broker
redelivery leaving the attempts header unchanged, and a Lua error surfacing
instead of silently reporting "nothing matched".

---

## Configuration

Everything is in `.env` — see `.env.example` for the annotated set.

| variable | default | note |
|---|---|---|
| `PRICE_TICK_ENABLED` | `false` | see **Price feed** |
| `PRICE_MATCH_BATCH_SIZE` | `1000` | ids claimed per Lua call, per direction |
| `PRICE_MAX_DRAIN_PASSES` | `5000` | bound on the chunk-and-requeue chain |
| `NOTIFICATION_REDISPATCH_AFTER` | `60` | seconds `pending` before replay |
| `ALERT_RETENTION_DAYS` | `30` | before `alerts:prune` removes it |

`PRICE_MATCH_BATCH_SIZE` has a hard ceiling of **6553**: `AlertClaimer` binds 5
parameters per notification row and claims up to twice this value per pass,
against Postgres' 65,535-parameter statement limit.

### After changing code or config

The consumers are long-running daemons holding both in memory:

```bash
docker compose exec php php artisan queue:restart    # PHP or config changes
docker compose build && docker compose up -d         # supervisord.conf only
```

Only `supervisord.conf` needs a rebuild — it is `COPY`d into the image, not
bind-mounted. Both are silent-failure modes where a stale copy quietly wins.
