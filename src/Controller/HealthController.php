<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\HyperLogger;
use App\Service\RedisService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use JsonException;
use Throwable;

class HealthController extends BaseApiController
{
    /**
     * Handle health index request
     *
     * @param RedisService $redisService
     * @return Response
     * @throws JsonException
     */
    public function index(RedisService $redisService): Response
    {
        $this->request->allowMethod(['get']);

        $status = [
            'services' => [
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis($redisService),
            ],
        ];

        // Determine overall health status
        $isHealthy = true;
        foreach ($status['services'] as $service => $health) {
            if (!$health) {
                $isHealthy = false;
                break;
            }
        }

        return $this->successResponse(
            array_merge(
                [
                    'status' => $isHealthy ? 'healthy' : 'unhealthy',
                    'timestamp' => time(),
                ],
                $status
            ),
            $isHealthy ? 200 : 503
        );
    }

    /**
     * Check database connectivity with lightweight query
     *
     * @return bool
     */
    private function checkDatabase(): bool
    {
        try {
            $connection = ConnectionManager::get('default');
            $connection->execute('SELECT 1');
            return true;
        } catch (Throwable $e) {
            HyperLogger::error("Check database health failed : {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Check Redis connectivity with a simple redis check
     *
     * @param RedisService $redisService
     * @return bool
     */
    private function checkRedis(RedisService $redisService): bool
    {
        return $redisService->isAvailable();
    }
}
