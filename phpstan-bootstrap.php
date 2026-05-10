<?php
/**
 * PHPStan bootstrap file - never loaded by WordPress.
 *
 * The canonical ABSPATH guard satisfies WordPress plugin-check's direct-access
 * rule. PHPStan can still execute the rest of this file because
 * szepeviktor/phpstan-wordpress runtime-defines ABSPATH in its extension
 * bootstrap, so the guard short-circuits during analysis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'ZW_CACHEMAN_DIR', __DIR__ . '/' );
define( 'ZW_CACHEMAN_URL', 'https://example.com/wp-content/plugins/zw-cacheman/' );
define( 'ZW_CACHEMAN_QUEUE', 'zw_cacheman_queue' );
define( 'ZW_CACHEMAN_SETTINGS', 'zw_cacheman_settings' );
define( 'ZW_CACHEMAN_CRON_HOOK', 'zw_cacheman_process_queue' );
