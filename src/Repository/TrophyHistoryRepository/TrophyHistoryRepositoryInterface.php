<?php declare(strict_types=1);

namespace App\Repository\TrophyHistoryRepository;

use RuntimeException;

interface TrophyHistoryRepositoryInterface
{
    /**
     * Persist a score-change audit record.
     *
     * Must be called inside an existing transaction.
     *
     * @param int $userId The player whose score changed.
     * @param string $matchId The match id
     * @param int $scoreBefore Score before the change.
     * @param int $scoreAfter Score after the change.
     * @param int $scoreDelta The score before and after delta
     * @param string $reason One of the trophy valid reason
     *
     * @throws RuntimeException Error on recored throphy history
     */
    public function record(
        int    $userId,
        string $matchId,
        int    $scoreBefore,
        int    $scoreAfter,
        int    $scoreDelta,
        string $reason,
    ): void;
}
