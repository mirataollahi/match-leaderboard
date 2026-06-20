<?php declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;


use App\Exception\RequestIdConflictException;
use App\Exception\UserNotFoundException;
use App\Exception\ValidationException;
use App\Service\MatchReportService;
use App\Service\MatchReportValidator;
use Cake\Cache\Cache;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * MatchesControllerTest — integration tests for POST /matches/report
 * @covers \App\Controller\MatchesController
 */
class MatchesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Users',
        'app.MatchReports',
        'app.TrophyHistory',
    ];

    /** @var MatchReportValidator&MockObject */
    private MockObject $validatorMock;

    /** @var MatchReportService&MockObject */
    private MockObject $serviceMock;

    /**
     * Valid sample request body
     */
    private const VALID_PAYLOAD = [
        'request_id' => '9a7e91f2-1fd4-45a3-9ff0-2b3d9a0ef111',
        'user_id' => 12,
        'match_id' => 8801,
        'result' => 'win',
        'score_delta' => 15,
        'reported_at' => 1710000000,
    ];

    public function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->validatorMock = $this->createMock(MatchReportValidator::class);
        $this->serviceMock = $this->createMock(MatchReportService::class);

        // Inject mocks into the controller via service container or constructor
        $this->mockService(MatchReportValidator::class, $this->validatorMock);
        $this->mockService(MatchReportService::class, $this->serviceMock);

        Cache::clear('default');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Cache::clear('default');
    }

    /**
     * Helper to make a POST request to /matches/report
     */
    private function postMatch(array $data = self::VALID_PAYLOAD): void
    {
        $this->configRequest([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->post('/matches/report', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Helper to decode response body
     */
    private function getResponseBody(): array
    {
        return json_decode((string)$this->_response->getBody(), true) ?? [];
    }

    /** @test */
    public function testNewMatchReportReturns201(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->with(self::VALID_PAYLOAD)
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->with(self::VALID_PAYLOAD)
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 215,
            ]);

        $this->postMatch();

        $this->assertResponseCode(201);
        $body = $this->getResponseBody();
        $this->assertTrue($body['success']);
        $this->assertFalse($body['duplicate']);
        $this->assertSame(12, $body['user_id']);
        $this->assertSame(8801, $body['match_id']);
        $this->assertSame(215, $body['new_score']);
    }

    /** @test */
    public function testDuplicateMatchReportReturns200(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => true,
                'duplicate' => true,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 215,
            ]);

        $this->postMatch();

        $this->assertResponseCode(200);
        $body = $this->getResponseBody();
        $this->assertTrue($body['success']);
        $this->assertTrue($body['duplicate']);
    }

    // ========== Validation Error Cases ==========

    /** @test */
    public function testMissingRequestIdReturns422(): void
    {
        $payload = self::VALID_PAYLOAD;
        unset($payload['request_id']);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->with($payload)
            ->willThrowException(new ValidationException([
                'request_id' => 'request_id is required.',
            ]));

        $this->postMatch($payload);

        $this->assertResponseCode(422);
        $body = $this->getResponseBody();
        $this->assertFalse($body['success']);
        $this->assertSame('VALIDATION_ERROR', $body['error']);
        $this->assertArrayHasKey('request_id', $body['details']);
    }

    /** @test */
    public function testInvalidResultReturns422(): void
    {
        $payload = array_merge(self::VALID_PAYLOAD, ['result' => 'invalid']);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->with($payload)
            ->willThrowException(new ValidationException([
                'result' => 'result must be one of: win, lose, draw.',
            ]));

        $this->postMatch($payload);

        $this->assertResponseCode(422);
        $body = $this->getResponseBody();
        $this->assertSame('VALIDATION_ERROR', $body['error']);
    }

    /** @test */
    public function testNonNumericScoreDeltaReturns422(): void
    {
        $payload = array_merge(self::VALID_PAYLOAD, ['score_delta' => 'not_a_number']);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willThrowException(new ValidationException([
                'score_delta' => 'score_delta must be an integer.',
            ]));

        $this->postMatch($payload);

        $this->assertResponseCode(422);
    }

    /** @test */
    public function testFutureTimestampReturns422(): void
    {
        $payload = array_merge(self::VALID_PAYLOAD, ['reported_at' => time() + 600]);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willThrowException(new ValidationException([
                'reported_at' => 'reported_at cannot be more than 5 minutes in the future.',
            ]));

        $this->postMatch($payload);

        $this->assertResponseCode(422);
    }

    /** @test */
    public function testMultipleValidationErrorsReturned(): void
    {
        $payload = [
            'user_id' => -5,
            'match_id' => '',
            'result' => 'invalid',
        ];

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willThrowException(new ValidationException([
                'request_id' => 'request_id is required.',
                'user_id' => 'user_id must be a positive integer.',
                'match_id' => 'match_id is required.',
                'result' => 'result must be one of: win, lose, draw.',
            ]));

        $this->postMatch($payload);

        $this->assertResponseCode(422);
        $body = $this->getResponseBody();
        $this->assertCount(4, $body['details']);
    }

    /** @test */
    public function testUserNotFoundReturns404(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willThrowException(new UserNotFoundException(12));

        $this->postMatch();

        $this->assertResponseCode(404);
        $body = $this->getResponseBody();
        $this->assertFalse($body['success']);
        $this->assertSame('USER_NOT_FOUND', $body['error']);
    }

    /** @test */
    public function testRequestIdConflictReturns409(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willThrowException(new RequestIdConflictException());

        $this->postMatch();

        $this->assertResponseCode(409);
        $body = $this->getResponseBody();
        $this->assertFalse($body['success']);
        $this->assertSame('REQUEST_ID_CONFLICT', $body['error']);
    }

    /** @test */
    public function testRateLimitHeadersPresent(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 215,
            ]);

        $this->postMatch();

        $this->assertResponseCode(201);
        $this->assertHeader('X-RateLimit-Limit', '5');
        $this->assertHeaderContains('X-RateLimit-Remaining', '');
        $this->assertHeaderContains('X-RateLimit-Reset', '');
    }

    /** @test */
    public function testRateLimitExceededReturns429(): void
    {
        // Simulate 5 requests within the rate limit window
        $cache = Cache::pool('default');
        $rateLimitKey = "rate_limit:12:127.0.0.1";
        $cache->set($rateLimitKey, 5, 10);

        $this->postMatch();

        $this->assertResponseCode(429);
    }

    /** @test */
    public function testRateLimitDecrementsRemaining(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 215,
            ]);

        // First request should have 4 remaining
        $this->postMatch();
        $this->assertHeader('X-RateLimit-Remaining', '4');

        // Simulate second request with existing counter
        $cache = Cache::pool('default');
        $rateLimitKey = "rate_limit:12:127.0.0.1";
        $cache->set($rateLimitKey, 1, 10);

        $this->postMatch();
        $this->assertHeader('X-RateLimit-Remaining', '3');
    }

    /** @test */
    public function testRateLimitKeyUsesUserIdAndIp(): void
    {
        $cache = Cache::pool('default');

        // Test with different user_id (user 99)
        $payload = array_merge(self::VALID_PAYLOAD, ['user_id' => 99]);
        $rateLimitKey99 = "rate_limit:99:127.0.0.1";

        // Should not be rate limited even if user 12 is
        $cache->set("rate_limit:12:127.0.0.1", 5, 10);
        $cache->set($rateLimitKey99, 0, 10);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn($payload);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 99,
                'match_id' => 8801,
                'new_score' => 200,
            ]);

        $this->postMatch($payload);

        $this->assertResponseCode(201);
    }

    /** @test */
    public function testRateLimitWithMissingUserIdUsesAnonymous(): void
    {
        $payload = self::VALID_PAYLOAD;
        unset($payload['user_id']);

        $cache = Cache::pool('default');
        $rateLimitKey = "rate_limit:anonymous:127.0.0.1";
        $cache->set($rateLimitKey, 5, 10);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willThrowException(new ValidationException([
                'user_id' => 'user_id must be a positive integer.',
            ]));

        $this->postMatch($payload);

        $this->assertResponseCode(429);
    }

    /** @test */
    public function testGetMethodReturns405(): void
    {
        $this->get('/matches/report');

        $this->assertResponseCode(405);
    }

    /** @test */
    public function testPutMethodReturns405(): void
    {
        $this->put('/matches/report');

        $this->assertResponseCode(405);
    }

    /** @test */
    public function testDeleteMethodReturns405(): void
    {
        $this->delete('/matches/report');

        $this->assertResponseCode(405);
    }

    /** @test */
    public function testEmptyRequestBodyReturns400(): void
    {
        $this->configRequest([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->post('/matches/report', '');

        $this->assertResponseCode(400);
    }

    /** @test */
    public function testNonJsonRequestBodyReturns400(): void
    {
        $this->configRequest([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'text/plain',
            ],
        ]);

        $this->post('/matches/report', 'not json');

        $this->assertResponseCode(400);
    }

    /** @test */
    public function testLoseResultNormalizedToLoss(): void
    {
        $payload = array_merge(self::VALID_PAYLOAD, ['result' => 'lose']);
        $expected = array_merge($payload, ['result' => 'loss']);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->with($payload)
            ->willReturn($expected);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->with($expected)
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 185,
            ]);

        $this->postMatch($payload);

        $this->assertResponseCode(201);
    }

    /** @test */
    public function testDrawResultWithZeroScoreDelta(): void
    {
        $payload = array_merge(self::VALID_PAYLOAD, [
            'result' => 'draw',
            'score_delta' => 0,
        ]);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn($payload);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 200,
            ]);

        $this->postMatch($payload);

        $this->assertResponseCode(201);
    }

    /** @test */
    public function testNegativeScoreDelta(): void
    {
        $payload = array_merge(self::VALID_PAYLOAD, [
            'result' => 'lose',
            'score_delta' => -10,
        ]);

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn($payload);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => true,
                'duplicate' => false,
                'user_id' => 12,
                'match_id' => 8801,
                'new_score' => 190,
            ]);

        $this->postMatch($payload);

        $this->assertResponseCode(201);
    }

    /** @test */
    public function testServerErrorReturns500(): void
    {
        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn(self::VALID_PAYLOAD);

        $this->serviceMock
            ->expects($this->once())
            ->method('process')
            ->willThrowException(new RuntimeException('Database connection failed'));

        $this->postMatch();

        $this->assertResponseCode(500);
        $body = $this->getResponseBody();
        $this->assertFalse($body['success']);
        $this->assertSame('INTERNAL_ERROR', $body['error']);
    }
}
