<?php declare(strict_types=1);

namespace App;

use App\Middleware\HostHeaderMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Repository\MatchReportRepository\MatchReportRepository;
use App\Repository\MatchReportRepository\MatchReportRepositoryInterface;
use App\Repository\ScoreRepository\ScoreRepository;
use App\Repository\ScoreRepository\ScoreRepositoryInterface;
use App\Repository\TrophyHistoryRepository\TrophyHistoryRepository;
use App\Repository\TrophyHistoryRepository\TrophyHistoryRepositoryInterface;
use App\Repository\UserRepository\UserRepository;
use App\Repository\UserRepository\UserRepositoryInterface;
use App\Service\LeaderboardService;
use App\Service\MatchReportService;
use App\Service\MatchReportValidator;
use App\Service\RedisService;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;

class Application extends BaseApplication
{
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        FactoryLocator::add('Table', (new TableLocator())->allowFallbackClass(false));
    }

    /**
     * Set up the middleware queue your application will use.
     *
     * @param MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            // Catch any exceptions in the lower layers, and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Validate Host header to prevent Host Header Injection attacks.
            ->add(new HostHeaderMiddleware())

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))

            // Add routing middleware. If you have a large number of routes connected, turning on routes
            ->add(new RoutingMiddleware($this))

            // Parse various types of encoded request bodies so that they are
            ->add(new BodyParserMiddleware())

            // Rate limiting (Redis-backed; fail-open if Redis is down)
            ->add(new RateLimitMiddleware(new RedisService()))

            // Cross Site Request Forgery (CSRF) Protection Middleware
            ->add(new CsrfProtectionMiddleware([
                'httponly' => true,
            ]));

        return $middlewareQueue;
    }

    /**
     * Register application container services.
     *
     * @param ContainerInterface $container The Container to update.
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        $container->add(MatchReportValidator::class);
        $container->add(RedisService::class);

        $container->add(MatchReportRepository::class)
            ->addArgument(MatchReportRepositoryInterface::class);

        $container->add(ScoreRepository::class)
            ->addArgument(ScoreRepositoryInterface::class);

        $container->add(TrophyHistoryRepository::class)
            ->addArgument(TrophyHistoryRepositoryInterface::class);

        $container->add(UserRepository::class)
            ->addArgument(UserRepositoryInterface::class);

        $container->add(MatchReportService::class)
            ->addArgument(UserRepositoryInterface::class)
            ->addArgument(MatchReportRepositoryInterface::class)
            ->addArgument(TrophyHistoryRepositoryInterface::class)
            ->addArgument(RedisService::class);

        $container->add(LeaderboardService::class)
            ->addArgument(UserRepository::class)
            ->addArgument(RedisService::class);
    }

    /**
     * Register custom event listeners here
     *
     * @param EventManagerInterface $eventManager
     * @return EventManagerInterface
     */
    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        // $eventManager->on(new SomeCustomListenerClass());

        return $eventManager;
    }
}
