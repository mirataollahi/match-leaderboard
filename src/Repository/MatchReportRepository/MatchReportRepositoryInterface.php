<?php declare(strict_types=1);

namespace App\Repository\MatchReportRepository;

use App\Model\Entity\MatchReport;
use RuntimeException;

/**
 * Contract for all MatchReport persistence operations.
 */
interface MatchReportRepositoryInterface
{
    /**
     * Find an existing match report by its idempotency key.
     *
     * @param string $requestId The client-supplied idempotency key.
     * @return MatchReport|null  The entity if already processed, null otherwise.
     */
    public function findByRequestId(string $requestId): ?MatchReport;

    /**
     * Persist a new match report row.
     *
     * Must be called inside an existing transaction.
     *
     * @param array<string, mixed> $data Validated report data.
     *
     * @return MatchReport The saved entity.
     *
     * @throws RuntimeException When the save fails.
     */
    public function create(array $data): MatchReport;
}
