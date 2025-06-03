<?php
/**
 * PHPStan Bootstrap File
 *
 * Defines constants that are normally defined in the main plugin file
 * to avoid PHPStan errors when analyzing individual files.
 */

// Plugin constants
define('ZW_CACHEMAN_DIR', __DIR__ . '/');
define('ZW_CACHEMAN_URL', 'https://example.com/wp-content/plugins/zuidwest-cache-manager/');
define('ZW_CACHEMAN_QUEUE', 'zw_cacheman_queue');
define('ZW_CACHEMAN_SETTINGS', 'zw_cacheman_settings');
define('ZW_CACHEMAN_CRON_HOOK', 'zw_cacheman_process_queue');