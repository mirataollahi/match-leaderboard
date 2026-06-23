<?php declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository\UserRepositoryInterface;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * LeaderboardService
 */
class LeaderboardService
{
    /**
     * @param UserRepositoryInterface $userRepository User persistence (for SQL fallback + seeding).
     * @param RedisService $redis Redis access layer.
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly RedisService            $redis,
    )
    {
    }

    /**
     * Return a paginated leaderboard page.
     *
     * @param int $limit Page size — clamped to [1, 100].
     * @param int $offset Zero-based starting position — must be ≥ 0.
     *
     */
    public function getLeaderboard(int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($this->redis->isAvailable()) {
            $data = $this->redis->getLeaderboard($limit, $offset);

            if (!empty($data)) {
                return ['data' => $data, 'source' => 'redis'];
            }

            // Redis is reachable but the sorted set is empty (cold start or
            // flush) — seed it from SQL then retry once.
            Log::info('[LeaderboardService] Redis sorted set is empty, seeding from SQL.', ['scope' => 'leaderboard']);
            //$this->seedRedisFromDatabase();

            $data = $this->redis->getLeaderboard($limit, $offset);

            if (!empty($data)) {
                return ['data' => $data, 'source' => 'redis'];
            }
        }

        Log::warning('[LeaderboardService] Redis unavailable or empty after seed — falling back to SQL.', ['scope' => 'leaderboard']);
        return ['data' => $this->getFromDatabase($limit, $offset), 'source' => 'sql'];
    }

    /**
     * Query the PostgreSQL leaderboard directly.
     *
     * @param int $limit Page size.
     * @param int $offset Zero-based offset.
     */
    private function getFromDatabase(int $limit, int $offset): array
    {
        $table = TableRegistry::getTableLocator()->get('Users');
        $rows = $table->find()
            ->select(['id', 'name', 'score'])
            ->orderByDesc('score')
            ->orderByAsc('id')    // tie-breaker: earlier registration wins
            ->limit($limit)
            ->offset($offset)
            ->all();

        $result = [];
        $rank = $offset + 1;
        foreach ($rows as $user) {
            $result[] = [
                'rank' => $rank++,
                'user_id' => (int)$user->id,
                'name' => (string)$user->name,
                'score' => (int)$user->score,
            ];
        }

        return $result;
    }

    /**
     * Load all users from the database and push them into the Redis sorted set.
     *
     * @return void
     */
    private function seedRedisFromDatabase(): void
    {
        $users = $this->userRepository->allForLeaderboard();
        $this->redis->seedLeaderboard($users);
    }
}
