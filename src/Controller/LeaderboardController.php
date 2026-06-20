<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\LeaderboardService;
use Throwable;
use Cake\Log\Log;
use Cake\Http\Response;
use JsonException;

class LeaderboardController extends AppController
{
    /**
     * Retrieve the current leaderboard with pagination
     *
     * @param LeaderboardService $leaderboardService
     * @return Response
     * @throws JsonException
     */
    public function index(
        LeaderboardService $leaderboardService,
    ): Response
    {
        $this->request->allowMethod(['get']);

        try {
            $limit = (int)($this->request->getQuery('limit', 10));
            $offset = (int)($this->request->getQuery('offset', 0));

            if ($limit < 1 || $limit > 100) {
                return $this->response
                    ->withStatus(422)
                    ->withType('application/json')
                    ->withStringBody(json_encode([
                        'success' => false,
                        'error' => 'VALIDATION_ERROR',
                        'message' => 'Limit must be between 1 and 100.',
                    ], JSON_THROW_ON_ERROR));
            }

            if ($offset < 0) {
                return $this->response
                    ->withStatus(422)
                    ->withType('application/json')
                    ->withStringBody(json_encode([
                        'success' => false,
                        'error' => 'VALIDATION_ERROR',
                        'message' => 'Offset must be a non-negative integer.',
                    ], JSON_THROW_ON_ERROR));
            }

            // Get leaderboard data
            $result = $leaderboardService->getLeaderboard($limit, $offset);

            return $this->response
                ->withStatus(200)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => true,
                    'data' => $result['data'],
                    'source' => $result['source'],
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                    ],
                ], JSON_THROW_ON_ERROR));

        } catch (Throwable $e) {
            Log::error('[LeaderboardController] Unexpected error: ' . $e->getMessage(), [
                'exception' => $e,
                'scope' => 'leaderboard',
            ]);

            return $this->response
                ->withStatus(500)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => 'INTERNAL_ERROR',
                    'message' => 'An unexpected error occurred while fetching the leaderboard.',
                ], JSON_THROW_ON_ERROR));
        }
    }
}
