<?php declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;
use Cake\Log\Log;
use Cake\Cache\Cache;
use Cake\Http\Exception\TooManyRequestsException;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;
use JsonException;

class BaseApiController extends AppController
{
    /**
     * Create a JSON response with consistent structure
     *
     * @param array $data
     * @param int $statusCode
     * @return Response
     * @throws JsonException
     */
    protected function jsonResponse(array $data, int $statusCode = 200): Response
    {
        return $this->response
            ->withStatus($statusCode)
            ->withType('application/json')
            ->withStringBody(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Create a success response
     *
     * @param array $data
     * @param int $statusCode
     * @return Response
     * @throws JsonException
     */
    protected function successResponse(array $data = [], int $statusCode = 200): Response
    {
        return $this->jsonResponse(
            array_merge(['success' => true], $data),
            $statusCode
        );
    }

    /**
     * Create an error response
     *
     * @param string $error
     * @param string $message
     * @param int $statusCode
     * @param array $additionalData
     * @return Response
     * @throws JsonException
     */
    protected function errorResponse(
        string $error,
        string $message = '',
        int    $statusCode = 500,
        array  $additionalData = []
    ): Response
    {
        $responseData = [
            'success' => false,
            'error' => $error,
        ];

        if (!empty($message)) {
            $responseData['message'] = $message;
        }

        $responseData = array_merge($responseData, $additionalData);

        return $this->jsonResponse($responseData, $statusCode);
    }

    /**
     * Log an unexpected error
     *
     * @param string $controllerName
     * @param string $message
     * @param array $context
     * @return Response
     * @throws JsonException
     */
    protected function handleUnexpectedError(
        string $controllerName,
        string $message,
        array  $context = []
    ): Response
    {
        Log::error("[{$controllerName}] Unexpected error: {$message}", $context);

        return $this->errorResponse(
            $message,
            $message,
            500
        );
    }

    /**
     * Handle a caught exception and return appropriate JSON response
     *
     * @param Throwable $e
     * @param string $controllerName
     * @return Response
     * @throws JsonException
     */
    protected function handleException(Throwable $e, string $controllerName): Response
    {
        return $this->handleUnexpectedError(
            $controllerName,
            $e->getMessage(),
            [
                'exception' => $e,
                'scope' => strtolower(str_replace('Controller', '', $controllerName)),
            ]
        );
    }

    /**
     * Validate and sanitize pagination parameters
     *
     * @param int $defaultLimit
     * @param int $maxLimit
     * @param int $defaultOffset
     * @return array|null Returns ['limit' => int, 'offset' => int] or null if validation fails with error response set
     * @throws JsonException
     */
    protected function validatePaginationParams(
        int $defaultLimit = 10,
        int $maxLimit = 100,
        int $defaultOffset = 0
    ): ?array
    {
        $limit = (int)($this->request->getQuery('limit', $defaultLimit));
        $offset = (int)($this->request->getQuery('offset', $defaultOffset));

        if ($limit < 1 || $limit > $maxLimit) {
            $this->response = $this->errorResponse(
                'VALIDATION_ERROR',
                "Limit must be between 1 and {$maxLimit}.",
                422
            );
            return null;
        }

        if ($offset < 0) {
            $this->response = $this->errorResponse(
                'VALIDATION_ERROR',
                'Offset must be a non-negative integer.',
                422
            );
            return null;
        }

        return ['limit' => $limit, 'offset' => $offset];
    }

    /**
     * Rate limiting implementation based on user_id + IP address.
     * Maximum 5 requests in 10 seconds window.
     *
     * @throws TooManyRequestsException|InvalidArgumentException
     */
    protected function checkRateLimit(): void
    {
        $userId = $this->request->getData('user_id') ?? 'anonymous';
        $ipAddress = $this->request->clientIp() ?? '0.0.0.0';

        $rateLimitKey = "rate_limit:{$userId}:{$ipAddress}";

        $cache = Cache::pool('default');

        $current = $cache->get($rateLimitKey, 0);

        if ($current >= 5) {
            throw new TooManyRequestsException(
                'Rate limit exceeded. Maximum 5 requests per 10 seconds.'
            );
        }

        $cache->set($rateLimitKey, $current + 1, 10);

        // Add rate limit headers
        $this->response = $this->response
            ->withHeader('X-RateLimit-Limit', '5')
            ->withHeader('X-RateLimit-Remaining', (string)(4 - $current))
            ->withHeader('X-RateLimit-Reset', (string)(time() + 10));
    }
}
