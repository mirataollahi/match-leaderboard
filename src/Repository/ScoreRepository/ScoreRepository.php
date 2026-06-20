<?php declare(strict_types=1);

namespace App\Repository\ScoreRepository;

use App\Exception\DuplicateRequestException;
use App\Model\Entity\ScoreLog;
use App\Model\Table\ScoreLogsTable;
use App\Model\Table\UserScoresTable;
use Cake\Database\Exception\DatabaseException;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * Handles score persistence against PostgreSQL with transactional safety.
 */
class ScoreRepository implements ScoreRepositoryInterface
{
    use LocatorAwareTrait;

    /** @var ScoreLogsTable ORM table for event log */
    private ScoreLogsTable $scoreLogs;

    /** @var UserScoresTable ORM table for aggregate scores */
    private UserScoresTable $userScores;

    public function __construct()
    {
        /** @var ScoreLogsTable $scoreLogs */
        $scoreLogs = $this->fetchTable('ScoreLogs');
        $this->scoreLogs = $scoreLogs;

        /** @var UserScoresTable $userScores */
        $userScores = $this->fetchTable('UserScores');
        $this->userScores = $userScores;
    }

    /**
     * {@inheritdoc}
     */
    public function isDuplicateRequest(string $requestId): bool
    {
        return $this->scoreLogs->exists(['request_id' => $requestId]);
    }

    /**
     * {@inheritdoc}
     */
    public function applyScoreDelta(string $userId, string $requestId, int $delta): ScoreLog
    {
        $connection = $this->scoreLogs->getConnection();

        return $connection->transactional(function () use ($userId, $requestId, $delta,$connection): ScoreLog {
            // 1. Insert the immutable event log entry
            $log = $this->scoreLogs->newEntity([
                'user_id' => $userId,
                'request_id' => $requestId,
                'score_delta' => $delta,
                'created_at' => new \DateTime(),
            ]);

            try {
                if (!$this->scoreLogs->save($log)) {
                    throw new RuntimeException("Failed to save score log for request: {$requestId}");
                }
            } catch (DatabaseException $e) {
                // Unique constraint violation = duplicate request_id
                if ($this->isUniqueConstraintViolation($e)) {
                    throw new DuplicateRequestException("Duplicate request_id: {$requestId}");
                }
                throw $e;
            }

            // 2. Upsert the aggregate score atomically
            $connection->execute(
                'INSERT INTO user_scores (user_id, score, updated_at)
                 VALUES (:user_id, :delta, NOW())
                 ON CONFLICT (user_id)
                 DO UPDATE SET score = user_scores.score + :delta, updated_at = NOW()',
                [':user_id' => $userId, ':delta' => $delta]
            );

            return $log;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getScore(string $userId): int
    {
        $row = $this->userScores->find()
            ->select(['score'])
            ->where(['user_id' => $userId])
            ->first();

        return $row?->score ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getTopUsers(int $limit): array
    {
        $rows = $this->userScores->find()
            ->select([
                'user_id' => 'UserScores.user_id',
                'score' => 'UserScores.score',
                'username' => 'Users.username',
            ])
            ->join([
                'Users' => [
                    'table' => 'users',
                    'type' => 'INNER',
                    'conditions' => 'Users.id = UserScores.user_id',
                ],
            ])
            ->orderByDesc('UserScores.score')
            ->limit($limit)
            ->toArray();

        return array_map(
            static fn(int $rank, mixed $row): array => [
                'rank' => $rank + 1,
                'user_id' => $row->user_id,
                'username' => $row->username,
                'score' => (int)$row->score,
            ],
            array_keys($rows),
            $rows,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getUserRank(string $userId): int
    {
        $result = $this->userScores->getConnection()->execute(
            'SELECT COUNT(*) + 1 AS rank FROM user_scores WHERE score > (
                SELECT score FROM user_scores WHERE user_id = :user_id
            )',
            [':user_id' => $userId]
        )->fetchAll('assoc');

        return (int)($result[0]['rank'] ?? 0);
    }

    /**
     * Detects a unique-constraint violation from a DatabaseException message.
     */
    private function isUniqueConstraintViolation(DatabaseException $e): bool
    {
        return str_contains($e->getMessage(), 'score_logs_request_id_unique')
            || str_contains($e->getMessage(), 'unique constraint')
            || str_contains($e->getMessage(), '23505'); // PostgreSQL SQLSTATE
    }
}
