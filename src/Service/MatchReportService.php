<?php declare(strict_types=1);

namespace App\Service;

use App\Exception\RequestIdConflictException;
use App\Exception\UserNotFoundException;
use App\Model\Entity\MatchReport;
use App\Repository\MatchReportRepository\MatchReportRepositoryInterface;
use App\Repository\TrophyHistoryRepository\TrophyHistoryRepositoryInterface;
use App\Repository\UserRepository\UserRepositoryInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use RuntimeException;


/**
 * MatchReportService
 */
class MatchReportService
{
    /**
     * @param UserRepositoryInterface $userRepo User persistence.
     * @param MatchReportRepositoryInterface $reportRepo Match-report persistence.
     * @param TrophyHistoryRepositoryInterface $trophyRepo Trophy-history persistence.
     * @param RedisService $redis Redis access layer.
     */
    public function __construct(
        private readonly UserRepositoryInterface          $userRepo,
        private readonly MatchReportRepositoryInterface   $reportRepo,
        private readonly TrophyHistoryRepositoryInterface $trophyRepo,
        private readonly RedisService                     $redis,
    )
    {
    }

    /**
     * Process a validated match-report request and return the response payload.
     *
     * @param array<string, mixed> $data Validated request data.
     * @return array<string, mixed>  Response payload ready for JSON encoding.
     *
     * @throws RequestIdConflictException  When request_id is reused with a different payload.
     * @throws UserNotFoundException       When user_id does not exist in the database.
     * @throws RuntimeException           On unexpected database or persistence failures.
     */
    public function process(array $data): array
    {
        $requestId = (string)$data['request_id'];

        // Idempotency check
        $cached = $this->redis->getIdempotencyResult($requestId);
        if ($cached !== null) {
            return $this->resolveFromCache($cached, $data);
        }

        // Idempotency check (DB fallback)
        $existing = $this->reportRepo->findByRequestId($requestId);
        if ($existing !== null) {
            return $this->resolveFromDatabase($existing, $data);
        }

        // Step 3: Validate user exists
        $user = $this->userRepo->findById((int)$data['user_id']);
        if ($user === null) {
            throw new UserNotFoundException((int)$data['user_id']);
        }

        // Step 4: Atomic transaction
        $newScore = 0;

        // todo : Check how correct way transaction
        // todo : Is this a real transaction
        ConnectionManager::get('default')->transactional(
            function () use ($data, $user, &$newScore): void {
                // a) INSERT match_reports
                $this->reportRepo->create($data);

                // b) UPDATE users.score with relative delta (safe under concurrency)
                $newScore = $this->userRepo->incrementScore(
                    (int)$data['user_id'],
                    (int)$data['score_delta'],
                );

                // c) INSERT trophy_history audit record
                $this->trophyRepo->record(
                    userId: (int)$data['user_id'],
                    matchId: (string)$data['match_id'],
                    scoreBefore: (int)$user->score,
                    scoreAfter: $newScore,
                    scoreDelta: (int)$data['score_delta'],
                    reason: 'match_' . $data['result'],
                );
            },
        );

        // Step 5: Update Redis leaderboard (non-fatal)
        $this->redis->upsertLeaderboardScore(
            (int)$data['user_id'],
            (string)$user->name,
            $newScore,
        );

        // Step 6: Cache idempotency result in Redis
        $response = [
            'success' => true,
            'duplicate' => false,
            'user_id' => (int)$data['user_id'],
            'match_id' => (string)$data['match_id'],
            'new_score' => $newScore,
        ];

        $this->redis->storeIdempotencyResult(
            $requestId,
            array_merge($response, ['__hash' => $this->payloadHash($data)]),
        );

        $matchReportInfo = json_encode([
            'scope' => 'match',
            'request_id' => $requestId,
            'user_id' => $data['user_id'],
            'match_id' => $data['match_id'],
            'result' => $data['result'],
            'new_score' => $newScore,
        ]);
        HyperLogger::info('[MatchReportService] Match recorded.' . $matchReportInfo);
        return $response;
    }

    /**
     * Resolve an idempotent response from a Redis-cached result.
     *
     * @param array<string, mixed> $cached Payload previously stored in Redis.
     * @param array<string, mixed> $data Incoming normalised request data.
     *
     * @return array<string, mixed>  Response payload with duplicate=true.
     * @throws RequestIdConflictException
     */
    private function resolveFromCache(array $cached, array $data): array
    {
        if (($cached['__hash'] ?? null) !== $this->payloadHash($data)) {
            throw new RequestIdConflictException();
        }

        Log::info('[MatchReportService] Duplicate request resolved from Redis cache.', [
            'scope' => 'match',
            'request_id' => $data['request_id'],
        ]);

        return [
            'success' => true,
            'duplicate' => true,
            'user_id' => (int)$cached['user_id'],
            'match_id' => (string)$cached['match_id'],
            'new_score' => (int)$cached['new_score'],
        ];
    }

    /**
     * Resolve an idempotent response from an existing database row.
     *
     * @param MatchReport $existing The DB row found by request_id.
     * @param array<string, mixed> $data Incoming normalised request data.
     * @return array<string, mixed>  Response payload with duplicate=true.
     *
     * @throws RequestIdConflictException
     */
    private function resolveFromDatabase(
        MatchReport $existing,
        array       $data,
    ): array
    {
        $payloadMatches =
            (int)$existing->user_id === (int)$data['user_id']
            && (string)$existing->match_id === (string)$data['match_id']
            && (string)$existing->result === (string)$data['result']
            && (int)$existing->score_delta === (int)$data['score_delta']
            && (int)$existing->reported_at->getTimestamp() === (int)$data['reported_at'];

        if (!$payloadMatches) {
            throw new RequestIdConflictException();
        }

        // Re-read the current score from trophy_history (the users.score may
        // have moved on due to subsequent matches)
        $trophyTable = TableRegistry::getTableLocator()->get('TrophyHistory');
        $trophy = $trophyTable->find()
            ->where(['user_id' => $existing->user_id, 'match_id' => $existing->match_id])
            ->first();

        $newScore = (int)$trophy?->score_after;

        Log::info('[MatchReportService] Duplicate request resolved from database.', [
            'scope' => 'match',
            'request_id' => $data['request_id'],
        ]);

        return [
            'success' => true,
            'duplicate' => true,
            'user_id' => (int)$existing->user_id,
            'match_id' => (string)$existing->match_id,
            'new_score' => $newScore,
        ];
    }

    /**
     * Compute a deterministic SHA-256 hash of the five business-significant
     * fields of a match-report request.
     *
     * This hash is stored alongside the cached response in Redis so that a
     * subsequent duplicate request can be checked in O(1) without a database
     * read.
     *
     * @param array<string, mixed> $data Normalised request data.
     *
     * @return string  64-character hex string.
     */
    private function payloadHash(array $data): string
    {
        return hash('sha256', implode('|', [
            $data['user_id'],
            $data['match_id'],
            $data['result'],
            $data['score_delta'],
            $data['reported_at'],
        ]));
    }
}
