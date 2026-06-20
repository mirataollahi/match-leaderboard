<?php declare(strict_types=1);

namespace App\Service;

use Throwable;

/**
 * AppLogger Utility Class
 *
 * Provides static logging methods for application-wide logging
 */
class HyperLogger
{
    /**
     * Default log directory path relative to project root
     *
     * @var string
     */
    private static string $logDir = LOGS . 'app' . DS;

    /**
     * Default log file name pattern
     *
     * @var string
     */
    private static string $logFilePattern = 'app-%s.log';

    /**
     * Log level constants
     */
    private const string LEVEL_SUCCESS = 'SUCCESS';
    private const string LEVEL_WARNING = 'WARNING';
    private const string LEVEL_ERROR = 'ERROR';
    private const string LEVEL_INFO = 'INFO';
    private const string LEVEL_DEBUG = 'DEBUG';

    /**
     * Date format for log entries
     *
     * @var string
     */
    private static string $dateFormat = 'Y-m-d H:i:s';

    /**
     * Whether to include microseconds in timestamps
     *
     * @var bool
     */
    private static bool $includeMicroseconds = true;

    /**
     * Set custom log directory
     *
     * @param string $directory The directory path for log files
     * @return void
     */
    public static function setLogDirectory(string $directory): void
    {
        self::$logDir = rtrim($directory, '/\\') . DS;
    }

    /**
     * Set log file pattern
     *
     * @param string $pattern The sprintf pattern for log filenames (should contain one %s placeholder for date)
     * @return void
     */
    public static function setLogFilePattern(string $pattern): void
    {
        self::$logFilePattern = $pattern;
    }

    /**
     * Set date format for log entries
     *
     * @param string $format PHP date format string
     * @return void
     */
    public static function setDateFormat(string $format): void
    {
        self::$dateFormat = $format;
    }

    /**
     * Set whether to include microseconds in timestamps
     *
     * @param bool $include Whether to include microseconds
     * @return void
     */
    public static function setIncludeMicroseconds(bool $include): void
    {
        self::$includeMicroseconds = $include;
    }

    /**
     * Log a success message
     *
     * @param string $message The success message to log
     * @param array<string, mixed> $context Additional context data to include in the log
     * @return void
     */
    public static function success(string $message, array $context = []): void
    {
        self::write(self::LEVEL_SUCCESS, $message, $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message The warning message to log
     * @param array<string, mixed> $context Additional context data to include in the log
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error message
     *
     * @param string $message The error message to log
     * @param array<string, mixed> $context Additional context data to include in the log
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::write(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log an informational message
     *
     * @param string $message The info message to log
     * @param array<string, mixed> $context Additional context data to include in the log
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::write(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a debug message
     *
     * @param string $message The debug message to log
     * @param array<string, mixed> $context Additional context data to include in the log
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Generic log writing method
     *
     * @param string $message The message to log
     * @param array<string, mixed> $context Additional context data to include in the log
     * @return void
     */
    public static function log(string $message, array $context = []): void
    {
        self::write(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Write a log entry to file
     *
     * @param string $level The log level (SUCCESS, WARNING, ERROR, INFO, DEBUG)
     * @param string $message The message to write
     * @param array<string, mixed> $context Additional context data
     * @return void
     * @throws \RuntimeException If unable to create log directory
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        try {
            // Ensure log directory exists
            self::ensureLogDirectoryExists();

            // Build log file path with current date
            $date = date('Y-m-d');
            $logFile = self::$logDir . sprintf(self::$logFilePattern, $date);

            // Format timestamp
            $timestamp = self::getTimestamp();

            // Build log entry
            $logEntry = self::formatLogEntry($timestamp, $level, $message, $context);

            // Write to file
            file_put_contents(
                $logFile,
                $logEntry . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

        } catch (Throwable $e) {
            // Fallback to PHP error log if file logging fails
            error_log(
                sprintf(
                    'AppLogger failed to write log: %s [Original message: %s]',
                    $e->getMessage(),
                    $message
                )
            );
        }
    }

    /**
     * Get formatted timestamp
     *
     * @return string Formatted timestamp
     */
    private static function getTimestamp(): string
    {
        $now = new \DateTimeImmutable();
        $timestamp = $now->format(self::$dateFormat);

        if (self::$includeMicroseconds) {
            $timestamp .= '.' . $now->format('u');
        }

        return $timestamp;
    }

    /**
     * Format a log entry
     *
     * @param string $timestamp The timestamp for the log entry
     * @param string $level The log level
     * @param string $message The log message
     * @param array<string, mixed> $context Additional context data
     * @return string Formatted log entry
     */
    private static function formatLogEntry(
        string $timestamp,
        string $level,
        string $message,
        array  $context = []
    ): string
    {
        $entry = sprintf(
            '[%s] [%s] %s',
            $timestamp,
            str_pad($level, 7, ' ', STR_PAD_RIGHT),
            $message
        );

        // Add context data if present
        if (!empty($context)) {
            $entry .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Add request information if available
        if (PHP_SAPI !== 'cli') {
            $requestInfo = sprintf(
                ' | %s %s | IP: %s',
                $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                $_SERVER['REQUEST_URI'] ?? '/',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            );
            $entry .= $requestInfo;
        }

        return $entry;
    }

    /**
     * Ensure the log directory exists, creating it if necessary
     *
     * @return void
     * @throws \RuntimeException If directory cannot be created
     */
    private static function ensureLogDirectoryExists(): void
    {
        if (!is_dir(self::$logDir)) {
            if (!mkdir(self::$logDir, 0755, true) && !is_dir(self::$logDir)) {
                throw new \RuntimeException(
                    sprintf('Unable to create log directory: %s', self::$logDir)
                );
            }

            // Create .git keep to track directory in version control
            $gitKeep = self::$logDir . '.gitkeep';
            if (!file_exists($gitKeep)) {
                file_put_contents($gitKeep, '');
            }
        }

        // Verify directory is writable
        if (!is_writable(self::$logDir)) {
            throw new \RuntimeException(
                sprintf('Log directory is not writable: %s', self::$logDir)
            );
        }
    }

    /**
     * Clear all log files from the log directory
     *
     * @param int $olderThanDays Delete logs older than specified days (0 for all)
     * @return array{deleted: int, failed: array<string>} Number of deleted files and list of failures
     */
    public static function clearLogs(int $olderThanDays = 0): array
    {
        $deleted = 0;
        $failed = [];

        if (!is_dir(self::$logDir)) {
            return ['deleted' => 0, 'failed' => []];
        }

        $files = glob(self::$logDir . '*.log');

        if ($files === false) {
            return ['deleted' => 0, 'failed' => []];
        }

        foreach ($files as $file) {
            // Skip if filtering by age
            if ($olderThanDays > 0) {
                $fileModified = filemtime($file);
                if ($fileModified === false || $fileModified > strtotime("-{$olderThanDays} days")) {
                    continue;
                }
            }

            if (unlink($file)) {
                $deleted++;
            } else {
                $failed[] = basename($file);
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Get list of log files with their sizes
     *
     * @return array<string, array{size: int, modified: string}> Array of log files with details
     */
    public static function getLogFiles(): array
    {
        $files = [];

        if (!is_dir(self::$logDir)) {
            return $files;
        }

        $logFiles = glob(self::$logDir . '*.log');

        if ($logFiles === false) {
            return $files;
        }

        foreach ($logFiles as $file) {
            $filename = basename($file);
            $size = filesize($file);
            $modified = date('Y-m-d H:i:s', filemtime($file) ?: time());

            $files[$filename] = [
                'size' => $size !== false ? $size : 0,
                'modified' => $modified,
            ];
        }

        ksort($files);
        return $files;
    }
}
