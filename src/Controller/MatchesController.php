<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\MatchReportService;
use Cake\Http\Exception\BadRequestException;
use Cake\Log\Log;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;
use App\Exception\RequestIdConflictException;
use App\Exception\UserNotFoundException;
use App\Exception\ValidationException;
use App\Service\MatchReportValidator;
use Cake\Http\Exception\TooManyRequestsException;
use Cake\Http\Response;

class MatchesController extends AppController
{

    /**
     * Handle create match report api request
     *
     * @param MatchReportValidator $matchReportValidator
     * @param MatchReportService $matchReportService
     * @return Response
     * @throws Throwable
     */
    public function report(
        MatchReportValidator $matchReportValidator,
        MatchReportService   $matchReportService
    ): Response
    {
        $this->request->allowMethod(['post']);

        // Rate limiting: max 5 requests per 10 seconds per user_id + IP
        $this->checkRateLimit();

        try {
            // Parse and validate the request body
            $rawData = $this->request->getData();

            if (empty($rawData)) {
                throw new BadRequestException('Request body must be valid JSON.');
            }
            $validatedData = $matchReportValidator->validate($rawData);
            $result = $matchReportService->process($validatedData);

            return $this->response
                ->withStatus($result['duplicate'] ? 200 : 201)
                ->withType('application/json')
                ->withStringBody(json_encode($result, JSON_THROW_ON_ERROR));

        } catch (ValidationException $e) {
            return $this->response
                ->withStatus(422)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => 'VALIDATION_ERROR',
                    'details' => $e->getErrors(),
                ], JSON_THROW_ON_ERROR));

        } catch (UserNotFoundException $e) {
            return $this->response
                ->withStatus(404)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => 'USER_NOT_FOUND',
                    'message' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR));

        } catch (RequestIdConflictException $e) {
            return $this->response
                ->withStatus(409)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => 'REQUEST_ID_CONFLICT',
                ], JSON_THROW_ON_ERROR));

        } catch (Throwable $e) {
            Log::error('[MatchesController] Unexpected error: ' . $e->getMessage(), [
                'exception' => $e,
                'scope' => 'match',
            ]);

            return $this->response
                ->withStatus(500)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => 'INTERNAL_ERROR',
                    'message' => 'An unexpected error occurred.',
                ], JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Rate limiting implementation based on user_id + IP address.
     * Maximum 5 requests in 10 seconds window.
     *
     * @throws TooManyRequestsException|InvalidArgumentException
     */
    private function checkRateLimit(): void
    {
        $userId = $this->request->getData('user_id') ?? 'anonymous';
        $ipAddress = $this->request->clientIp() ?? '0.0.0.0';

        $rateLimitKey = "rate_limit:{$userId}:{$ipAddress}";

        $cache = \Cake\Cache\Cache::pool('default');

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
