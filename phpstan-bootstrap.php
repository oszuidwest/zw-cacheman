<?php
/**
 * PHPStan Bootstrap File - never loaded by WordPress.
 *
 * The OR-define pattern below satisfies WordPress plugin-check's direct-access
 * guard while still letting PHPStan execute the rest of this file (PHPStan
 * runs outside WordPress, so ABSPATH is not defined there).
 */

defined('ABSPATH') || define('ABSPATH', __DIR__);

// Plugin constants
define('ZW_CACHEMAN_DIR', __DIR__ . '/');
define('ZW_CACHEMAN_URL', 'https://example.com/wp-content/plugins/zw-cacheman/');
define('ZW_CACHEMAN_QUEUE', 'zw_cacheman_queue');
define('ZW_CACHEMAN_SETTINGS', 'zw_cacheman_settings');
define('ZW_CACHEMAN_CRON_HOOK', 'zw_cacheman_process_queue');