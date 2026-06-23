<?php

declare(strict_types=1);

use Cake\ORM\TableRegistry;
use Migrations\BaseSeed;

/**
 * Seeds the match report and related tables with sample data.
 */
class MatchReportSeeder extends BaseSeed
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
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $matchReportsTable = TableRegistry::getTableLocator()->get('MatchReports');
        $trophyHistoryTable = TableRegistry::getTableLocator()->get('TrophyHistory');

        if ($usersTable->find()->count() > 0) {
            echo "Seeder skipped — data already present.\n";
            return;
        }

        // 1. Seed users
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

        // 2. Seed match reports and trophy history
        $matchCount = 0;
        $trophyCount = 0;

        foreach ($userEntities as $name => $user) {
            $results = $this->shuffledResults();
            $matchScores = array_map(fn(string $result): int => self::SCORE_MAP[$result], $results);
            $totalDelta = array_sum($matchScores);

            // Calculate the score before these matches
            $scoreTracker = $user->score - $totalDelta;

            foreach ($results as $i => $result) {
                $delta = self::SCORE_MAP[$result];
                $scoreBefore = $scoreTracker;
                $scoreAfter = $scoreTracker + $delta;

                $matchId = sprintf('%d-%s-%d', $user->id, strtolower($name), $i + 1);
                $requestId = sprintf('seed-%s-%d', strtolower($name), $i + 1);
                $reportedAt = new DateTime("-{$i} hours");

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

                $trophyHistory = $trophyHistoryTable->newEntity([
                    'user_id' => $user->id,
                    'match_id' => $matchId,
                    'score_before' => $scoreBefore,
                    'score_after' => $scoreAfter,
                    'score_delta' => $delta,
                    'reason' => 'match_' . $result,
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
     * Returns a shuffled array containing one win, one loss, and one draw.
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
