<?php declare(strict_types=1);

use Cake\ORM\TableRegistry;

/**
 * Seed match report table in database
 */
class MatchReportSeeder extends AbstractSeed
{
    private const SCORE_MAP = [
        'win' => 25,
        'draw' => 5,
        'loss' => -15,
    ];

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        // Get table instances
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $matchReportsTable = TableRegistry::getTableLocator()->get('MatchReports');
        $trophyHistoryTable = TableRegistry::getTableLocator()->get('TrophyHistory');

        // Guard: skip if users already seeded
        if ($usersTable->find()->count() > 0) {
            echo "Seeder skipped — data already present.\n";
            return;
        }

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

        // Create user entities and save them
        $userEntities = [];
        foreach ($players as [$name, $score]) {
            $user = $usersTable->newEntity([
                'name' => $name,
                'score' => $score,
            ]);

            $usersTable->saveOrFail($user);
            $userEntities[$name] = $user;
        }

        echo sprintf("Inserted %d users.\n", count($userEntities));

        // ── 2. Match reports + trophy_history ─────────────────
        $matchCount = 0;
        $trophyCount = 0;

        foreach ($userEntities as $name => $user) {
            // Each player has 3 matches: one of each outcome for variety
            $results = $this->shuffledResults();

            // Starting score for this player (before the 3 matches)
            $currentScore = $user->score;
            $matchScores = array_map(fn($r) => self::SCORE_MAP[$r], $results);
            $totalDelta = array_sum($matchScores);

            // Calculate what the score was before these 3 matches
            $scoreTracker = $currentScore - $totalDelta;

            foreach ($results as $i => $result) {
                $delta = self::SCORE_MAP[$result];
                $scoreBefore = $scoreTracker;
                $scoreAfter = $scoreTracker + $delta;

                // Generate unique identifiers
                $matchId = sprintf('%d-%s-%d', $user->id, strtolower($name), $i + 1);
                $requestId = sprintf('seed-%s-%d', strtolower($name), $i + 1);
                $reportedAt = new \DateTime("-{$i} hours");

                // Create match report
                $matchReport = $matchReportsTable->newEntity([
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'match_id' => $matchId,
                    'result' => $result,
                    'score_delta' => $delta,
                    'reported_at' => $reportedAt,
                ]);

                $matchReportsTable->saveOrFail($matchReport);
                $matchCount++;

                // Create trophy history
                $reason = 'match_' . $result;
                $trophyHistory = $trophyHistoryTable->newEntity([
                    'user_id' => $user->id,
                    'match_id' => $matchId,
                    'score_before' => $scoreBefore,
                    'score_after' => $scoreAfter,
                    'score_delta' => $delta,
                    'reason' => $reason,
                ]);

                $trophyHistoryTable->saveOrFail($trophyHistory);
                $trophyCount++;

                $scoreTracker = $scoreAfter;
            }
        }

        echo sprintf("Inserted %d match reports and %d trophy history records.\n", $matchCount, $trophyCount);
        echo "Seeder complete.\n";
    }

    /**
     * Return a shuffled array with one win, one loss, one draw.
     *
     * @return array<string>
     */
    private function shuffledResults(): array
    {
        $results = ['win', 'loss', 'draw'];
        shuffle($results);
        return $results;
    }
}
