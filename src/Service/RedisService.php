<?php declare(strict_types=1);

namespace App\Service;

use Cake\Core\Configure;
use Redis;
use RedisException;
use Cake\Log\Log;
use Throwable;

/**
 * RedisService
 */
class RedisService
{
    /** @var Redis|null  Live connection, null when unavailable. */
    private ?Redis $redis = null;

    /** @var bool  False after the first caught RedisException. */
    private bool $available = false;

    /** @var string  Key namespace prefix for all leaderboard keys. */
    private readonly string $prefix;

    /** @var int  Max requests allowed per sliding window. */
    private readonly int $rateLimitMax;

    /** @var int  Sliding window width in seconds. */
    private readonly int $rateLimitWindow;

    /** Using persist connection to redis server */
    private bool $enablePersistConnection = true;

    public function __construct(bool $enablePersistConnection = true)
    {
        $this->enablePersistConnection = $enablePersistConnection;
        $lbCfg = Configure::read('Leaderboard', []);

        $this->prefix = (string)($lbCfg['redis_key_prefix'] ?? 'lb:');
        $this->rateLimitMax = (int)($lbCfg['rate_limit']['max_requests'] ?? 5);
        $this->rateLimitWindow = (int)($lbCfg['rate_limit']['window_seconds'] ?? 10);

        $this->connect(Configure::read('Redis', []));
    }

    /**
     * Establish a Redis connection from the application configuration.
     *
     * @param array<string, mixed> $cfg The 'Redis' block from app.php.
     */
    private function connect(array $cfg): void
    {
        try {
            $r = new Redis();

            if ($this->enablePersistConnection) {
                $connected = $r->pconnect(
                    (string)($cfg['host'] ?? '127.0.0.1'),
                    (int)($cfg['port'] ?? 6379),
                    (float)($cfg['timeout'] ?? 2.0),
                );
            } else {
                $connected = $r->connect(
                    (string)($cfg['host'] ?? '127.0.0.1'),
                    (int)($cfg['port'] ?? 6379),
                    (float)($cfg['timeout'] ?? 2.0),
                );
            }


            if (!$connected) {
                throw new RedisException('Redis::connect() returned false.');
            }

            if (!empty($cfg['password'])) {
                $r->auth((string)$cfg['password']);
            }

            $r->select((int)($cfg['database'] ?? 0));
            $r->ping();

            $this->redis = $r;
            $this->available = true;
        } catch (RedisException $e) {
            HyperLogger::error('[RedisService] Connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Returns true when a live Redis connection is available.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Issue a PING health check.
     *
     * @return bool  True when Redis responds successfully.
     */
    public function ping(): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            $pong = $this->redis->ping();
            return $pong === true || $pong === '+PONG';
        } catch (RedisException $e) {
            $this->markUnavailable('ping: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cache the completed response payload for a given request_id.
     *
     * @param string $requestId Client-supplied idempotency key.
     * @param array<string, mixed> $payload Response body + __hash.
     */
    public function storeIdempotencyResult(string $requestId, array $payload): void
    {
        if (!$this->available) {
            return;
        }

        try {
            $this->redis->setEx(
                'idem:' . $requestId,
                86_400,                                         // 24 h
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            $this->markUnavailable('storeIdempotencyResult: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a previously cached response for a request_id.
     *
     * @param string $requestId Client-supplied idempotency key.
     * @return array<string, mixed>|null  Cached payload, or null on miss/error.
     */
    public function getIdempotencyResult(string $requestId): ?array
    {
        if (!$this->available) {
            return null;
        }
        try {
            $data = $this->redis->get('idem:' . $requestId);

            if ($data === false) {
                return null;
            }

            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->markUnavailable('getIdempotencyResult: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Add or update one player's entry in the leaderboard sorted set.
     *
     * @param int $userId The player's database id.
     * @param string $name Display name (cached to avoid extra DB reads).
     * @param int $newScore The player's updated total score.
     *
     * @return bool  True on success, false when Redis is unavailable.
     */
    public function upsertLeaderboardScore(int $userId, string $name, int $newScore): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            $scoreKey = $this->prefix . 'scores';
            $userKey = $this->prefix . 'user:' . $userId;

            $this->redis->multi(Redis::PIPELINE);
            $this->redis->zAdd($scoreKey, $newScore, (string)$userId);
            $this->redis->hMSet($userKey, [
                'id' => (string)$userId,
                'name' => $name,
                'score' => (string)$newScore,
            ]);
            $this->redis->exec();

            return true;
        } catch (RedisException $e) {
            $this->markUnavailable('upsertLeaderboardScore: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch a paginated leaderboard page from the sorted set.
     *
     * @param int $limit Page size (1–100).
     * @param int $offset Zero-based starting position.
     * @return array<int, array{rank: int, user_id: int, name: string, score: int}>
     *         Returns an empty array when Redis is unavailable or the set is empty.
     */
    public function getLeaderboard(int $limit = 10, int $offset = 0): array
    {
        if (!$this->available) {
            return [];
        }

        try {
            $scoreKey = $this->prefix . 'scores';

            /** @var array<string, float>|false $members */
            $members = $this->redis->zRevRange(
                $scoreKey,
                $offset,
                $offset + $limit - 1,
                true,
            );

            if (empty($members)) {
                return [];
            }

            $result = [];
            $rank = $offset + 1;

            foreach ($members as $memberId => $score) {
                $meta = $this->redis->hGetAll($this->prefix . 'user:' . $memberId);

                $result[] = [
                    'rank' => $rank++,
                    'user_id' => (int)$memberId,
                    'name' => (string)($meta['name'] ?? 'Unknown'),
                    'score' => (int)$score,
                ];
            }

            return $result;
        } catch (RedisException $e) {
            $this->markUnavailable('getLeaderboard: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Bulk-load all users into the sorted set for cache warm-up.
     *
     * @param array<int, array{id: int, name: string, score: int}> $users
     */
    public function seedLeaderboard(array $users): void
    {
        if (!$this->available || empty($users)) {
            return;
        }

        try {
            $scoreKey = $this->prefix . 'scores';

            $this->redis->multi(Redis::PIPELINE);

            foreach ($users as $u) {
                $this->redis->zAdd($scoreKey, (int)$u['score'], (string)$u['id']);
                $this->redis->hMSet(
                    $this->prefix . 'user:' . $u['id'],
                    [
                        'id' => (string)$u['id'],
                        'name' => (string)$u['name'],
                        'score' => (string)$u['score'],
                    ],
                );
            }

            $this->redis->exec();

            Log::info(
                sprintf('[RedisService] Leaderboard seeded with %d users.', count($users)),
                ['scope' => 'leaderboard'],
            );
        } catch (RedisException $e) {
            $this->markUnavailable('seedLeaderboard: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    /**
     * Enforce a sliding-window rate limit keyed on (IP + user_id).
     *
     * @param string $ip Client IP (may be from X-Forwarded-For).
     * @param int $userId User id from the request body.
     * @return bool  True = request allowed; false = limit exceeded.
     */
    public function checkRateLimit(string $ip, int $userId): bool
    {
        if (!$this->available) {
            Log::warning(
                '[RedisService] Rate limiter bypassed — Redis unavailable (fail-open).',
                ['scope' => 'rate_limit'],
            );
            return true;
        }

        try {
            $key = 'rl:' . $ip . ':' . $userId;
            $count = $this->redis->incr($key);

            if ($count === 1) {
                $this->redis->expire($key, $this->rateLimitWindow);
            }

            return $count <= $this->rateLimitMax;
        } catch (RedisException $e) {
            $this->markUnavailable('checkRateLimit: ' . $e->getMessage());
            return true; // fail-open
        }
    }

    /**
     * Return remaining quota and TTL for informational rate-limit headers.
     *
     * @param string $ip Client IP address.
     * @param int $userId User id from the request body.
     *
     * @return array{remaining: int, reset_in: int}
     */
    public function getRateLimitInfo(string $ip, int $userId): array
    {
        $defaults = ['remaining' => $this->rateLimitMax, 'reset_in' => $this->rateLimitWindow];

        if (!$this->available) {
            return $defaults;
        }

        try {
            $key = 'rl:' . $ip . ':' . $userId;
            $count = (int)($this->redis->get($key) ?: 0);
            $ttl = (int)$this->redis->ttl($key);

            return [
                'remaining' => max(0, $this->rateLimitMax - $count),
                'reset_in' => $ttl > 0 ? $ttl : $this->rateLimitWindow,
            ];
        } catch (RedisException $e) {
            $this->markUnavailable('getRateLimitInfo: ' . $e->getMessage());
            return $defaults;
        }
    }

    /**
     * Mark this service as unavailable and log the reason.
     *
     * @param string $reason Context string appended to the log line.
     */
    private function markUnavailable(string $reason): void
    {
        $this->available = false;
        Log::warning('[RedisService] Marked unavailable — ' . $reason, ['scope' => 'redis']);
    }
}
