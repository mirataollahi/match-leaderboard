<?php declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use DateTime;
use InvalidArgumentException;

/**
 * The match_report table columns in database
 *
 * @property int $id
 * @property string $request_id
 * @property int $user_id
 * @property string $match_id
 * @property string $result
 * @property int $score_delta
 * @property DateTime $reported_at
 * @property DateTime $created_at
 *
 * @property User $user
 */
class MatchReport extends Entity
{
    /**
     * The match reports table accessible columns
     *
     * @var array
     */
    protected array $_accessible = [
        '*' => false,
        'request_id' => true,
        'user_id' => true,
        'match_id' => true,
        'result' => true,
        'score_delta' => true,
        'reported_at' => true,
        'user' => true,
        'created_at' => false,
    ];

    /**
     * The hidden columns after fetched from database
     *
     * @var array
     */
    protected array $_hidden = [];

    /**
     * Accept both "lose" (API spec) and "loss" (DB canonical)
     */
    public const VALID_RESULTS = ['win', 'loss', 'lose', 'draw'];

    /**
     * The result score in match score
     */
    public const RESULT_SCORES = [
        'win' => 25,
        'draw' => 5,
        'loss' => -15,
        'lose' => -15,
    ];

    /**
     * Normalize match report result for DB consistency
     *
     * @param string $result The pure match report info
     * @return string
     */
    protected function _setResult(string $result): string
    {
        $result = strtolower(trim($result));
        if ($result === 'lose') {
            $result = 'loss';
        }

        if (!in_array($result, ['win', 'loss', 'draw'], true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid result "%s". Must be one of: win, lose, draw', $result)
            );
        }

        return $result;
    }

    /**
     * Normalize score delta in match report
     *
     * @param mixed $scoreDelta
     * @return int
     */
    protected function _setScoreDelta(mixed $scoreDelta): int
    {
        return (int)$scoreDelta;
    }

    /**
     * Normalize user id type to integer in match report
     *
     * @param mixed $userId
     * @return int
     */
    protected function _setUserId(mixed $userId): int
    {
        return (int)$userId;
    }

    /**
     * Check the match report is win or not
     *
     * @return bool
     */
    public function isWin(): bool
    {
        return $this->result === 'win';
    }

    /**
     * Check the match report is loss or not
     *
     * @return bool
     */
    public function isLoss(): bool
    {
        return $this->result === 'loss';
    }

    /**
     * Check the match report is draw or not
     *
     * @return bool
     */
    public function isDraw(): bool
    {
        return $this->result === 'draw';
    }

    /**
     * Check the match report absolute score delta
     *
     * @return int
     */
    public function getAbsoluteScoreDelta(): int
    {
        return abs($this->score_delta);
    }

    /**
     * Is score increased in the match report item
     *
     * @return bool
     */
    public function isScoreIncreased(): bool
    {
        return $this->score_delta > 0;
    }

    /**
     * Check is score is decreased in the match report item
     *
     * @return bool
     */
    public function isScoreDecreased(): bool
    {
        return $this->score_delta < 0;
    }

    /**
     * Get expected score delta
     *
     * @return int
     */
    public function getExpectedScoreDelta(): int
    {
        return self::RESULT_SCORES[$this->result] ?? 0;
    }

    /**
     * Get summery info of the match report
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'match_id' => $this->match_id,
            'result' => $this->result,
            'score_delta' => $this->score_delta,
            'reported_at' => $this->reported_at?->format('Y-m-d H:i:s'),
        ];
    }
}
