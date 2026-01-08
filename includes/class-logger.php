<?php
/**
 * Logger functionality for ZuidWest Cache Manager.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

/**
 * Handles all logging functionality.
 */
class CachemanLogger
{
    /**
     * Whether debug mode is enabled.
     *
     * @var bool
     */
    private bool $debug_mode;

    /**
     * The log directory path.
     *
     * @var string
     */
    private readonly string $log_dir;

    /**
     * Constructor
     *
     * @param bool $debug_mode Whether debug mode is enabled.
     */
    public function __construct(bool $debug_mode = false)
    {
        $this->debug_mode = $debug_mode;

        // Set up log directory (inside wp-content/uploads).
        $upload_dir = wp_upload_dir();
        $this->log_dir = trailingslashit($upload_dir['basedir']) . 'zw-cacheman-logs/';

        // Create log directory if it doesn't exist.
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }

    /**
     * Update debug mode setting
     *
     * @param bool $debug_mode Whether debug mode is enabled.
     */
    public function set_debug_mode(bool $debug_mode): void
    {
        $this->debug_mode = $debug_mode;
    }

    /**
     * Log a debug message
     *
     * @param string $source The source of the log message.
     * @param string $message The message to log.
     */
    public function debug(string $source, string $message): void
    {
        if (!$this->debug_mode) {
            return;
        }

        $filename = $this->log_dir . 'debug-' . current_time('Y-m-d') . '.log';
        $timestamp = current_time('Y-m-d H:i:s');
        $log_message = '[' . $timestamp . '] [' . $source . '] ' . $message . PHP_EOL;

        // Append to the log file.
        file_put_contents($filename, $log_message, FILE_APPEND);
    }

    /**
     * Log an error message to PHP error log
     *
     * @param string $source The source of the log message.
     * @param string $message The error message to log.
     */
    public function error(string $source, string $message): void
    {
        // Always log errors to PHP error log.
        error_log('[ZW Cacheman ERROR] [' . $source . '] ' . $message);

        // Also log errors to our debug log if debug mode is enabled.
        if ($this->debug_mode) {
            $filename = $this->log_dir . 'debug-' . current_time('Y-m-d') . '.log';
            $timestamp = current_time('Y-m-d H:i:s');
            $log_message = '[' . $timestamp . '] [ERROR] [' . $source . '] ' . $message . PHP_EOL;

            // Append to the log file.
            file_put_contents($filename, $log_message, FILE_APPEND);
        }
    }

    /**
     * Get path to current debug log file
     *
     * @return string Path to current debug log file.
     */
    public function get_current_log_path(): string
    {
        return $this->log_dir . 'debug-' . current_time('Y-m-d') . '.log';
    }

    /**
     * Get list of available log files
     *
     * @param int $limit Limit the number of files returned.
     * @return array<string> Array of log file paths.
     */
    public function get_log_files(int $limit = 10): array
    {
        if (!is_dir($this->log_dir)) {
            return [];
        }

        $files = glob($this->log_dir . 'debug-*.log');
        if (!is_array($files)) {
            return [];
        }

        // Sort files by name descending (newest first).
        rsort($files);

        // Limit number of files returned.
        return array_slice($files, 0, $limit);
    }

    /**
     * Clear all log files
     *
     * @return bool True on success, false on failure.
     */
    public function clear_logs(): bool
    {
        if (!is_dir($this->log_dir)) {
            return false;
        }

        $files = glob($this->log_dir . 'debug-*.log');
        if (!is_array($files)) {
            return false;
        }

        $success = true;
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }
}
