<?php declare(strict_types=1);

/**
 * Game Data Seeder - PostgreSQL + Redis
 *
 * Usage: php seeder.php [--users=8000] [--matches-per-user=3] [--flush] [--help]
 */

// Database configuration
$dbConfig = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '54324',
    'dbname' => getenv('DB_DATABASE') ?: 'match-score',
    'username' => getenv('DB_USERNAME') ?: 'match-score',
    'password' => getenv('DB_PASSWORD') ?: '3SI94b2qgt8Rg7prq4wP2oxn',
];

// Redis configuration
$redisConfig = [
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: null,
    'database' => (int)(getenv('REDIS_DATABASE') ?: 0),
    'prefix' => getenv('REDIS_PREFIX') ?: 'lb:',
];

class GameDataSeeder
{
    private const RESULTS = ['win', 'draw', 'loss'];

    private const SCORE_MAP = [
        'win' => 25,
        'draw' => 5,
        'loss' => -15,
    ];

    private PDO $pdo;
    private ?Redis $redis;
    private string $redisPrefix;
    private bool $redisAvailable = false;

    // Seeder options
    private int $totalUsers;
    private int $matchesPerUser;
    private bool $flushData;

    public function __construct(array $dbConfig, array $redisConfig, array $options = [])
    {
        // Parse options
        $this->totalUsers = (int)($options['users'] ?? 8000);
        $this->matchesPerUser = (int)($options['matches_per_user'] ?? 3);
        $this->flushData = (bool)($options['flush'] ?? false);

        // Connect to PostgreSQL
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['dbname']
        );

        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Connect to Redis
        $this->redisPrefix = $redisConfig['prefix'];
        $this->connectRedis($redisConfig);
    }

    private function connectRedis(array $cfg): void
    {
        try {
            $this->redis = new Redis();

            $connected = $this->redis->connect(
                $cfg['host'],
                $cfg['port'],
                4.0
            );

            if (!$connected) {
                throw new RedisException('Failed to connect to Redis');
            }

            if (!empty($cfg['password'])) {
                $this->redis->auth($cfg['password']);
            }

            $this->redis->select($cfg['database']);
            $this->redis->ping();

            $this->redisAvailable = true;
            echo "✓ Redis connected successfully.\n";
        } catch (RedisException $e) {
            $this->redis = null;
            $this->redisAvailable = false;
            echo "⚠ Redis unavailable: " . $e->getMessage() . "\n";
            echo "  Will seed database only. Run redis:seed later to populate cache.\n";
        }
    }

    public function run(): void
    {
        $startTime = microtime(true);

        try {
            if ($this->flushData) {
                $this->flushExistingData();
            }

            // Check if data already exists
            $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM users');
            $count = (int)$stmt->fetch()['c'];

            if ($count > 0 && !$this->flushData) {
                echo "⚠ Database already contains {$count} users.\n";
                echo "  Use --flush to clear existing data first.\n";
                echo "  Or continue to add more users on top of existing data.\n";
                echo "  Proceeding with incremental seeding...\n\n";
            }

            $this->pdo->beginTransaction();

            echo "═══════════════════════════════════════════\n";
            echo "  Game Data Seeder\n";
            echo "═══════════════════════════════════════════\n";
            echo "  Target users:      {$this->totalUsers}\n";
            echo "  Matches per user:  {$this->matchesPerUser}\n";
            echo "  Redis available:   " . ($this->redisAvailable ? 'Yes' : 'No') . "\n";
            echo "═══════════════════════════════════════════\n\n";

            // Get starting user ID for incremental seeding
            $maxUserId = $this->getMaxUserId();
            $startUserId = $maxUserId + 1;
            $nextMatchId = $this->getMaxMatchId() + 1;

            echo "Starting from user_id: {$startUserId}\n";
            echo "Starting from match_id: {$nextMatchId}\n\n";

            // ── Generate Users ─────────────────────────────────
            echo "📝 Generating users...\n";
            $users = $this->generateUsers($startUserId);
            $userIds = $this->insertUsers($users);
            echo "✓ Inserted " . count($userIds) . " users.\n\n";

            // ── Generate Match History ─────────────────────────
            echo "⚽ Generating match history...\n";
            $matchCount = $this->insertMatchHistory($userIds, $users, $nextMatchId);
            echo "✓ Inserted {$matchCount} match reports and trophy history records.\n\n";

            // Update user scores to final values
            $this->updateFinalScores($userIds, $users);

            // ── Seed Redis Leaderboard ─────────────────────────
            if ($this->redisAvailable) {
                echo "🔴 Seeding Redis leaderboard...\n";
                $this->seedRedisLeaderboard($userIds);
                echo "✓ Redis leaderboard seeded.\n\n";
            }

            $this->pdo->commit();

            $elapsed = round(microtime(true) - $startTime, 2);

            echo "═══════════════════════════════════════════\n";
            echo "  ✅ Seeding Complete!\n";
            echo "═══════════════════════════════════════════\n";
            echo "  Users created:     " . count($userIds) . "\n";
            echo "  Total users:       " . ($maxUserId + count($userIds)) . "\n";
            echo "  Matches created:   {$matchCount}\n";
            echo "  Time elapsed:      {$elapsed}s\n";
            echo "═══════════════════════════════════════════\n";

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "❌ Seeder failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Generate random user data
     */
    private function generateUsers(int $startUserId): array
    {
        $users = [];

        // First name pool for realistic names
        $firstNames = [
            'Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Hank',
            'Iris', 'Jack', 'Karen', 'Leo', 'Mia', 'Noah', 'Olivia', 'Peter',
            'Quinn', 'Rachel', 'Sam', 'Tina', 'Uma', 'Victor', 'Wendy', 'Xander',
            'Yara', 'Zane', 'Amara', 'Blake', 'Clara', 'Derek', 'Elara', 'Felix',
            'Gemma', 'Hugo', 'Ivy', 'Jasper', 'Kira', 'Liam', 'Maya', 'Nico',
            'Opal', 'Pax', 'Rory', 'Sage', 'Theo', 'Vera', 'Wren', 'Zara',
            'Aiden', 'Bella', 'Cyrus', 'Daisy', 'Ethan', 'Fiona', 'Gavin', 'Hazel',
            'Isla', 'Jonah', 'Kai', 'Luna', 'Milo', 'Nora', 'Owen', 'Piper',
            'Quentin', 'Riley', 'Stella', 'Tyler', 'Violet', 'Wyatt', 'Zoe',
        ];

        // Last name pool
        $lastNames = [
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller',
            'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez',
            'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
            'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark',
            'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young', 'Allen', 'King',
            'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores', 'Green',
            'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell',
        ];

        for ($i = 0; $i < $this->totalUsers; $i++) {
            $userId = $startUserId + $i;
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $name = "{$firstName} {$lastName}";

            // Add unique suffix to avoid duplicates
            $name = "{$name}_{$userId}";

            // Generate random base score between 0 and 500
            $baseScore = rand(0, 500);

            $users[$userId] = [
                'id' => $userId,
                'name' => $name,
                'base_score' => $baseScore,
            ];
        }

        return $users;
    }

    /**
     * Insert users into database
     */
    private function insertUsers(array $users): array
    {
        $now = date('Y-m-d H:i:s');
        $userStmt = $this->pdo->prepare(
            'INSERT INTO users (id, name, score, created_at, updated_at)
             VALUES (:id, :name, :score, :created_at, :updated_at)'
        );

        $userIds = [];
        $batchSize = 500;
        $batch = [];

        foreach ($users as $userId => $userData) {
            $batch[] = [
                'id' => $userId,
                'name' => $userData['name'],
                'score' => $userData['base_score'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= $batchSize) {
                foreach ($batch as $row) {
                    $userStmt->execute($row);
                }
                $batch = [];

                $progress = count($userIds) + count($batch);
                echo "  Inserted {$progress}/{$this->totalUsers} users...\r";
            }
        }

        // Insert remaining
        foreach ($batch as $row) {
            $userStmt->execute($row);
        }

        return array_keys($users);
    }

    /**
     * Generate and insert match history for all users
     */
    private function insertMatchHistory(array $userIds, array $users, int $startMatchId): int
    {
        $now = date('Y-m-d H:i:s');
        $matchReportStmt = $this->pdo->prepare(
            'INSERT INTO match_reports (request_id, user_id, match_id, result, score_delta, reported_at, created_at)
             VALUES (:request_id, :user_id, :match_id, :result, :score_delta, :reported_at, :created_at)'
        );

        $trophyHistoryStmt = $this->pdo->prepare(
            'INSERT INTO trophy_history (user_id, match_id, score_before, score_after, score_delta, reason, created_at)
             VALUES (:user_id, :match_id, :score_before, :score_after, :score_delta, :reason, :created_at)'
        );

        $matchId = $startMatchId;
        $matchCount = 0;

        foreach ($userIds as $userId) {
            // Generate random match sequence
            $results = [];
            for ($i = 0; $i < $this->matchesPerUser; $i++) {
                $results[] = self::RESULTS[array_rand(self::RESULTS)];
            }

            // Calculate score progression
            $currentScore = $users[$userId]['base_score'];
            $scoreTracker = $currentScore;

            foreach ($results as $i => $result) {
                $matchIdStr = (string)($matchId++);
                $requestId = sprintf('seed-%d-%d', $userId, $i + 1);
                $delta = self::SCORE_MAP[$result];
                $scoreBefore = $scoreTracker;
                $scoreAfter = $scoreTracker + $delta;
                $reportedTs = date('Y-m-d H:i:s', strtotime("-{$i} hours"));

                // Insert match report
                $matchReportStmt->execute([
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'match_id' => $matchIdStr,
                    'result' => $result,
                    'score_delta' => $delta,
                    'reported_at' => $reportedTs,
                    'created_at' => $now,
                ]);

                // Insert trophy history
                $trophyHistoryStmt->execute([
                    'user_id' => $userId,
                    'match_id' => $matchIdStr,
                    'score_before' => $scoreBefore,
                    'score_after' => $scoreAfter,
                    'score_delta' => $delta,
                    'reason' => 'match_' . $result,
                    'created_at' => $now,
                ]);

                $scoreTracker = $scoreAfter;
                $matchCount++;
            }

            // Update user's final score
            $users[$userId]['final_score'] = $scoreTracker;

            if ($matchCount % 500 === 0) {
                echo "  Generated {$matchCount} matches...\r";
            }
        }

        return $matchCount;
    }

    /**
     * Update user scores to final values after matches
     */
    private function updateFinalScores(array $userIds, array $users): void
    {
        $updateStmt = $this->pdo->prepare(
            'UPDATE users SET score = :score, updated_at = :updated_at WHERE id = :id'
        );

        $now = date('Y-m-d H:i:s');

        foreach ($userIds as $userId) {
            $updateStmt->execute([
                'score' => $users[$userId]['final_score'] ?? $users[$userId]['base_score'],
                'updated_at' => $now,
                'id' => $userId,
            ]);
        }
    }

    /**
     * Seed Redis leaderboard with all users
     */
    private function seedRedisLeaderboard(array $userIds): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        // Fetch all users with final scores
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name, score FROM users WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($userIds));
        $users = $stmt->fetchAll();

        $scoreKey = $this->redisPrefix . 'scores';

        // Use pipeline for better performance
        $this->redis->multi(Redis::PIPELINE);

        foreach ($users as $user) {
            $this->redis->zAdd($scoreKey, (int)$user['score'], (string)$user['id']);
            $this->redis->hMSet(
                $this->redisPrefix . 'user:' . $user['id'],
                [
                    'id' => (string)$user['id'],
                    'name' => (string)$user['name'],
                    'score' => (string)$user['score'],
                ]
            );
        }

        $this->redis->exec();

        echo "  Redis: Seeded " . count($users) . " users into sorted set '{$scoreKey}'\n";
    }

    /**
     * Flush existing seeder data
     */
    private function flushExistingData(): void
    {
        echo "🗑  Flushing existing data...\n";

        $this->pdo->exec('DELETE FROM trophy_history');
        $this->pdo->exec('DELETE FROM match_reports');
        $this->pdo->exec('DELETE FROM users');

        // Reset sequences
        $this->pdo->exec("ALTER SEQUENCE users_id_seq RESTART WITH 1");
        $this->pdo->exec("ALTER SEQUENCE match_reports_id_seq RESTART WITH 1");
        $this->pdo->exec("ALTER SEQUENCE trophy_history_id_seq RESTART WITH 1");

        // Clear Redis leaderboard
        if ($this->redisAvailable) {
            $this->flushRedisData();
        }

        echo "✓ Existing data flushed.\n\n";
    }

    /**
     * Clear Redis leaderboard data
     */
    private function flushRedisData(): void
    {
        // Get all keys with prefix
        $keys = $this->redis->keys($this->redisPrefix . '*');

        if (!empty($keys)) {
            $this->redis->del($keys);
            echo "  Redis: Flushed " . count($keys) . " keys.\n";
        }
    }

    /**
     * Get the maximum user ID in the database
     */
    private function getMaxUserId(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM users');
        return (int)$stmt->fetch()['max_id'];
    }

    /**
     * Get the maximum match ID in the database
     */
    private function getMaxMatchId(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(CAST(match_id AS INTEGER)), 0) AS max_id FROM match_reports');
        return (int)$stmt->fetch()['max_id'];
    }

    /**
     * Print usage information
     */
    public static function printHelp(): void
    {
        echo "Game Data Seeder - PostgreSQL + Redis\n";
        echo "Usage: php seeder.php [options]\n\n";
        echo "Options:\n";
        echo "  --users=N              Number of users to generate (default: 8000)\n";
        echo "  --matches-per-user=N   Number of matches per user (default: 3)\n";
        echo "  --flush                Clear existing data before seeding\n";
        echo "  --help                 Show this help message\n\n";
        echo "Examples:\n";
        echo "  php seeder.php --users=8000 --matches-per-user=3\n";
        echo "  php seeder.php --users=10000 --flush\n";
        echo "  php seeder.php --users=1000 --matches-per-user=5\n";
    }
}

// ── Parse command line arguments ────────────────────────────
$options = [
    'users' => 8000,
    'matches_per_user' => 3,
    'flush' => false,
];

foreach ($argv as $arg) {
    if ($arg === '--help') {
        GameDataSeeder::printHelp();
        exit(0);
    }
    if ($arg === '--flush') {
        $options['flush'] = true;
    }
    if (str_starts_with($arg, '--users=')) {
        $options['users'] = (int)substr($arg, 8);
    }
    if (str_starts_with($arg, '--matches-per-user=')) {
        $options['matches_per_user'] = (int)substr($arg, 19);
    }
}

// Validate options
if ($options['users'] < 1 || $options['users'] > 100000) {
    echo "Error: Users must be between 1 and 100,000\n";
    exit(1);
}

if ($options['matches_per_user'] < 1 || $options['matches_per_user'] > 100) {
    echo "Error: Matches per user must be between 1 and 100\n";
    exit(1);
}

// ── Execute the seeder ────────────────────────────────────
try {
    $seeder = new GameDataSeeder($dbConfig, $redisConfig, $options);
    $seeder->run();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
