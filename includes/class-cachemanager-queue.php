<?php

namespace ZW_CACHEMAN_Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class CacheManagerQueue
 *
 * Handles queuing of URLs for low-priority processing.
 */
class CacheManagerQueue
{
    /**
     * Singleton instance.
     *
     * @var CacheManagerQueue
     */
    private static $instance;

    /**
     * Returns the singleton instance.
     *
     * @return CacheManagerQueue
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Queues URLs for later processing.
     *
     * @param array $urls URLs to queue.
     */
    public function queue_low_priority_urls($urls)
    {
        $existing_urls = get_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, []);
        $all_urls      = array_unique(array_merge($existing_urls, $urls));
        update_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, $all_urls);
    }
}
