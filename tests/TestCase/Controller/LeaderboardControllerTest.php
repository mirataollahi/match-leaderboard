<?php declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\LeaderboardService;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * LeaderboardControllerTest — integration tests for GET /leaderboard
 * @covers \App\Controller\LeaderboardController
 */
class LeaderboardControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Users',
    ];

    /** @var LeaderboardService&MockObject */
    private MockObject $leaderboardServiceMock;

    private const SAMPLE_LEADERBOARD = [
        'data' => [
            ['rank' => 1, 'user_id' => 7, 'name' => 'Alice', 'score' => 320],
            ['rank' => 2, 'user_id' => 12, 'name' => 'Bob', 'score' => 315],
            ['rank' => 3, 'user_id' => 5, 'name' => 'Charlie', 'score' => 290],
        ],
        'source' => 'redis',
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->leaderboardServiceMock = $this->createMock(LeaderboardService::class);
        $this->mockService(LeaderboardService::class, $this->leaderboardServiceMock);
    }

    /**
     * Helper to make a GET request to /leaderboard
     */
    private function getLeaderboard(int $limit = 10, int $offset = 0): array
    {
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->get("/leaderboard?limit={$limit}&offset={$offset}");

        return json_decode((string)$this->_response->getBody(), true) ?? [];
    }

    /** @test */
    public function testLeaderboardReturns200(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->with(10, 0)
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $this->getLeaderboard();

        $this->assertResponseCode(200);
    }

    /** @test */
    public function testLeaderboardResponseShape(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $body = $this->getLeaderboard();

        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('source', $body);
        $this->assertContains($body['source'], ['redis', 'sql']);
        $this->assertArrayHasKey('pagination', $body);
    }

    /** @test */
    public function testLeaderboardDataShape(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $body = $this->getLeaderboard();

        foreach ($body['data'] as $entry) {
            $this->assertArrayHasKey('rank', $entry);
            $this->assertArrayHasKey('user_id', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('score', $entry);
            $this->assertIsInt($entry['rank']);
            $this->assertIsInt($entry['user_id']);
            $this->assertIsString($entry['name']);
            $this->assertIsInt($entry['score']);
        }
    }

    /** @test */
    public function testLeaderboardIsSortedByScoreDesc(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $body = $this->getLeaderboard();
        $scores = array_column($body['data'], 'score');
        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores, 'Leaderboard must be sorted by score descending');
    }

    /** @test */
    public function testLeaderboardRanksAreSequential(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $body = $this->getLeaderboard(5, 0);

        foreach ($body['data'] as $i => $entry) {
            $this->assertSame($i + 1, $entry['rank']);
        }
    }

    /** @test */
    public function testLeaderboardPaginationMeta(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->with(3, 0)
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $body = $this->getLeaderboard(3, 0);

        $this->assertSame(3, $body['pagination']['limit']);
        $this->assertSame(0, $body['pagination']['offset']);
    }

    /** @test */
    public function testLeaderboardSecondPageRanksStartAtOffset(): void
    {
        $secondPageData = [
            'data' => [
                ['rank' => 4, 'user_id' => 3, 'name' => 'Dave', 'score' => 280],
                ['rank' => 5, 'user_id' => 8, 'name' => 'Eve', 'score' => 275],
            ],
            'source' => 'redis',
        ];

        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->with(3, 3)
            ->willReturn($secondPageData);

        $body = $this->getLeaderboard(3, 3);

        if (!empty($body['data'])) {
            $this->assertSame(4, $body['data'][0]['rank']);
        }
    }

    /** @test */
    public function testLeaderboardWithRedisSource(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn([
                'data' => self::SAMPLE_LEADERBOARD['data'],
                'source' => 'redis',
            ]);

        $body = $this->getLeaderboard();

        $this->assertSame('redis', $body['source']);
    }

    /** @test */
    public function testLeaderboardWithSqlFallback(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn([
                'data' => self::SAMPLE_LEADERBOARD['data'],
                'source' => 'sql',
            ]);

        $body = $this->getLeaderboard();

        $this->assertSame('sql', $body['source']);
    }

    /** @test */
    public function testLeaderboardWithEmptyData(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn([
                'data' => [],
                'source' => 'redis',
            ]);

        $body = $this->getLeaderboard();

        $this->assertTrue($body['success']);
        $this->assertEmpty($body['data']);
    }

    /** @test */
    public function testLimitBelow1Returns422(): void
    {
        $body = $this->getLeaderboard(0, 0);

        $this->assertResponseCode(422);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Limit', $body['message']);
    }

    /** @test */
    public function testLimitAbove100Returns422(): void
    {
        $body = $this->getLeaderboard(101, 0);

        $this->assertResponseCode(422);
        $this->assertFalse($body['success']);
    }

    /** @test */
    public function testNegativeOffsetReturns422(): void
    {
        $body = $this->getLeaderboard(10, -1);

        $this->assertResponseCode(422);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Offset', $body['message']);
    }

    /** @test */
    public function testDefaultParametersUsed(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->with(10, 0)
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);

        // Request without query parameters
        $this->get('/leaderboard');

        $this->assertResponseCode(200);
    }

    /** @test */
    public function testStringLimitParameterCastToInt(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->with(5, 0)
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $body = $this->getLeaderboard(5, 0);

        $this->assertResponseCode(200);
    }

    /** @test */
    public function testPostMethodReturns405(): void
    {
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->post('/leaderboard');

        $this->assertResponseCode(405);
    }

    /** @test */
    public function testPutMethodReturns405(): void
    {
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->put('/leaderboard');

        $this->assertResponseCode(405);
    }

    /** @test */
    public function testJsonContentTypeHeader(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $this->getLeaderboard();

        $this->assertContentType('application/json');
    }

    /** @test */
    public function testNonJsonAcceptHeaderStillReturnsJson(): void
    {
        $this->configRequest([
            'headers' => ['Accept' => 'text/html'],
        ]);

        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(self::SAMPLE_LEADERBOARD);

        $this->get('/leaderboard?limit=10&offset=0');

        $this->assertResponseCode(200);
        // Should still return JSON as per API contract
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertIsArray($body);
    }

    /** @test */
    public function testServerErrorReturns500(): void
    {
        $this->leaderboardServiceMock
            ->expects($this->once())
            ->method('getLeaderboard')
            ->willThrowException(new \RuntimeException('Redis connection failed'));

        $body = $this->getLeaderboard();

        $this->assertResponseCode(500);
        $this->assertFalse($body['success']);
        $this->assertSame('INTERNAL_ERROR', $body['error']);
    }
}
