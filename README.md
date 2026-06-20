# Match Score API

A high-performance backend service for recording online game match results and
serving a real-time player leaderboard. Built with **PHP 8.4** and **CakePHP 5**,
backed by **PostgreSQL** and **Redis**.
---

## Requirements

| Tool                               | Version |
|------------------------------------|---------|
| PHP                                | 8.4+    |
| PostgreSQL                         | 15+     |
| Redis                              | 7+      |
| (Optional) Docker & Docker Compose | 24+     |

---

## Quick Start (Docker)

```bash
git clone <repo>
cd match-score

composer i

# Run pgsql container
docker-compose up -d match-score-pgsql

# Run match score redis container
docker-compose up -d match-score-redis

# Run migrations
docker-compose exec app bin/cake migrations migrate

# Seed with pure php script
php config/Seeds/pure_seeder.php
```


## Running the Server

```bash
# Built-in PHP development server on port 8765
bin/cake server -p 8000

# Or with a custom host
php -S 0.0.0.0:8000 -t webroot
```

## Swagger documents page
Swagger api and requests documents are available on :
http://localhost:8000/doc


## Running Tests

```bash
# Run all tests
vendor/bin/phpunit
# Run only integration tests
vendor/bin/phpunit tests/TestCase/Controller/
```

## API Reference & Sample Requests

### POST /matches/report

Record a match result.

**Request:**

```bash
curl -X POST http://localhost:8765/matches/report \
  -H "Content-Type: application/json" \
  -d '{
    "request_id":  "9a7e91f2-1fd4-45a3-9ff0-2b3d9a0ef111",
    "user_id":     12,
    "match_id":    8801,
    "result":      "win",
    "score_delta": 25,
    "reported_at": 1710000000
  }'
```

**Success response (200):**

```json
{
    "success": true,
    "duplicate": false,
    "user_id": 12,
    "match_id": "8801",
    "new_score": 215
}
```

**Duplicate — same payload (200):**

```json
{
    "success": true,
    "duplicate": true,
    "user_id": 12,
    "match_id": "8801",
    "new_score": 215
}
```

**Duplicate — different payload (409):**

```json
{
    "success": false,
    "error": "REQUEST_ID_CONFLICT"
}
```

**Validation error (422):**

```json
{
    "success": false,
    "error": "VALIDATION_ERROR",
    "errors": {
        "result": "result must be one of: win, lose, draw."
    }
}
```

**Rate limit exceeded (429):**

```json
{
    "success": false,
    "error": "RATE_LIMIT_EXCEEDED",
    "message": "Too many requests. Please retry after 8 seconds.",
    "retry_after": 8
}
```

---

### GET /leaderboard

Retrieve the top players.

**Request:**

```bash
curl "http://localhost:8765/leaderboard?limit=10&offset=0"
```

**Response (Redis):**

```json
{
    "success": true,
    "data": [
        {
            "rank": 1,
            "user_id": 1,
            "name": "Alice",
            "score": 320
        },
        {
            "rank": 2,
            "user_id": 2,
            "name": "Bob",
            "score": 315
        },
        {
            "rank": 3,
            "user_id": 3,
            "name": "Charlie",
            "score": 300
        }
    ],
    "source": "redis",
    "meta": {
        "limit": 10,
        "offset": 0,
        "count": 3
    }
}
```

**Response (SQL fallback):**

```json
{
    "success": true,
    "data": [
        {
            "rank": 1,
            "user_id": 1,
            "name": "Alice",
            "score": 320
        }
    ],
    "source": "sql",
    "meta": {
        "limit": 10,
        "offset": 0,
        "count": 1
    }
}
```

---

### GET /health

Check service health.

```bash
curl http://localhost:8765/health
```

```json
{
    "status": "ok",
    "database": "ok",
    "redis": "ok",
    "timestamp": "2024-03-10T12:00:00+00:00"
}
```

---

## Environment Variables

| Variable         | Default           | Description          |
|------------------|-------------------|----------------------|
| `DEBUG`          | `true`            | CakePHP debug mode   |
| `SECURITY_SALT`  | *(set in config)* | App security salt    |
| `DB_HOST`        | `127.0.0.1`       | PostgreSQL host      |
| `DB_PORT`        | `5432`            | PostgreSQL port      |
| `DB_NAME`        | `match-score`     | Database name        |
| `DB_USER`        | `match-score`     | DB username          |
| `DB_PASS`        | —                 | DB password          |
| `REDIS_HOST`     | `127.0.0.1`       | Redis host           |
| `REDIS_PORT`     | `6379`            | Redis port           |
| `REDIS_PASSWORD` | `null`            | Redis auth password  |
| `REDIS_DB`       | `0`               | Redis database index |

---

## Architecture Overview

```
Client
  │
  ▼
BodyParserMiddleware     ← parses JSON body
  │
RateLimitMiddleware      ← Redis INCR sliding window (5 req / 10 sec per IP+uid)
  │
RoutingMiddleware
  │
  ├── POST /matches/report ──► MatchesController
  │                                  │
  │                            validate input
  │                                  │
  │                            MatchReportService
  │                              │         │
  │                       Redis idem    DB fallback
  │                       fast-path         │
  │                              │    BEGIN TRANSACTION
  │                              │      INSERT match_reports
  │                              │      UPDATE users.score
  │                              │      INSERT trophy_history
  │                              │    COMMIT
  │                              │
  │                        Redis ZADD + HSET (leaderboard)
  │                        Redis SETEX (idempotency cache)
  │
  └── GET /leaderboard ──► LeaderboardController
                                  │
                            LeaderboardService
                              │           │
                           Redis        SQL fallback
                           ZREVRANGE    ORDER BY score DESC
```

## Questions

### 1. What happens if two concurrent requests are submitted for the same user?

- Layer 1 - `Database Row-Level Locking (Primary Defense)`:
  In the UserRepository, PostgreSQL's SELECT ... FOR UPDATE acquires an exclusive row-level lock on the user's record
  before updating the score. This serializes concurrent updates at the database level, preventing lost updates.
  The second request waits until the first transaction commits or rolls back, ensuring each score delta is correctly
  applied in sequence.

- Layer 2 - `Redis Distributed Lock for Idempotency`:
  The MatchReportService uses Redis SET NX (set if not exists) to acquire a distributed lock per request_id before
  processing.
  If two requests arrive with the same request_id, only one acquires the lock and processes; the other either finds the
  cached result
  or receives a concurrency exception. This prevents duplicate processing even across multiple application servers.


- Layer 3 - `Queue-Based Serialization (For High Throughput)`:
  For extreme concurrency scenarios, requests can be published to RabbitMQ with the user_id as the routing key. RabbitMQ
  guarantees message ordering within the same routing key, naturally serializing operations per user without database
  contention.

#### Handling Different Scenarios:

- Same request_id + same payload → Returns cached result with duplicate: true
- Same request_id + different payload → Returns 409 REQUEST_ID_CONFLICT
- Different request_id + same user → Row-level lock serializes updates correctly
- High-frequency requests → Rate limiter (already implemented) blocks excessive calls

### 2. What happens if the service goes down mid-operation?

The system uses a layered recovery architecture ensuring data consistency regardless of when a crash occurs:

- Layer 1 - `Database Transaction Atomicity`:
  The MatchReportService.process() method wraps all three database operations (match_reports insert, users.score update,
  trophy_history insert) in a single PostgreSQL transaction. This guarantees ACID properties:
  Crash before transaction: Nothing is persisted, the request can be safely retried with the same request_id (
  idempotent)
  Crash during transaction: PostgreSQL's WAL (Write-Ahead Logging) automatically rolls back all partial changes. No
  dirty
  data remains
  Crash after transaction commit: All three database records are permanently safe in PostgreSQL

- Layer 2 - `Recovery Table for Non-Transactional Operations`:
  Redis updates happen outside the database transaction to avoid distributed transactions. A recovery_points table
  records
  the pending Redis operation before it executes. If the service crashes after the database commit but before Redis
  updates, a cron job (RecoverTransactionsCommand) runs every 5 minutes to:
  Find recovery records older than 5 minutes with status pending_redis_update
  Idempotently replay the Redis leaderboard update
  Mark the recovery record as completed

### 3. If the leaderboard gets much larger, how would you change the design?

When scaling to millions of users, I evolve the architecture through four progressive optimizations:

Optimization 1 - Redis Sorted Set Sharding:
Split the single Redis sorted set into 16+ shards using consistent hashing based on user_id. Each shard holds a subset
of users. The getTopPlayers operation uses a scatter-gather pattern: query the top N from each shard, merge results in
application memory, and return the final sorted list. This distributes memory and computation across multiple Redis
instances or cluster nodes.

Optimization 2 - Tiered Caching Strategy:
Implement three cache layers with different TTLs:

- L1 - Application Memory Cache (5 seconds): Holds the most frequently accessed leaderboard pages in process memory
- L2 - Redis Cache (60 seconds): Pre-computed leaderboard snapshots for common limit/offset combinations
- L3 - PostgreSQL Materialized Views (5 minutes): Database-level pre-computed rankings refreshed periodically

### 4. If we want weekly and monthly leaderboards, how would the design evolve?

The design evolves using a time-bucketed key strategy with automated lifecycle management:

Design Approach - Time-Bucketed Redis Keys:
Instead of a single leaderboard key, the system maintains separate Redis sorted sets for each time period using a naming
convention:

- leaderboard:all_time — permanent, never expires
- leaderboard:daily:2026-06-20 — TTL 3 days
- leaderboard:weekly:2026-W25 — TTL 14 days
- leaderboard:monthly:2026-06 — TTL 60 days

### 5. If you want to add anti-cheat rules, where is the most appropriate place in your architecture?

The most appropriate place is inside MatchReportService.process(), after validation but before the database transaction,
implemented as a Pipeline Pattern with a separate background analysis service for complex pattern detection.

Why This Location:

Before the transaction prevents fraudulent data from being persisted, avoiding costly rollbacks or cleanup

- After validation ensures the data is well-formed before running expensive anti-cheat checks
- Inside the service rather than middleware keeps business logic cohesive and testable
- Separate from the controller maintains single responsibility and allows reuse across different entry points (API, CLI,
  queue consumers)

Pipeline Design:
Rules are executed sequentially from fastest/cheapest to slowest/most expensive. Each rule returns one of three
decisions:

- `PASS`: No violation, continue to next rule
- `FLAG`: Suspicious but not definitive — process the request but mark it for admin review and log the violation
- `BLOCK`: Definite cheating — reject the request immediately, stop processing remaining rules
