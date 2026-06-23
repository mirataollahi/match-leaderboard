<?php declare(strict_types=1);

/**
 * Database Wipe Script - PostgreSQL + Redis
 *
 * Usage: php wipe.php [--force] [--skip-redis] [--help]
 *
 * ⚠️  WARNING: This script permanently deletes ALL data from the database and Redis.
 *     Use with extreme caution in production environments!
 */

// Database configuration
$dbConfig = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '54324',
    'dbname' => getenv('DB_DATABASE') ?: 'match-score',
    'username' => getenv('DB_USERNAME') ?: 'match-score',
    'password' => getenv('DB_PASSWORD') ?: '3SI94b2qgt8Rg7prq4wP2oxn',
];

// Redis configuration
$redisConfig = [
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: null,
    'database' => (int)(getenv('REDIS_DATABASE') ?: 0),
    'prefix' => getenv('REDIS_PREFIX') ?: 'lb:',
];

class DatabaseWiper
{
    private PDO $pdo;
    private ?Redis $redis;
    private string $redisPrefix;
    private bool $redisAvailable = false;

    // Options
    private bool $forceMode;
    private bool $skipRedis;

    public function __construct(array $dbConfig, array $redisConfig, array $options = [])
    {
        $this->forceMode = (bool)($options['force'] ?? false);
        $this->skipRedis = (bool)($options['skip_redis'] ?? false);

        // Connect to PostgreSQL
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['dbname']
        );

        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Connect to Redis
        $this->redisPrefix = $redisConfig['prefix'];
        $this->connectRedis($redisConfig);
    }

    private function connectRedis(array $cfg): void
    {
        if ($this->skipRedis) {
            echo "⏭  Skipping Redis connection.\n";
            return;
        }

        try {
            $this->redis = new Redis();

            $connected = $this->redis->connect(
                $cfg['host'],
                $cfg['port'],
                4.0
            );

            if (!$connected) {
                throw new RedisException('Failed to connect to Redis');
            }

            if (!empty($cfg['password'])) {
                $this->redis->auth($cfg['password']);
            }

            $this->redis->select($cfg['database']);
            $this->redis->ping();

            $this->redisAvailable = true;
            echo "✓ Redis connected successfully.\n";
        } catch (RedisException $e) {
            $this->redis = null;
            $this->redisAvailable = false;
            echo "⚠ Redis unavailable: " . $e->getMessage() . "\n";
            echo "  Will wipe database only.\n";
        }
    }

    public function run(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════╗\n";
        echo "║  🗑  DATABASE WIPE SCRIPT                ║\n";
        echo "║  ⚠️  THIS WILL DELETE ALL DATA!          ║\n";
        echo "╚══════════════════════════════════════════╝\n\n";

        // Show what will be wiped
        $this->showCurrentState();

        // Confirmation
        if (!$this->forceMode) {
            echo "\n⚠️  WARNING: This will permanently delete ALL data!\n";
            echo "Type 'DELETE' (all caps) to confirm: ";

            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            fclose($handle);

            if ($line !== 'DELETE') {
                echo "\n❌ Aborted. Data is safe.\n";
                return;
            }
        }

        echo "\n🔄 Starting wipe process...\n\n";

        try {
            $this->pdo->beginTransaction();

            // ── Step 1: Get table information ─────────────────
            echo "📊 Analyzing database schema...\n";
            $tables = $this->getTables();
            $sequences = $this->getSequences();

            echo "   Found " . count($tables) . " tables and " . count($sequences) . " sequences.\n\n";

            // ── Step 2: Wipe Database Tables ──────────────────
            echo "🗑  Wiping database tables...\n";
            $this->wipeTables($tables);
            echo "   ✓ All tables cleared.\n\n";

            // ── Step 3: Reset Sequences ───────────────────────
            echo "🔄 Resetting sequences...\n";
            $this->resetSequences($sequences);
            echo "   ✓ All sequences reset to 1.\n\n";

            // ── Step 4: Verify Wipe ───────────────────────────
            echo "✅ Verifying wipe...\n";
            $this->verifyWipe($tables);

            // ── Step 5: Wipe Redis ────────────────────────────
            if ($this->redisAvailable) {
                echo "\n🔴 Wiping Redis data...\n";
                $this->wipeRedis();
            }

            $this->pdo->commit();

            echo "\n╔══════════════════════════════════════════╗\n";
            echo "║  ✅ WIPE COMPLETE                         ║\n";
            echo "║  All data has been permanently deleted.   ║\n";
            echo "╚══════════════════════════════════════════╝\n\n";

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "\n❌ Wipe failed: " . $e->getMessage() . "\n";
            echo "   Database has been rolled back to previous state.\n";
            throw $e;
        }
    }

    /**
     * Get all user tables (excluding system tables)
     */
    private function getTables(): array
    {
        $stmt = $this->pdo->query("
            SELECT tablename
            FROM pg_catalog.pg_tables
            WHERE schemaname = 'public'
            ORDER BY tablename
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all sequences
     */
    private function getSequences(): array
    {
        $stmt = $this->pdo->query("
            SELECT sequencename
            FROM pg_sequences
            WHERE schemaname = 'public'
            ORDER BY sequencename
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Show current database state before wiping
     */
    private function showCurrentState(): void
    {
        echo "📊 Current Database State:\n";
        echo str_repeat("─", 40) . "\n";

        $tables = $this->getTables();

        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) AS count FROM \"{$table}\"");
                $count = $stmt->fetch()['count'];
                echo sprintf("  %-30s %d rows\n", $table, $count);
            } catch (Exception $e) {
                echo sprintf("  %-30s Error: %s\n", $table, $e->getMessage());
            }
        }

        if ($this->redisAvailable) {
            try {
                $keys = $this->redis->keys($this->redisPrefix . '*');
                echo sprintf("  %-30s %d keys\n", "Redis (prefix: {$this->redisPrefix})", count($keys));
            } catch (Exception $e) {
                echo "  Redis: Connection error\n";
            }
        }
    }

    /**
     * Wipe all tables using TRUNCATE
     */
    private function wipeTables(array $tables): void
    {
        if (empty($tables)) {
            echo "   No tables to wipe.\n";
            return;
        }

        // Disable triggers temporarily for faster truncate
        $this->pdo->exec('SET session_replication_role = replica;');

        // Build TRUNCATE statement with all tables
        $tableNames = array_map(function($table) {
            return '"' . $table . '"';
        }, $tables);

        $sql = 'TRUNCATE TABLE ' . implode(', ', $tableNames) . ' CASCADE';
        $this->pdo->exec($sql);

        // Re-enable triggers
        $this->pdo->exec('SET session_replication_role = DEFAULT;');

        echo "   Truncated " . count($tables) . " tables with CASCADE.\n";
    }

    /**
     * Reset all sequences to start from 1
     */
    private function resetSequences(array $sequences): void
    {
        if (empty($sequences)) {
            echo "   No sequences to reset.\n";
            return;
        }

        foreach ($sequences as $sequence) {
            try {
                $this->pdo->exec("ALTER SEQUENCE \"{$sequence}\" RESTART WITH 1");
                echo "   ✓ Reset sequence: {$sequence}\n";
            } catch (Exception $e) {
                echo "   ⚠ Failed to reset sequence {$sequence}: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Verify that all tables are empty
     */
    private function verifyWipe(array $tables): void
    {
        $allEmpty = true;

        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) AS count FROM \"{$table}\"");
                $count = (int)$stmt->fetch()['count'];

                if ($count > 0) {
                    echo "   ⚠ Table '{$table}' still has {$count} rows!\n";
                    $allEmpty = false;
                } else {
                    echo "   ✓ Table '{$table}' is empty\n";
                }
            } catch (Exception $e) {
                echo "   ⚠ Could not verify table '{$table}': " . $e->getMessage() . "\n";
            }
        }

        if (!$allEmpty) {
            echo "   ⚠ Some tables were not fully wiped.\n";
        }
    }

    /**
     * Wipe all Redis keys with the application prefix
     */
    private function wipeRedis(): void
    {
        if (!$this->redisAvailable) {
            echo "   ⚠ Redis is not available.\n";
            return;
        }

        try {
            // Get all keys with the application prefix
            $keys = $this->redis->keys($this->redisPrefix . '*');

            if (empty($keys)) {
                echo "   No Redis keys found with prefix '{$this->redisPrefix}'.\n";
                return;
            }

            // Delete all keys
            $deletedCount = $this->redis->del($keys);

            echo "   ✓ Deleted {$deletedCount} Redis keys.\n";

            // Also clean up any idempotency keys
            $idemKeys = $this->redis->keys('idem:*');
            if (!empty($idemKeys)) {
                $idemCount = $this->redis->del($idemKeys);
                echo "   ✓ Deleted {$idemCount} idempotency keys.\n";
            }

            // Clean rate limit keys
            $rlKeys = $this->redis->keys('rl:*');
            if (!empty($rlKeys)) {
                $rlCount = $this->redis->del($rlKeys);
                echo "   ✓ Deleted {$rlCount} rate limit keys.\n";
            }

            // Verify Redis is clean
            $remainingKeys = $this->redis->keys($this->redisPrefix . '*');
            if (count($remainingKeys) > 0) {
                echo "   ⚠ Warning: " . count($remainingKeys) . " keys still remain.\n";
            }

        } catch (RedisException $e) {
            echo "   ❌ Redis wipe failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Print usage information
     */
    public static function printHelp(): void
    {
        echo "Database Wipe Script - PostgreSQL + Redis\n";
        echo "Usage: php wipe.php [options]\n\n";
        echo "Options:\n";
        echo "  --force        Skip confirmation prompt (dangerous!)\n";
        echo "  --skip-redis   Only wipe database, skip Redis\n";
        echo "  --help         Show this help message\n\n";
        echo "Examples:\n";
        echo "  php wipe.php                  # Interactive mode with confirmation\n";
        echo "  php wipe.php --force          # Skip confirmation\n";
        echo "  php wipe.php --skip-redis     # Wipe only database\n\n";
        echo "⚠️  WARNING: This script permanently deletes ALL data!\n";
        echo "    Make sure you have backups before running in production.\n";
    }
}

// ── Parse command line arguments ────────────────────────────
$options = [
    'force' => false,
    'skip_redis' => false,
];

foreach ($argv as $arg) {
    if ($arg === '--help') {
        DatabaseWiper::printHelp();
        exit(0);
    }
    if ($arg === '--force') {
        $options['force'] = true;
    }
    if ($arg === '--skip-redis') {
        $options['skip_redis'] = true;
    }
}

// ── Execute the wiper ──────────────────────────────────────
try {
    $wiper = new DatabaseWiper($dbConfig, $redisConfig, $options);
    $wiper->run();
} catch (Exception $e) {
    echo "\nFatal error: " . $e->getMessage() . "\n";
    exit(1);
}
