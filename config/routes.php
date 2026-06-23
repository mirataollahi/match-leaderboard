<?php declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->setExtensions(['json']);

    /**
     * Api leaderboard routes
     */
    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->get('/', ['controller' => 'Doc', 'action' => 'testPage']);
        $builder->post('/matches/report',
            ['controller' => 'Matches', 'action' => 'report', 'csrf' => false]
        );
        $builder->get('/leaderboard', ['controller' => 'Leaderboard', 'action' => 'index']);
        $builder->get('/health', ['controller' => 'Health', 'action' => 'index']);
    });

    /**
     * Swagger document pages routes
     */
    $routes->scope('/doc', function (RouteBuilder $builder): void {
        $builder->get('/', ['controller' => 'Doc', 'action' => 'index']);
        $builder->get('/spec', ['controller' => 'Doc', 'action' => 'spec']);
    })->setRouteClass(DashedRoute::class);

    /**
     * The panel routes
     */
    $routes->scope('/panel', function (RouteBuilder $builder): void {
        $builder->get('/leaderboard', [
            'controller' => 'Panel',
            'action' => 'index',
        ]);
    });
};
