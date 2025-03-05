<?php
if (! defined('ABSPATH')) {
    exit;
}

namespace ZW_CACHEMAN_Core;

/**
 * Class CacheManager_Queue
 *
 * Handles queuing of URLs for low-priority processing.
 */
class CacheManager_Queue
{
    /**
     * Singleton instance.
     *
     * @var CacheManager_Queue
     */
    private static $instance;

    /**
     * Returns the singleton instance.
     *
     * @return CacheManager_Queue
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
        $all_urls = array_unique(array_merge($existing_urls, $urls));
        update_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, $all_urls);
    }
}
