<?php

/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges Cloudflare cache for high-priority URLs when posts are published, edited, or deleted. It also queues related taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 1.6
 * Author: Streekomroep ZuidWest
 * License: GPLv3
 * Requires PHP: 8.3
 * Text Domain: zw-cacheman
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define('ZW_CACHEMAN_DIR', plugin_dir_path(__FILE__));
define('ZW_CACHEMAN_URL', plugin_dir_url(__FILE__));
define('ZW_CACHEMAN_QUEUE', 'zw_cacheman_queue');
define('ZW_CACHEMAN_SETTINGS', 'zw_cacheman_settings');
define('ZW_CACHEMAN_CRON_HOOK', 'zw_cacheman_cron_hook');

// Include required files
require_once ZW_CACHEMAN_DIR . 'includes/enum-purge-type.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-logger.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-url-helper.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-api.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-url-delver.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-cache-manager.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-admin.php';

/**
 * Initialize the plugin on WordPress init
 *
 * @return void
 */
function zw_cacheman_init()
{
    // Get settings
    $settings = get_option(ZW_CACHEMAN_SETTINGS, [
        'zone_id' => '',
        'api_key' => '',
        'batch_size' => 30,
        'debug_mode' => false
    ]);

    // Create global plugin instances
    $logger = new ZW_CACHEMAN_Core\CachemanLogger(!empty($settings['debug_mode']));
    $url_helper = new ZW_CACHEMAN_Core\CachemanUrlHelper($logger);
    $api = new ZW_CACHEMAN_Core\CachemanAPI($url_helper, $logger);
    $url_delver = new ZW_CACHEMAN_Core\CachemanUrlDelver($url_helper, $logger);
    $manager = new ZW_CACHEMAN_Core\CachemanManager($api, $url_delver, $logger);

    // Only load admin interface in admin area
    if (is_admin()) {
        new ZW_CACHEMAN_Core\CachemanAdmin($manager, $api, $logger);
    }
}
add_action('init', 'zw_cacheman_init');

/**
 * Plugin activation hook
 *
 * @return void
 */
function zw_cacheman_activate()
{
    // Initialize default settings if they don't exist
    if (!get_option(ZW_CACHEMAN_SETTINGS)) {
        update_option(ZW_CACHEMAN_SETTINGS, [
            'zone_id' => '',
            'api_key' => '',
            'batch_size' => 30,
            'debug_mode' => false
        ]);
    }

    // Make sure cron is scheduled
    if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
        wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
    }

    // Create logs directory if it doesn't exist
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'zw-cacheman-logs/';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
}
register_activation_hook(__FILE__, 'zw_cacheman_activate');

/**
 * Plugin deactivation hook
 *
 * @return void
 */
function zw_cacheman_deactivate()
{
    // Clear any scheduled cron jobs
    $timestamp = wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, ZW_CACHEMAN_CRON_HOOK);
    }
}
register_deactivation_hook(__FILE__, 'zw_cacheman_deactivate');

/**
 * Plugin uninstall hook
 *
 * @return void
 */
function zw_cacheman_uninstall()
{
    // Clean up all plugin data
    delete_option(ZW_CACHEMAN_SETTINGS);
    delete_option(ZW_CACHEMAN_QUEUE);

    // Clean up log directory
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'zw-cacheman-logs/';

    if (is_dir($log_dir)) {
        $files = glob($log_dir . 'debug-*.log');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($log_dir);
    }
}
register_uninstall_hook(__FILE__, 'zw_cacheman_uninstall');

/**
 * Add custom cron schedule
 *
 * @param array $schedules Existing schedules.
 * @return array Modified schedules.
 */
function zw_cacheman_cron_schedules($schedules)
{
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every minute', 'zw-cacheman')
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'zw_cacheman_cron_schedules');
