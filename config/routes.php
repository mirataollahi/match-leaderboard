<?php declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->setExtensions(['json']);

    /**
     *
     */
    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->get('/', ['controller' => 'Doc', 'action' => 'testPage']);

        // Match reporting
        $builder->post('/matches/report', ['controller' => 'Matches', 'action' => 'report']);

        // Leaderboard
        $builder->get('/leaderboard', ['controller' => 'Leaderboard', 'action' => 'index']);

        // Health check
        $builder->get('/health', ['controller' => 'Health', 'action' => 'index']);
    });

    /**
     * Swagger document pages routes
     */
    $routes->scope('/doc', function (RouteBuilder $builder): void {
        $builder->get('/', ['controller' => 'Doc', 'action' => 'index']);
        $builder->get('/spec', ['controller' => 'Doc', 'action' => 'spec']);
    })->setRouteClass(DashedRoute::class);
};
