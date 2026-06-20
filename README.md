# Match Score API

A high-performance backend service for recording online game match results and
serving a real-time player leaderboard. Built with **PHP 8.4** and **CakePHP 5**,
backed by **PostgreSQL** and **Redis**.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Quick Start (Docker)](#quick-start-docker)
3. [Manual Setup](#manual-setup)
4. [Migrations & Seeds](#migrations--seeds)
5. [Running the Server](#running-the-server)
6. [Running Tests](#running-tests)
7. [API Reference & Sample Requests](#api-reference--sample-requests)
8. [Environment Variables](#environment-variables)
9. [Architecture Overview](#architecture-overview)

---

## Requirements

| Tool | Version |
|------|---------|
| PHP  | 8.4+    |
| Composer | 2.x |
| PostgreSQL | 15+ |
| Redis | 7+   |
| (Optional) Docker & Docker Compose | 24+ |

---

## Quick Start (Docker)

```bash
git clone <repo>
cd match-score

# Start all services (PHP-FPM + Nginx + PostgreSQL + Redis)
docker-compose up -d

# Run migrations
docker-compose exec app bin/cake migrations migrate

# Seed sample data (20 players + match history)
docker-compose exec app bin/cake migrations seed --seed GameDataSeeder

# Verify health
curl http://localhost:8765/health
```

---

## Manual Setup

### 1. Install PHP dependencies

```bash
composer install
```

### 2. Configure environment

Copy and edit the config file:

```bash
cp config/app.example.php config/app.php
```

Key settings to update:

```php
'Datasources' => [
    'default' => [
        'driver'   => \Cake\Database\Driver\Postgres::class,
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'username' => 'match-score',
        'password' => 'your-password',
        'database' => 'match-score',
    ],
],
'Redis' => [
    'host' => '127.0.0.1',
    'port' => 6379,
],
```

### 3. Create the PostgreSQL database

```sql
CREATE USER "match-score" WITH PASSWORD 'your-password';
CREATE DATABASE "match-score" OWNER "match-score";
```

---

## Migrations & Seeds

```bash
# Run all migrations (creates users, match_reports, trophy_history tables)
bin/cake migrations migrate

# Rollback all migrations
bin/cake migrations rollback

# Seed 20 realistic players + match history
bin/cake migrations seed --seed GameDataSeeder
```

The seeder is **idempotent** — it checks for existing data and skips if already present.

---

## Running the Server

```bash
# Built-in PHP development server on port 8765
bin/cake server -p 8765

# Or with a custom host
bin/cake server -H 0.0.0.0 -p 8765
```

---

## Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run only unit tests
vendor/bin/phpunit tests/TestCase/Service/

# Run only integration tests
vendor/bin/phpunit tests/TestCase/Controller/

# With coverage (requires Xdebug or PCOV)
vendor/bin/phpunit --coverage-html coverage/
```

### Test coverage includes:

| Scenario | Test file |
|----------|-----------|
| Successful match report | `MatchReportServiceTest` |
| Duplicate request — same payload | `MatchReportServiceTest`, `MatchesControllerTest` |
| Duplicate request — different payload (conflict) | `MatchReportServiceTest`, `MatchesControllerTest` |
| Validation errors (missing fields, invalid result) | `MatchesControllerTest` |
| Non-existent user_id | `MatchReportServiceTest`, `MatchesControllerTest` |
| Rate limiting (429) | `MatchesControllerTest` |
| Leaderboard from Redis | `LeaderboardServiceTest` |
| Fallback to SQL when Redis unavailable | `LeaderboardServiceTest` |
| Fallback to SQL when Redis returns empty | `LeaderboardServiceTest` |
| Pagination correctness | `LeaderboardServiceTest`, `LeaderboardControllerTest` |

---

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
    { "rank": 1, "user_id": 1, "name": "Alice",   "score": 320 },
    { "rank": 2, "user_id": 2, "name": "Bob",     "score": 315 },
    { "rank": 3, "user_id": 3, "name": "Charlie", "score": 300 }
  ],
  "source": "redis",
  "meta": { "limit": 10, "offset": 0, "count": 3 }
}
```

**Response (SQL fallback):**
```json
{
  "success": true,
  "data": [
    { "rank": 1, "user_id": 1, "name": "Alice", "score": 320 }
  ],
  "source": "sql",
  "meta": { "limit": 10, "offset": 0, "count": 1 }
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
  "status":    "ok",
  "database":  "ok",
  "redis":     "ok",
  "timestamp": "2024-03-10T12:00:00+00:00"
}
```

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `DEBUG` | `true` | CakePHP debug mode |
| `SECURITY_SALT` | *(set in config)* | App security salt |
| `DB_HOST` | `127.0.0.1` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_NAME` | `match-score` | Database name |
| `DB_USER` | `match-score` | DB username |
| `DB_PASS` | — | DB password |
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_PASSWORD` | `null` | Redis auth password |
| `REDIS_DB` | `0` | Redis database index |

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

### Key design decisions

- **No async queue for score updates**: Synchronous writes ensure idempotency
  guarantees hold without distributed coordination overhead.
- **Fail-open rate limiting**: Redis outages should not block legitimate match
  reports. Strict limiting can be enforced at the API gateway layer in production.
- **Composite PK on `trophy_history`**: `(user_id, match_id)` enforces exactly
  one audit entry per player per match at the DB level.
- **`autoRender = false`**: Controllers write JSON directly — no template engine
  overhead for a pure API service.

See [DESIGN.md](DESIGN.md) for deeper explanations of every architectural decision.
