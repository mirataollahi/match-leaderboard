<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\RedisService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use JsonException;
use Throwable;

class HealthController extends AppController
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
            'success' => true,
            'status' => 'healthy',
            'timestamp' => time(),
            'services' => [
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis($redisService),
            ],
        ];

        // If any service is unhealthy, return 503
        $hasIssues = false;
        foreach ($status['services'] as $service => $health) {
            if (!$health) {
                $hasIssues = true;
                $status['status'] = 'unhealthy';
                break;
            }
        }

        return $this->response
            ->withStatus($hasIssues ? 503 : 200)
            ->withType('application/json')
            ->withStringBody(json_encode($status, JSON_THROW_ON_ERROR));
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
