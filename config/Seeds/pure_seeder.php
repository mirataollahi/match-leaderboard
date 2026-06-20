<?php declare(strict_types=1);

/**
 * Game Data Seeder - Standalone PDO Version (PostgreSQL)
 */

// Database configuration
$dbConfig = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '54324',
    'dbname' => getenv('DB_DATABASE') ?: 'match-score',
    'username' => getenv('DB_USERNAME') ?: 'match-score',
    'password' => getenv('DB_PASSWORD') ?: '3SI94b2qgt8Rg7prq4wP2oxn',
];

class GameDataSeeder
{
    private const RESULTS = ['win', 'loss', 'draw'];

    private const SCORE_MAP = [
        'win' => 25,
        'draw' => 5,
        'loss' => -15,
    ];

    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
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
    }

    public function run(): void
    {
        try {
            $this->pdo->beginTransaction();

            // Guard: skip if users already seeded
            $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM users');
            $count = $stmt->fetch();

            if ((int)($count['c'] ?? 0) > 0) {
                echo "Seeder skipped — data already present.\n";
                $this->pdo->rollBack();
                return;
            }

            $now = date('Y-m-d H:i:s');

            // ── 1. Users ──────────────────────────────────────────
            $players = [
                ['Alice', 200],
                ['Bob', 185],
                ['Charlie', 175],
                ['Diana', 160],
                ['Eve', 155],
                ['Frank', 140],
                ['Grace', 135],
                ['Hank', 120],
                ['Iris', 115],
                ['Jack', 100],
                ['Karen', 95],
                ['Leo', 90],
                ['Mia', 80],
                ['Noah', 75],
                ['Olivia', 70],
                ['Peter', 60],
                ['Quinn', 55],
                ['Rachel', 45],
                ['Sam', 35],
                ['Tina', 20],
            ];

            $userIds = [];
            $userStmt = $this->pdo->prepare(
                'INSERT INTO users (name, score, created_at, updated_at) VALUES (:name, :score, :created_at, :updated_at)'
            );

            foreach ($players as [$name, $score]) {
                $userStmt->execute([
                    'name' => $name,
                    'score' => $score,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $userIds[$name] = (int)$this->pdo->lastInsertId('users_id_seq');
            }

            echo sprintf("Inserted %d users.\n", count($userIds));

            // ── 2. Match reports + trophy_history ─────────────────
            $matchReportStmt = $this->pdo->prepare(
                'INSERT INTO match_reports (request_id, user_id, match_id, result, score_delta, reported_at, created_at)
                 VALUES (:request_id, :user_id, :match_id, :result, :score_delta, :reported_at, :created_at)'
            );

            $trophyHistoryStmt = $this->pdo->prepare(
                'INSERT INTO trophy_history (user_id, match_id, score_before, score_after, score_delta, reason, created_at)
                 VALUES (:user_id, :match_id, :score_before, :score_after, :score_delta, :reason, :created_at)'
            );

            $matchCount = 0;
            $trophyCount = 0;
            $matchCounter = 1000; // starting match_id

            foreach ($userIds as $name => $userId) {
                // Each player has 3 matches: one of each outcome for variety
                $results = $this->shuffledResults();

                // Get starting score for this player
                $currentScore = 0;
                foreach ($players as [$pName, $pScore]) {
                    if ($pName === $name) {
                        $currentScore = $pScore;
                        break;
                    }
                }

                // We're seeding history, so work backwards from the current score
                // by replaying 3 matches chronologically
                $matchScores = array_map(fn($r) => self::SCORE_MAP[$r], $results);
                $totalDelta = array_sum($matchScores);

                // Reconstructed starting score before these matches
                $seedStartScore = $currentScore - $totalDelta;

                $scoreTracker = $seedStartScore;

                foreach ($results as $i => $result) {
                    $matchId = (string)($matchCounter++);
                    $requestId = sprintf('seed-%s-%d', strtolower($name), $i + 1);
                    $delta = self::SCORE_MAP[$result];
                    $scoreBefore = $scoreTracker;
                    $scoreAfter = $scoreTracker + $delta;
                    $reportedTs = date('Y-m-d H:i:s', strtotime("-{$i} hours"));

                    // Insert match report
                    $matchReportStmt->execute([
                        'request_id' => $requestId,
                        'user_id' => $userId,
                        'match_id' => $matchId,
                        'result' => $result,
                        'score_delta' => $delta,
                        'reported_at' => $reportedTs,
                        'created_at' => $now,
                    ]);
                    $matchCount++;

                    // Insert trophy history
                    $reason = 'match_' . $result;
                    $trophyHistoryStmt->execute([
                        'user_id' => $userId,
                        'match_id' => $matchId,
                        'score_before' => $scoreBefore,
                        'score_after' => $scoreAfter,
                        'score_delta' => $delta,
                        'reason' => $reason,
                        'created_at' => $now,
                    ]);
                    $trophyCount++;

                    $scoreTracker = $scoreAfter;
                }
            }

            $this->pdo->commit();

            echo sprintf("Inserted %d match reports and %d trophy history records.\n", $matchCount, $trophyCount);
            echo "Seeder complete. Run your leaderboard seed command if applicable.\n";

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            echo "Seeder failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Return a shuffled array with one win, one loss, one draw.
     *
     * @return list<string>
     */
    private function shuffledResults(): array
    {
        $results = ['win', 'loss', 'draw'];
        shuffle($results);
        return $results;
    }
}

// ── Execute the seeder ────────────────────────────────────────
try {
    $seeder = new GameDataSeeder($dbConfig);
    $seeder->run();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
