<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges Cloudflare cache for high-priority URLs when posts are published or edited. It also queues related taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 1.0
 * Author: Streekomroep ZuidWest
 * License: GPLv3
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
require_once ZW_CACHEMAN_DIR . 'includes/class-api.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-cache-manager.php';
require_once ZW_CACHEMAN_DIR . 'includes/class-admin.php';

/**
 * Initialize the plugin on WordPress init
 *
 * @return void
 */
function zw_cacheman_init()
{
    // Create global plugin instances
    $api = new ZW_CACHEMAN_Core\CachemanAPI();
    $manager = new ZW_CACHEMAN_Core\CachemanManager($api);

    // Only load admin interface in admin area
    if (is_admin()) {
        new ZW_CACHEMAN_Core\CachemanAdmin($manager, $api);
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
