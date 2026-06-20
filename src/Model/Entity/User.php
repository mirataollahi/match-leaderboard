<?php declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use DateTime;

/**
 * The user column in database
 *
 * @property int $id
 * @property string $name
 * @property int $score
 * @property DateTime $created_at
 * @property DateTime $updated_at
 *
 * @property MatchReport[] $match_reports
 * @property TrophyHistory[] $trophy_history
 */
class User extends Entity
{
    protected array $_accessible = [
        '*' => false,
        'name' => true,
        'score' => true,
        'match_reports' => true,
        'trophy_history' => true,
        'created_at' => false,
        'updated_at' => false,
    ];

    protected array $_hidden = [];

    protected array $_virtual = [];

    protected function _setName(string $name): string
    {
        return trim($name);
    }

    protected function _setScore(mixed $score): int
    {
        return (int)$score;
    }

    protected function _getDisplayName(): string
    {
        return $this->name;
    }

    public function hasMinimumScore(int $minimumScore): bool
    {
        return $this->score >= $minimumScore;
    }

    public function getWinRate(): ?float
    {
        if (empty($this->match_reports)) {
            return null;
        }

        $wins = 0;
        $total = count($this->match_reports);

        foreach ($this->match_reports as $report) {
            if ($report->result === 'win') {
                $wins++;
            }
        }

        return $total > 0 ? round(($wins / $total) * 100, 2) : 0.0;
    }

    public function getTotalScoreDelta(): ?int
    {
        if (empty($this->trophy_history)) {
            return null;
        }

        return array_sum(array_map(fn($h) => $h->score_delta, $this->trophy_history));
    }
}
