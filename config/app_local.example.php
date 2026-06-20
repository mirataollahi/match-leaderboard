<?php

use function Cake\Core\env;


return [

    'debug' => filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN),

    'Security' => [
        'salt' => env('SECURITY_SALT', 'e6ef00d49b8197575d6c91918c020b50173f38bccb58622d6fffd67dc5413850'),
    ],

    'Datasources' => [
        // Default postgres database configs
        'default' => [
            'className' => 'Cake\Database\Connection',
            'driver' => 'Cake\Database\Driver\Postgres',
            'persistent' => false,
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 5432),
            'username' => env('DB_USERNAME', 'match-score'),
            'password' => env('DB_PASSWORD', 'secret'),
            'database' => env('DB_DATABASE', 'match-score'),
            'schema' => 'public',
            'encoding' => 'utf8',
            'timezone' => 'UTC',
            'cacheMetadata' => true,
            'log' => false,
            'quoteIdentifiers' => false,
            'url' => env('DATABASE_URL', null),
        ],
    ],

    /*
     * Email configuration.
     */
    'EmailTransport' => [
        'default' => [
            'host' => 'localhost',
            'port' => 25,
            'username' => null,
            'password' => null,
            'client' => null,
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
    ],


    /*
    * Cache configuration, including for Redis.
    */
    'Cache' => [
        'redis' => [
            'className' => 'Cake\Cache\Engine\RedisEngine',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', 'secret'),
            'database' => env('REDIS_DB', 0),
            'duration' => '+1 hours',
            'prefix' => 'myapp_',
            // 'throwOnFailure' => true,
        ],
    ],

    /*
     * Session configuration, to store sessions in Redis.
     */
    'Session' => [
        'defaults' => 'cache',
        'handler' => [
            'config' => 'redis',
        ],
        'cookie' => 'myapp_session',
        'timeout' => 1440,
    ],
];
