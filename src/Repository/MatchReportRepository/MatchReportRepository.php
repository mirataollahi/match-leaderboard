<?php declare(strict_types=1);

namespace App\Repository\MatchReportRepository;

use App\Model\Entity\MatchReport;
use Cake\ORM\TableRegistry;
use RuntimeException;
use Throwable;

/**
 * CakePHP ORM implementation of MatchReportRepositoryInterface.
 */
class MatchReportRepository implements MatchReportRepositoryInterface
{
    /**
     * Look up an existing match report by the idempotency key.
     *
     * @param string $requestId Client-supplied idempotency key.
     * @return MatchReport|null  Existing entity or null on first submission.
     */
    public function findByRequestId(string $requestId): ?MatchReport
    {
        $table = TableRegistry::getTableLocator()->get('MatchReports');
        /** @var MatchReport|null $report */
        $report = $table->find()
            ->where(['request_id' => $requestId])
            ->first();

        return $report;
    }

    /**
     * Persist a new match_reports row.
     *
     * @param array<string, mixed> $data Validated and normalised request fields.
     * @return MatchReport The freshly saved entity.
     * @throws RuntimeException When entity validation or save fails.
     */
    public function create(array $data): MatchReport
    {
        try {
            $table = TableRegistry::getTableLocator()->get('MatchReports');

            /** @var MatchReport $entity */
            $entity = $table->newEntity([
                'request_id' => $data['request_id'],
                'user_id' => (int)$data['user_id'],
                'match_id' => (string)$data['match_id'],
                'result' => $data['result'],
                'score_delta' => (int)$data['score_delta'],
                'reported_at' => (new \DateTime())->setTimestamp((int)$data['reported_at']),
            ], ['accessibleFields' => [
                'request_id' => true,
                'user_id' => true,
                'match_id' => true,
                'result' => true,
                'score_delta' => true,
                'reported_at' => true,
            ]]);

            if ($entity->hasErrors()) {
                throw new RuntimeException('MatchReport validation failed: ' . json_encode($entity->getErrors(), JSON_THROW_ON_ERROR),);
            }

            // ['atomic' => false] — transaction is owned by the caller (service layer)
            if (!$table->save($entity, ['atomic' => false])) {
                throw new RuntimeException('Failed to save MatchReport entity.');
            }

            return $entity;
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
