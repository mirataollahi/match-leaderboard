<?php declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use DateTime;
use InvalidArgumentException;

/**
 * The trophy history table columns in database
 *
 * @property int $user_id
 * @property string $match_id
 * @property int $score_before
 * @property int $score_after
 * @property int $score_delta
 * @property string $reason
 * @property DateTime $created_at
 *
 * @property User $user
 */
class TrophyHistory extends Entity
{
    protected array $_accessible = [
        '*' => false,
        'user_id' => true,
        'match_id' => true,
        'score_before' => true,
        'score_after' => true,
        'score_delta' => true,
        'reason' => true,
        'user' => true,
        'created_at' => false,
    ];

    protected array $_hidden = [];

    public const VALID_REASONS = [
        'match_win',
        'match_loss',
        'match_draw',
        'admin_adjustment',
        'penalty',
        'bonus',
    ];

    protected function _setUserId(mixed $userId): int
    {
        return (int)$userId;
    }

    protected function _setScoreBefore(mixed $s): int
    {
        return (int)$s;
    }

    protected function _setScoreAfter(mixed $s): int
    {
        return (int)$s;
    }

    protected function _setScoreDelta(mixed $d): int
    {
        return (int)$d;
    }

    protected function _setReason(string $reason): string
    {
        $reason = strtolower(trim($reason));

        if (!in_array($reason, self::VALID_REASONS, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid reason "%s". Must be one of: %s', $reason, implode(', ', self::VALID_REASONS))
            );
        }

        return $reason;
    }

    public function isScoreConsistent(): bool
    {
        return ($this->score_before + $this->score_delta) === $this->score_after;
    }

    public function isScoreIncreased(): bool
    {
        return $this->score_after > $this->score_before;
    }

    public function isScoreDecreased(): bool
    {
        return $this->score_after < $this->score_before;
    }

    public function getScoreChangePercentage(): float
    {
        if ($this->score_before === 0) {
            return $this->score_after > 0 ? 100.0 : 0.0;
        }
        return round(($this->score_delta / abs($this->score_before)) * 100, 2);
    }

    public function getAbsoluteChange(): int
    {
        return abs($this->score_delta);
    }

    public function getSummary(): array
    {
        return [
            'match_id' => $this->match_id,
            'score_before' => $this->score_before,
            'score_after' => $this->score_after,
            'score_delta' => $this->score_delta,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'change_type' => $this->isScoreIncreased() ? 'increase' : 'decrease',
        ];
    }
}
