<?php declare(strict_types=1);

namespace App\Repository\MatchReportRepository;

use App\Model\Entity\MatchReport;
use App\Model\Entity\User;
use App\Model\Table\MatchReportsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DateTime;
use RuntimeException;
use Throwable;
use Exception;

class MatchReportRepository implements MatchReportRepositoryInterface
{
    use LocatorAwareTrait;

    /**
     * Match report ORM  table class
     *
     * @var MatchReportsTable|Table
     */
    public MatchReportsTable|Table $matchReportsTable;

    /**
     * Create match report repository and inject it dependencies
     */
    public function __construct()
    {
        $this->matchReportsTable = $this->fetchTable('MatchReports');
    }

    /**
     * Find a match report base on it request id
     *
     * @param string $requestId Client store match report request id
     * @return MatchReport|null  Existing entity or null on first submission
     */
    public function findByRequestId(string $requestId): ?MatchReport
    {
        return $this->matchReportsTable->find()
            ->where(['request_id' => $requestId])->first();
    }

    /**
     * Create new match report base on it data
     *
     * @param array<string, mixed> $data Validated and normalized store request data
     * @return MatchReport The created match report in database
     * @throws RuntimeException Failed to insert match report in database exception
     */
    public function create(array $data): MatchReport
    {
        try {
            $preparedData = $this->prepareData($data);
            $entity = $this->matchReportsTable->newEntity($preparedData);

            // Validate the match report before insert
            if ($entity->hasErrors()) {
                throw new RuntimeException(
                    'Validation failed: ' . json_encode($entity->getErrors())
                );
            }
            /** @var MatchReport $matchReport */
            $matchReport = $this->matchReportsTable->saveOrFail($entity, ['atomic' => false]);
            return $matchReport;
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to create match report: {$e->getMessage()}",
                $e->getCode(), $e
            );
        }
    }

    /**
     * Prepare data for entity creation . Only type casting, no validation
     *
     * @param array<string, mixed> $data Raw input data
     * @return array<string, mixed> Prepared data
     * @throws Exception
     */
    protected function prepareData(array $data): array
    {
        $prepared = [
            'request_id' => (string)($data['request_id'] ?? ''),
            'user_id' => (int)($data['user_id'] ?? 0),
            'match_id' => (string)($data['match_id'] ?? ''),
            'result' => (string)($data['result'] ?? ''),
            'score_delta' => (int)($data['score_delta'] ?? 0),
        ];

        // Handle reported_at separately
        if (isset($data['reported_at'])) {
            if (is_numeric($data['reported_at'])) {
                $prepared['reported_at'] = (new DateTime())->setTimestamp((int)$data['reported_at']);
            } elseif ($data['reported_at'] instanceof DateTime) {
                $prepared['reported_at'] = $data['reported_at'];
            } elseif (is_string($data['reported_at'])) {
                $prepared['reported_at'] = new DateTime($data['reported_at']);
            }
        }
        return $prepared;
    }

    /**
     * Find a match report for the given user and match ID.
     *
     * @param User $user The user entity
     * @param int $matchId The match id
     * @return MatchReport|null The match report of empty if not found in user match reports
     */
    public function findUserMatchReport(User $user, int $matchId): ?MatchReport
    {
        return $this->matchReportsTable->find()
            ->where([
                'user_id' => $user->id,
                'match_id' => $matchId,
            ])->first();
    }
}
