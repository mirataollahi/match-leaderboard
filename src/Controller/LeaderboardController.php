<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\LeaderboardService;
use Throwable;
use Cake\Http\Response;
use JsonException;

class LeaderboardController extends BaseApiController
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
            // Validate pagination parameters
            $pagination = $this->validatePaginationParams();
            if ($pagination === null) {
                return $this->response; // Response already set by validation
            }

            // Get leaderboard data
            $result = $leaderboardService->getLeaderboard(
                $pagination['limit'],
                $pagination['offset']
            );

            return $this->successResponse([
                'data' => $result['data'],
                'source' => $result['source'],
                'pagination' => [
                    'limit' => $pagination['limit'],
                    'offset' => $pagination['offset'],
                ],
            ]);

        } catch (Throwable $e) {
            return $this->handleException($e, 'LeaderboardController');
        }
    }
}
