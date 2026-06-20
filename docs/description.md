DESIGN.md 1. Project Overview Purpose of the Match Reporting &
Leaderboard Service This service is designed for an online game with
over a million daily active players. It provides a high-performance,
reliable backend to accept match results and serve a real-time
leaderboard. The system's core responsibilities are to durably record
match outcomes, atomically update player scores, and provide a ranked
list of top players.

Functional Requirements

Match Reporting: An API to submit a match result (win, lose, draw) for a
user, which updates their score.

Leaderboard Retrieval: An API to fetch the top players, ordered by
score, with support for pagination.

Idempotent Processing: Guarantee that submitting the same match report
multiple times does not incorrectly inflate a player's score.

Trophy History: Maintain an immutable, auditable log of every single
score change.

Non-Functional Requirements

High Performance: The leaderboard must be served with low latency, even
under heavy read load. Score update processing is asynchronous to ensure
the API remains responsive.

Reliability: Score updates must never be lost. The system must guarantee
atomicity for score changes and their related records. It must be
resilient to failures in any component, such as the queue or cache.

Scalability: The architecture must support horizontal scaling of the API
server and worker processes to handle a growing player base and request
volume.

2.  Technology Stack Language: PHP 8.4

Framework: CakePHP 5.x

Primary Database: PostgreSQL (Source of Truth)

Cache & Leaderboard: Redis (Sorted Sets)

Message Queue: RabbitMQ

Job Processing: Background worker processes (CakePHP console commands)

Monitoring: Structured logging (e.g., JSON format to stdout/CloudWatch)

3.  System Architecture The system follows an event-driven, asynchronous
    architecture where PostgreSQL is the single source of truth. The
    processing order you recommended has been adopted to guarantee
    durability and consistency.

API Server: A stateless web server responsible for request validation,
rate limiting, and idempotency checks. It does not perform core business
logic but instead publishes a validated "score event" to the queue.

Queue (RabbitMQ): Decouples the API from processing. It provides
durable, ordered delivery of score jobs to workers, acting as a buffer
during traffic spikes and enabling retries.

Background Workers: Consumers that subscribe to the RabbitMQ queue. Each
worker executes a single, atomic database transaction for a score job
and then updates Redis.

PostgreSQL (Source of Truth): The authoritative data store for all
persistent data: users, match_reports, and trophy_history.

Redis (Read-Optimized View): A materialized view of the leaderboard,
stored as a Sorted Set. It is rebuilt from PostgreSQL in case of
failure.

Failure Recovery:

Worker Crashes: The RabbitMQ message remains unacknowledged and is
redelivered.

Redis Update Failure: The worker retries the Redis write. On final
failure, the discrepancy is flagged and will be resolved by a periodic
reconciliation job.

Reconciliation Job: A scheduled background task that periodically
fetches the top N scores from PostgreSQL and fully rebuilds the Redis
Sorted Set to guarantee eventual consistency.

\![Architecture Diagram Placeholder\] (Sequence: Client -\> API -\>
RabbitMQ -\> Worker -\> (DB Transaction Commit) -\> Redis Update -\> Ack
Message)

4.  Database Design (PostgreSQL) This schema preserves the exact table
    and column names from the original specification.

## 4.1 Table: users

Stores player profiles and their current aggregate score.

Column   Type   Constraints   Description
  -------- ------ ------------- -------------

id INT PRIMARY KEY, GENERATED ALWAYS AS IDENTITY Unique player
identifier. name VARCHAR(255) NOT NULL Player's display name. score INT
NOT NULL, DEFAULT 0, CHECK (score \>= 0) Player's current total score.
created_at TIMESTAMPTZ NOT NULL, DEFAULT NOW() Account creation time.
updated_at TIMESTAMPTZ NOT NULL, DEFAULT NOW() Last score update time.
\## 4.2 Table: match_reports Serves as an event log and guarantees
idempotency via a unique request_id.

Column   Type   Constraints   Description
  -------- ------ ------------- -------------

id BIGINT PRIMARY KEY, GENERATED ALWAYS AS IDENTITY Internal record ID.
request_id UUID NOT NULL, UNIQUE Client-provided idempotency key.
user_id INT NOT NULL, FOREIGN KEY (users) The player this report is for.
match_id INT NOT NULL The match being reported. result VARCHAR(4) NOT
NULL, CHECK (result IN ('win','lose','draw')) Outcome of the match.
score_delta INT NOT NULL Points earned or lost. reported_at TIMESTAMPTZ
NOT NULL Timestamp from the client. created_at TIMESTAMPTZ NOT NULL,
DEFAULT NOW() Server processing time. Index: An index is automatically
created on request_id for the UNIQUE constraint, making idempotency
checks extremely fast.

## 4.3 Table: trophy_history

An immutable, append-only audit log for every score change.

Column   Type   Constraints   Description
  -------- ------ ------------- -------------

id BIGINT PRIMARY KEY, GENERATED ALWAYS AS IDENTITY Internal record ID.
user_id INT NOT NULL The player whose score changed. match_id INT NOT
NULL The match that caused the change. score_before INT NOT NULL
Player's score before this change. score_after INT NOT NULL Player's
score after this change. score_delta INT NOT NULL Points earned or lost
in this event. reason VARCHAR(50) NOT NULL, DEFAULT 'match_result' The
source of the score change (e.g., 'match_result', 'manual_adjustment').
created_at TIMESTAMPTZ NOT NULL, DEFAULT NOW() Timestamp of when the
history entry was created. Index: An index on (user_id, created_at DESC)
is crucial for quickly fetching a single player's trophy history.

5.  API Specification \## 5.1 POST /matches/report Records a new match
    result.

Request Body (JSON):

``` json
{
  "request_id": "9a7e91f2-1fd4-45a3-9ff0-2b3d9a0ef111",
  "user_id": 12,
  "match_id": 8801,
  "result": "win",
  "score_delta": 15,
  "reported_at": 1710000000
```

Validation Rules:

request_id: required, valid UUID version 4.

user_id: required, integer, must exist in the users table.

match_id: required, integer.

result: required, string, one of win, lose, draw.

score_delta: required, integer.

reported_at: required, integer, valid Unix timestamp.

Success Response (201 Created):

``` json
{
  "success": true,
  "duplicate": false,
  "user_id": 12,
  "match_id": 8801,
  "new_score": 215
```

Idempotency Behavior & Error Responses:

Duplicate with Same Payload (200 OK): If a request with a known
request_id and identical payload arrives, the job is not re-enqueued. A
cached success response is returned.

``` json
{
  "success": true,
  "duplicate": true,
  "user_id": 12,
  "match_id": 8801,
  "new_score": 215
```

Duplicate with Different Payload (409 Conflict): If a request_id is
reused with a different payload, the system rejects it.

``` json
{
  "success": false,
  "error": "REQUEST_ID_CONFLICT",
  "message": "The request_id has already been used with a different payload."
```

Rate Limit Exceeded (429 Too Many Requests):

``` json
{
  "success": false,
  "error": "RATE_LIMIT_EXCEEDED",
  "message": "Too many requests. Please slow down."
```

Validation Error (422 Unprocessable Entity):

``` json
{
  "success": false,
  "error": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "errors": {
    "user_id": ["The user_id field is required."]
  }
}
## 5.2 GET /leaderboard
Retrieves the current top players.

Query Parameters:

limit (optional, integer, default: 10, max: 100): Number of players to return.

offset (optional, integer, default: 0): Number of players to skip for pagination.

Success Response (200 OK - Redis Source):

```json
{
  "success": true,
  "data": [
    {
      "rank": 1,
      "user_id": 7,
      "name": "Alice",
      "score": 320
    }
  ],
  "source": "redis"
```

Success Response (200 OK - SQL Fallback): If Redis is unavailable, the
data is fetched directly from PostgreSQL and source is set to sql.

``` json
{
  "success": true,
  "data": [
    {
      "rank": 1,
      "user_id": 7,
      "name": "Alice",
      "score": 320
    }
  ],
  "source": "sql"
```

# 6. Professional Score Processing Flow

This is the core business logic, following the exact sequence you
specified.

Client sends POST /matches/report.

API Validate: The request payload is rigorously validated against the
rules in Section 5.1.

Rate Limit Check: A Redis key rate_limit:{user_id}:{client_ip} is
checked and incremented with a 10-second TTL. If the count exceeds 5,
the API returns a 429 error.

Idempotency Check: The request_id is checked against a unique constraint
in match_reports. If present, the payload hash is compared to the
existing one to determine the correct idempotent response
(success/conflict).

Enqueue Job: If the request is novel, a message containing the entire
validated payload is published to a durable RabbitMQ queue (e.g.,
match_report.processing). The API returns a 202 Accepted to the client.
The score update is not done synchronously.

Worker Consumes: A background worker picks up the message.

Database Transaction: The worker opens a database transaction and
performs the following sequentially:

INSERT into match_reports.

SELECT score FROM users WHERE id = \$1 FOR UPDATE (locks the user row).

UPDATE users SET score = score + \$1, updated_at = NOW() WHERE id = \$2.

INSERT into trophy_history with score_before, score_after, and
score_delta.

Commit Transaction: If all steps succeed, the transaction is committed,
guaranteeing atomic persistence.

Update Redis: ZADD leaderboard {new_score} {user_id} is executed.

Acknowledge Message: Only after both the DB transaction and the Redis
update are successful, the RabbitMQ message is acknowledged, removing it
from the queue.

On Failure:

If the transaction fails, it's rolled back. The message is rejected and
goes back to the queue for a retry with exponential backoff.

After maximum retries, the message is dead-lettered to a
match_report.failed queue for manual operator inspection.

If the Redis update fails after a successful commit, the worker retries
the Redis command a few times. If it ultimately fails, the message is
acknowledged to prevent an infinite loop, and the job's ID is logged to
a "reconciliation needed" list.

7.  Redis Strategy Data Structure: A single Redis Sorted Set with the
    key leaderboard.

Member: user_id

Score: The player's current total score from the users table.

Read Path: Leaderboard reads (GET /leaderboard) are served primarily
from this Sorted Set using ZREVRANGE or ZREVRANGEBYSCORE for O(log(N) +
M) time complexity.

Source of Truth: PostgreSQL remains the authoritative source. Redis is a
disposable, performance-optimized materialized view.

Fallback: The API server has a circuit breaker on Redis. If Redis is
unavailable or a query fails, it transparently falls back to querying
SELECT u.id, u.name, u.score FROM users u ORDER BY u.score DESC LIMIT
\$1 OFFSET \$2 directly from PostgreSQL.

Reconciliation: A scheduled job runs every few minutes, queries the top
10,000 users from PostgreSQL, and executes a ZADD leaderboard ...
pipeline to rebuild the Sorted Set, repairing any missed updates. The
Sorted Set is atomically swapped with a new temporary key or simply
cleared and repopulated.

8.  Idempotency Idempotency is a two-layer defense:

Database Layer: A UNIQUE constraint on match_reports.request_id provides
the strongest guarantee. Any attempt to insert a duplicate key will fail
at the database level, which is atomic.

Payload Validation: To handle the REQUEST_ID_CONFLICT scenario, the
service stores a SHA-256 hash of the original request payload (or
compares the relevant fields). On a duplicate request_id insert, the
system checks if the new payload's hash matches the existing one. If
yes, it's a safe replay. If no, it's a conflict, and the error is
returned before any attempt to enqueue the job.

9.  Reliability and Failure Handling Atomic Transactions: The
    match_reports insert, users.score update, and trophy_history insert
    are wrapped in a single PostgreSQL transaction with row-level
    locking (SELECT ... FOR UPDATE). This prevents race conditions for
    concurrent updates to the same user.

Worker Retries & Dead-Letter Queue: The RabbitMQ consumer is configured
for manual acknowledgments. If processing fails, the message is rejected
without acknowledgment, and RabbitMQ redelivers it. Exponential backoff
is configured on the queue. After a configured maximum number of retries
(e.g., 5), the message is automatically moved to a Dead-Letter Queue
(DLQ) to prevent an infinite loop and to be dealt with by a human
operator.

Redis Repair: A failed Redis update does not roll back the committed
database transaction. Instead, an asynchronous reconciliation process
runs on a schedule to scan for and repair any inconsistencies between
the database and the cache.

10. Rate Limiting Algorithm: Fixed-window counter implemented with
    Redis.

Granularity: Per user_id combined with the client's IP address.

Key: ratelimit:match_report:{user_id}:{md5_of_ip}

Logic: For each request, the API executes an atomic LUA script that:

Gets the current count for the key. If the count is over the limit
(e.g., 5), return 0 (reject). If under the limit, increment it, set a
TTL (e.g., 10 seconds), and return 1 (accept). Performance: This is an
O(1) operation and is performed before any database interaction to
protect all downstream resources.

11. Testing The following test cases should be implemented
    (Unit/Integration/Feature):

Success: A valid request is submitted. Assert a 202 Accepted response
and that the worker eventually updates the score correctly.

Idempotency - Same Payload: A request is sent twice. Assert that the
second call returns 200 OK with "duplicate": true and that the score is
only incremented once.

Idempotency - Different Payload: A request with the same request_id but
a different score_delta is sent. Assert a 409 Conflict error.

Rate Limiting: Send 6 rapid requests from the same user_id and IP.
Assert the 6th request returns 429 Too Many Requests.

Leaderboard (Redis): Submit scores for multiple users. Call GET
/leaderboard and assert the correct order and "source": "redis".

Leaderboard (SQL Fallback): Simulate a Redis outage. Call GET
/leaderboard and assert the correct order and "source": "sql".

Queue Retry: Simulate a temporary database failure in the worker. Assert
the message is re-queued and successfully processed after the database
recovers.

Dead-Letter Queue: Simulate a permanent, unrecoverable failure (e.g., a
schema mismatch). Assert the message is routed to the Dead-Letter Queue
after maximum retries.

Concurrency: Run two workers concurrently with two different matches for
the same user. Assert both are processed correctly and the final score
is the sum of their deltas, thanks to row-level locking.

12. Answers to Key Design Questions Q1: If two concurrent requests are
    submitted for the same user, what happens? Two workers will pick up
    the jobs. Both will begin a database transaction. The first worker
    to execute the SELECT score FROM users WHERE id = X FOR UPDATE
    statement acquires an exclusive row lock. The second worker's
    identical query will block and wait. The first worker completes its
    updates and commits, releasing the lock. The second worker will then
    read the newly-updated score and apply its own delta on top. This
    guarantees correctness and prevents lost updates, all managed by
    PostgreSQL's ACID properties.

Q2: If the Redis service goes down mid-operation, what happens? For the
write path, the worker's ZADD command will fail after the DB transaction
has committed. The worker will retry the Redis operation. If Redis
remains down, the message is acknowledged to prevent an infinite loop,
and a log event is fired. A background reconciliation job will later see
the score in PostgreSQL that is not in Redis and will perform the
update, healing the system automatically. For the read path, the API
will detect the Redis failure and immediately fall back to querying the
leaderboard directly from the PostgreSQL database, serving the request
with "source": "sql".

Q3: If the leaderboard becomes significantly larger, what would you
change? For a global leaderboard with tens of millions of players, Redis
Sorted Sets can begin to use significant memory and have slower
O(log(N)) writes. The design would evolve to a sharded Redis cluster.
The leaderboard key could be sharded by a player's score range (e.g.,
leaderboard:bronze, leaderboard:silver, leaderboard:gold). The API would
query the correct shard based on rank. For the absolute top K, a single
leaderboard:top1000 Sorted Set could be maintained. This composite
approach keeps latency low and the working dataset for a single shard
small.

Q4: If we need a weekly and monthly leaderboard as well, how can this be
developed? This can be achieved by creating separate, time-bucketed
Sorted Sets. For example: leaderboard:weekly:2024-W01,
leaderboard:monthly:2024-01. The worker, upon processing a score event,
would atomically update the player's score in both the leaderboard
(all-time) and the current leaderboard:weekly:... set. A scheduled job
would clean up old keys. The GET /leaderboard endpoint can be extended
with a period query parameter (all_time, weekly, monthly), with the
service reading from the corresponding Sorted Set key.

Q5: If we want to add an anti-cheat rule, what is the best place for it?
The most appropriate place is a dedicated step in the Background Worker,
specifically before the core scoring transaction. When a worker consumes
a message, it would first call a CheatDetectionService with the match
details and the user's recent history. This service could perform checks
like:

Is the score_delta suspiciously high?

Is this player winning at an unrealistic rate from the same opponent?

Is the reported_at timestamp logical? If a rule is violated, the worker
rejects the message, publishes a cheat_suspected event to a different
queue for analysis, and does not proceed with the score update,
effectively preventing the cheating score from impacting the
leaderboard.
