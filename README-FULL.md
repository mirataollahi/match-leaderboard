# 📊 Game Leaderboard Service

## Overview

This project is a backend service for managing user scores and maintaining a high-performance leaderboard system.
The system is designed to handle high concurrency, ensure data consistency, and provide fast read/write operations under heavy load.

The implementation is based on CakePHP with PostgreSQL as the primary data store, and is designed with scalability,
fault tolerance, and clean architecture principles in mind.

---

## Core Requirements

* Users can gain score through API requests
* Each request must be idempotent (no duplicate processing)
* The system must support concurrent requests safely
* Leaderboard queries must be fast (top users, rank, etc.)
* The system should be resilient to partial failures (DB/Redis downtime)
* The design should be extensible (e.g., weekly/monthly leaderboards)

---

## Architecture Overview

The system follows a layered architecture:

* Controller Layer → Handles HTTP requests
* Service Layer → Contains business logic
* Data Access Layer (ORM) → Interacts with PostgreSQL
* (Optional) Cache Layer → Redis for fast leaderboard operations
* (Optional) Queue Layer → Async processing for durability

---

## Database Design (PostgreSQL)

### 1. users

Stores basic user information.

* id (PK)
* username (unique)
* created
* modified

---

### 2. user_scores

Stores the aggregated score per user.

* user_id (PK, FK → users id)
* score (bigint)
* updated_at

Purpose:

* Fast lookup of a user’s total score
* Avoids recalculating scores from logs

---

### 3. score_logs

Stores all score change events.

* id (PK)
* user_id (FK)
* request_id (unique)
* score_delta
* created_at

Purpose:

* Ensures idempotency using unique request_id
* Keeps history of all score changes
* Enables replay/debugging

---

### 4. leaderboards (optional)

Supports time-based leaderboards.

* id
* user_id
* score
* type (daily, weekly, monthly)
* period (e.g., 2026-W25)
* created_at

Purpose:

* Enables multiple leaderboard types
* Supports future scalability

---

## Idempotency Strategy

Each request includes a unique `request_id`.

* A unique index is placed on `score_logs.request_id`
* If a duplicate request is received:

    * The system ignores it or returns the previous result
* This guarantees **exactly-once processing**

---

## Concurrency Handling

* Database-level constraints (unique index on request_id)
* Transactions for:

    * inserting score_logs
    * updating user_scores
* Prevents race conditions in concurrent requests

---

## Leaderboard Strategy

### Option 1 (Simple)

* Use PostgreSQL:

  ```sql
  ORDER BY score DESC LIMIT N
  ```

### Option 2 (High Performance - Recommended)

* Use Redis Sorted Sets:

    * Key: leaderboard
    * Member: user_id
    * Score: user score

Benefits:

* O(log n) updates
* Fast rank queries

---

## Failure Handling (Fallback Strategy)

### If Redis is unavailable:

* System falls back to PostgreSQL updates

### If PostgreSQL is unavailable:

* Write to Redis (temporary)
* Queue the update for retry later

### If service crashes mid-operation:

* Idempotency ensures safe retry

---

## Rate Limiting (Suggested)

* Implement using Redis:

    * Key: rate_limit:user_id
    * Use token bucket or sliding window

---

## Scalability Considerations

* Separate read/write paths
* Use Redis for hot data (leaderboard)
* Shard leaderboard by region if needed
* Partition large tables (score_logs)

---

## Extensibility

The system is designed to support:

* Weekly/monthly leaderboards
* Anti-cheat rules (via service layer or middleware)
* Event-driven architecture (via queues)
* Microservice migration in the future

---

## Summary

This system balances:

* **Consistency** (PostgreSQL as source of truth)
* **Performance** (Redis for leaderboard)
* **Reliability** (idempotency + fallback strategies)

The architecture is clean, scalable, and production-ready in terms of design principles.
