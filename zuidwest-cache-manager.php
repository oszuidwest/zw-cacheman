<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges cache for high-priority URLs immediately on post publishing and edits. Also queues associated taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 1.0
 * Author: Streekomroep ZuidWest
 */


// Constants
define('ZWCACHE_LOW_PRIORITY_STORE', 'zwcache_purge_urls');
define('ZWCACHE_CRON_HOOK', 'zwcache_manager_cron_hook');

// Load all files
require_once 'src/admin.php';
require_once 'src/cron.php';
require_once 'src/hooks.php';
require_once 'src/post.php';
require_once 'src/purge.php';
require_once 'src/utils.php';
