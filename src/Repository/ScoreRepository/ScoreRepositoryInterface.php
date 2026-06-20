<?php declare(strict_types=1);

namespace App\Repository\ScoreRepository;

use App\Exception\DuplicateRequestException;
use App\Model\Entity\ScoreLog;
use RuntimeException;

/**
 * Contract for score persistence operations.
 */
interface ScoreRepositoryInterface
{
    /**
     * Returns true when a score log with the given request_id already exists.
     */
    public function isDuplicateRequest(string $requestId): bool;

    /**
     * Atomically appends a score log entry and updates the user's aggregate score.
     *
     * Both writes are wrapped in a single database transaction.
     * The unique index on score_logs.request_id ensures idempotency.
     *
     * @throws DuplicateRequestException When request_id is already stored
     * @throws RuntimeException On unexpected DB failure
     */
    public function applyScoreDelta(string $userId, string $requestId, int $delta): ScoreLog;

    /**
     * Returns the current aggregate score for a user directly from PostgreSQL.
     */
    public function getScore(string $userId): int;

    /**
     * Returns the top-N users ordered by descending score from PostgreSQL.
     *
     * @return array<int, array{rank: int, user_id: string, username: string, score: int}>
     */
    public function getTopUsers(int $limit): array;

    /**
     * Returns the 1-based rank of the given user by score (PostgreSQL fallback).
     */
    public function getUserRank(string $userId): int;
}
