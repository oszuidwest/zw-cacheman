<?php

/**
 * Adds custom cron schedule if it doesn't already exist.
 *
 * @param array $schedules The existing schedules.
 * @return array The modified schedules.
 */
function zwcache_add_cron_interval($schedules)
{
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => esc_html__('Every minute'),
        ];
    }
    return $schedules;
}

add_filter('cron_schedules', 'zwcache_add_cron_interval');