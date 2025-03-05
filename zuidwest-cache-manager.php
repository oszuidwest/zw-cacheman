<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges Cloudflare cache for high-priority URLs when posts are published or edited. It also queues related taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 0.9
 * Author: Streekomroep ZuidWest
 * License: GPLv3
 * Text Domain: zw-cacheman
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
define('ZW_CACHEMAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZW_CACHEMAN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZW_CACHEMAN_PLUGIN_FILE', __FILE__);
define('ZW_CACHEMAN_LOW_PRIORITY_STORE', 'zw_cacheman_purge_urls');
define('ZW_CACHEMAN_CRON_HOOK', 'zw_cacheman_manager_cron_hook');

// Load plugin text domain for translations.
function zw_cacheman_load_textdomain()
{
    load_plugin_textdomain('zw-cacheman', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'zw_cacheman_load_textdomain');

// Include required files.
require_once ZW_CACHEMAN_PLUGIN_DIR . 'includes/class-cachemanager.php';
require_once ZW_CACHEMAN_PLUGIN_DIR . 'includes/class-cachemanager-api.php';
require_once ZW_CACHEMAN_PLUGIN_DIR . 'includes/class-cachemanager-url-resolver.php';
require_once ZW_CACHEMAN_PLUGIN_DIR . 'includes/class-cachemanager-queue.php';
require_once ZW_CACHEMAN_PLUGIN_DIR . 'includes/class-cachemanager-admin.php';

// Initialize the core cache manager and admin functionality.
function zw_cacheman_init()
{
    \ZW_CACHEMAN_Core\CacheManager::get_instance();
    if (is_admin()) {
        new \ZW_CACHEMAN_Core\CacheManagerAdmin();
    }
}
add_action('plugins_loaded', 'zw_cacheman_init');
