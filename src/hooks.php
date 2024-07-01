<?php

/**
 * Activates the plugin and schedules a WP-Cron event if not already scheduled.
 */
function zwcache_activate()
{
    if (!wp_next_scheduled(ZWCACHE_CRON_HOOK)) {
        wp_schedule_event(time(), 'every_minute', ZWCACHE_CRON_HOOK);
    }
    zwcache_debug_log('Plugin activated and cron job scheduled.');
}

/**
 * Deactivates the plugin, clears the scheduled WP-Cron event, and deletes the option storing low-priority URLs.
 */
function zwcache_deactivate()
{
    wp_clear_scheduled_hook(ZWCACHE_CRON_HOOK);
    delete_option(ZWCACHE_LOW_PRIORITY_STORE);
    zwcache_debug_log('Plugin deactivated and cron job cleared.');
}

register_activation_hook(__FILE__, 'zwcache_activate');
register_deactivation_hook(__FILE__, 'zwcache_deactivate');
