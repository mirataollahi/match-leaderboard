<?php declare(strict_types=1);

use Migrations\AbstractSeed;


/**
 * GameDataSeeder
 *
 * Seeds realistic-looking game data:
 *  - 20 users with varied starting scores
 *  - ~3 match reports per user (win / loss / draw mix)
 *  - Corresponding trophy_history rows
 *  - Idempotent: skips if data already present
 *
 * Run: bin/cake migrations seed --seed GameDataSeeder
 */
class GameDataSeeder extends AbstractSeed
{
    private const RESULTS = ['win', 'loss', 'draw'];

    private const SCORE_MAP = [
        'win' => 25,
        'draw' => 5,
        'loss' => -15,
    ];

    public function run(): void
    {
        $db = $this->getAdapter()->getConnection();

        // Guard: skip if users already seeded
        $count = $db->query('SELECT COUNT(*) AS c FROM users')->fetch(\PDO::FETCH_ASSOC);
        if ((int)($count['c'] ?? 0) > 0) {
            echo "Seeder skipped — data already present.\n";
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
        foreach ($players as [$name, $score]) {
            $db->exec(sprintf(
                "INSERT INTO users (name, score, created_at, updated_at) VALUES ('%s', %d, '%s', '%s')",
                $name, $score, $now, $now
            ));
            $userIds[$name] = (int)$db->lastInsertId();
        }

        echo sprintf("Inserted %d users.\n", count($userIds));

        // ── 2. Match reports + trophy_history ─────────────────
        $matchCount = 0;
        $trophyCount = 0;
        $matchCounter = 1000; // starting match_id

        foreach ($userIds as $name => $userId) {
            // Each player has 3 matches: one of each outcome for variety
            $results = $this->shuffledResults();

            // Starting score for this player
            $currentScore = $players[array_search([$name, null], array_map(
                fn($p) => [$p[0], null], $players
            )) ?: 0][1] ?? 0;

            // Re-derive from $players array
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

                // match_reports
                $db->exec(sprintf(
                    "INSERT INTO match_reports
                        (request_id, user_id, match_id, result, score_delta, reported_at, created_at)
                     VALUES ('%s', %d, '%s', '%s', %d, '%s', '%s')",
                    $requestId, $userId, $matchId, $result, $delta, $reportedTs, $now
                ));
                $matchCount++;

                // trophy_history
                $reason = 'match_' . $result;
                $db->exec(sprintf(
                    "INSERT INTO trophy_history
                        (user_id, match_id, score_before, score_after, score_delta, reason, created_at)
                     VALUES (%d, '%s', %d, %d, %d, '%s', '%s')",
                    $userId, $matchId, $scoreBefore, $scoreAfter, $delta, $reason, $now
                ));
                $trophyCount++;

                $scoreTracker = $scoreAfter;
            }
        }

        echo sprintf("Inserted %d match reports and %d trophy history records.\n", $matchCount, $trophyCount);
        echo "Seeder complete. Run `bin/cake leaderboard:seed` to warm Redis.\n";
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
