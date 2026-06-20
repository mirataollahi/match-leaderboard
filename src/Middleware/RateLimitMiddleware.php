<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\RedisService;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Cake\Log\Log;

/**
 * RateLimitMiddleware
 *
 * Applies a sliding-window rate limit keyed by (client IP + user_id).
 * Only applied to routes that carry a user_id in the JSON body.
 *
 * Limit: configurable via Leaderboard.rate_limit (default 5 req / 10 sec).
 *
 * When Redis is unavailable the check is skipped (fail-open) and a warning is logged.
 * Returns 429 with Retry-After header when limit is exceeded.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private RedisService $redis;

    public function __construct(RedisService $redis)
    {
        $this->redis = $redis;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Only throttle match report submissions
        $path = $request->getUri()->getPath();
        if (!str_contains($path, '/matches/report')) {
            return $handler->handle($request);
        }

        $body   = $request->getParsedBody() ?? [];
        $userId = (int)($body['user_id'] ?? 0);

        if ($userId === 0) {
            // Validation will catch missing user_id — let it through
            return $handler->handle($request);
        }

        $ip = $this->resolveIp($request);

        $allowed = $this->redis->checkRateLimit($ip, $userId);

        if (!$allowed) {
            $info = $this->redis->getRateLimitInfo($ip, $userId);

            Log::warning('Rate limit exceeded', [
                'scope'    => 'rate_limit',
                'ip'       => $ip,
                'user_id'  => $userId,
                'reset_in' => $info['reset_in'],
            ]);

            $response = new Response();
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)$info['reset_in'])
                ->withHeader('X-RateLimit-Limit', (string)5)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withStringBody(json_encode([
                    'success' => false,
                    'error'   => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Too many requests. Please retry after ' . $info['reset_in'] . ' seconds.',
                    'retry_after' => $info['reset_in'],
                ], JSON_THROW_ON_ERROR));
        }

        $response = $handler->handle($request);

        // Attach rate-limit informational headers on success
        $info = $this->redis->getRateLimitInfo($ip, $userId);
        return $response
            ->withHeader('X-RateLimit-Limit', (string)5)
            ->withHeader('X-RateLimit-Remaining', (string)$info['remaining'])
            ->withHeader('X-RateLimit-Reset', (string)$info['reset_in']);
    }

    private function resolveIp(ServerRequestInterface $request): string
    {
        // Support common reverse-proxy headers
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded) {
            return trim(explode(',', $forwarded)[0]);
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
