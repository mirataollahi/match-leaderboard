<?php declare(strict_types=1);

namespace App\Repository\TrophyHistoryRepository;

use Cake\ORM\TableRegistry;
use RuntimeException;
use Throwable;

/**
 * CakePHP ORM implementation of TrophyHistoryRepositoryInterface.
 */
class TrophyHistoryRepository implements TrophyHistoryRepositoryInterface
{
    /**
     * Persist a score-change audit record to `trophy_history`.
     *
     * @param int $userId
     * @param string $matchId
     * @param int $scoreBefore
     * @param int $scoreAfter
     * @param int $scoreDelta
     * @param string $reason
     *
     * @throws RuntimeException
     */
    public function record(
        int    $userId,
        string $matchId,
        int    $scoreBefore,
        int    $scoreAfter,
        int    $scoreDelta,
        string $reason,
    ): void
    {

        try {
            $table = TableRegistry::getTableLocator()->get('TrophyHistory');

            $entity = $table->newEntity([
                'user_id' => $userId,
                'match_id' => $matchId,
                'score_before' => $scoreBefore,
                'score_after' => $scoreAfter,
                'score_delta' => $scoreDelta,
                'reason' => $reason,
            ], ['accessibleFields' => [
                'user_id' => true,
                'match_id' => true,
                'score_before' => true,
                'score_after' => true,
                'score_delta' => true,
                'reason' => true,
            ]]);

            if ($entity->hasErrors()) {
                throw new RuntimeException(
                    'TrophyHistory validation failed: ' . json_encode($entity->getErrors(), JSON_THROW_ON_ERROR),
                );
            }

            // ['atomic' => false] — transaction is owned by the caller (service layer)
            if (!$table->save($entity, ['atomic' => false])) {
                throw new RuntimeException('Failed to save TrophyHistory entity.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
        }

    }
}
