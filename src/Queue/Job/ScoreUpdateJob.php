<?php

declare(strict_types=1);

namespace App\Queue\Job;

use App\Model\Table\FailedJobsTable;
use App\Repository\LeaderboardRepository\LeaderboardRepository;
use App\Repository\ScoreRepository\ScoreRepository;
use App\Service\RedisLeaderboardService;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumes score_update messages from RabbitMQ.
 *
 * Responsibilities:
 *   1. Sync the user's leaderboard snapshot rows in PostgreSQL
 *   2. Ensure Redis sorted sets are up-to-date (re-sync on miss)
 *   3. On failure: persist to failed_jobs for audit and retry
 *
 * This job is idempotent — re-processing the same payload is safe because
 * `leaderboard_repository::upsertSnapshot` uses INSERT … ON CONFLICT DO UPDATE.
 */
class ScoreUpdateJob
{
    use LocatorAwareTrait;

    /** @var LeaderboardRepository PostgreSQL leaderboard snapshot repository */
    private LeaderboardRepository $leaderboardRepo;

    /** @var ScoreRepository PostgreSQL score repository */
    private ScoreRepository $scoreRepo;

    /** @var RedisLeaderboardService Redis sorted-set service */
    private RedisLeaderboardService $redisLeaderboard;

    /** @var FailedJobsTable ORM table for failed job persistence */
    private FailedJobsTable $failedJobs;

    /** @var int Maximum retries before sending to dead-letter storage */
    private int $maxRetries = 3;

    public function __construct()
    {
        $this->leaderboardRepo  = new LeaderboardRepository();
        $this->scoreRepo        = new ScoreRepository();
        $this->redisLeaderboard = new RedisLeaderboardService();

        /** @var FailedJobsTable $failedJobs */
        $failedJobs = $this->fetchTable('FailedJobs');
        $this->failedJobs = $failedJobs;
    }

    /**
     * Processes a single AMQP message from the score_updates queue.
     *
     * Acknowledges the message on success.  On failure, nacks without
     * re-queue and persists to failed_jobs for audit/retry.
     */
    public function handle(AMQPMessage $message): void
    {
        $payload = [];

        try {
            $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);

            $this->process($payload);

            $message->ack();
        } catch (\Throwable $e) {
            Log::error("ScoreUpdateJob failed: {$e->getMessage()}", ['payload' => $payload]);

            // Persist failure for audit and manual/automated retry
            $this->recordFailure('score_updates', $payload, $e->getMessage());

            // Nack without re-queue — re-queuing risks infinite loops
            $message->nack(false);
        }
    }

    /**
     * Core processing logic: sync PostgreSQL leaderboard snapshots and Redis.
     *
     * @param array<string, mixed> $payload
     */
    private function process(array $payload): void
    {
        $userId   = (string)($payload['user_id']  ?? '');
        $newScore = (int)($payload['new_score']    ?? 0);

        if ($userId === '') {
            throw new \InvalidArgumentException('Missing user_id in payload');
        }

        $now = new \DateTime();

        // Upsert all four leaderboard snapshot types for the current periods
        $periods = [
            ['alltime', 'alltime'],
            ['daily',   $now->format('Y-m-d')],
            ['weekly',  $now->format('Y-\WW')],
            ['monthly', $now->format('Y-m')],
        ];

        foreach ($periods as [$type, $period]) {
            $this->leaderboardRepo->upsertSnapshot($userId, $newScore, $type, $period);
        }

        // Re-sync Redis if the score is missing (e.g. after a Redis restart)
        $redisScore = $this->redisLeaderboard->getScore($userId);
        if ($redisScore === null) {
            $this->redisLeaderboard->setScore($userId, $newScore);
            Log::info("ScoreUpdateJob: re-synced Redis score for user {$userId}");
        }
    }

    /**
     * Persists a failed job record to PostgreSQL for audit and retry.
     *
     * @param array<string, mixed> $payload
     */
    private function recordFailure(string $queue, array $payload, string $error): void
    {
        try {
            $entity = $this->failedJobs->newEntity([
                'queue'     => $queue,
                'payload'   => json_encode($payload),
                'error'     => $error,
                'attempts'  => 1,
                'failed_at' => new \DateTime(),
            ]);

            $this->failedJobs->save($entity);
        } catch (\Throwable $e) {
            // Last resort: log only, to avoid masking the original error
            Log::critical("ScoreUpdateJob: could not persist failed job: {$e->getMessage()}");
        }
    }
}
