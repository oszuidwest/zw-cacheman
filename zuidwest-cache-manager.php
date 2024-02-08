<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges cache for high-priority URLs immediately on post publishing and edits. Also queues associated taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 1.0
 * Author: Streekomroep ZuidWest
 */

// Load all files

require_once 'src/admin.php';
require_once 'src/cron.php';
require_once 'src/hooks.php';
require_once 'src/post.php';
require_once 'src/purge.php';
require_once 'src/utils.php';
