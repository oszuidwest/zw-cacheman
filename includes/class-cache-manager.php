<?php
/**
 * Core Cache Manager functionality
 */

namespace ZW_CACHEMAN_Core;

/**
 * Handles the core cache purging functionality
 */
class CachemanManager
{
    /**
     * API instance
     *
     * @var CachemanAPI
     */
    private $api;

    /**
     * URL Delver instance
     *
     * @var CachemanUrlDelver
     */
    private $url_delver;

    /**
     * Constructor
     *
     * @param CachemanAPI       $api       The API handler instance.
     * @param CachemanUrlDelver $url_delver The URL delver instance.
     */
    public function __construct($api, $url_delver)
    {
        $this->api = $api;
        $this->url_delver = $url_delver;

        // Hook into post status transitions
        add_action('transition_post_status', [$this, 'handle_post_status_change'], 10, 3);

        // Set up cron handler
        add_action(ZW_CACHEMAN_CRON_HOOK, [$this, 'process_queue']);

        // Check if cron is scheduled
        if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
            wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
            $this->debug_log('Scheduled missing cron job.');
        }
    }

    /**
     * Debug logging
     *
     * @param string $message The message to log.
     * @return void
     */
    public function debug_log($message)
    {
        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        if (!empty($settings['debug_mode'])) {
            error_log('[ZW Cacheman] ' . $message);
        }
    }

    /**
     * Handle post status changes
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post Post object.
     * @return void
     */
    public function handle_post_status_change($new_status, $old_status, $post)
    {
        // Skip if autosave or revision
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return;
        }

        $this->debug_log('Post ' . $post->ID . ' status changed from ' . $old_status . ' to ' . $new_status);

        // Only process on publish/unpublish
        if ('publish' === $new_status || 'publish' === $old_status) {
            // High priority purge items to process immediately
            $high_priority_items = $this->url_delver->get_high_priority_purge_items($post);
            $this->api->process_purge_items($high_priority_items);

            // Queue low priority items for later processing
            $low_priority_items = $this->url_delver->get_low_priority_purge_items($post);
            $this->queue_purge_items($low_priority_items);
        }
    }

    /**
     * Queue purge items for later processing
     *
     * @param array $purge_items Items to add to the queue
     * @return void
     */
    public function queue_purge_items($purge_items)
    {
        if (empty($purge_items)) {
            return;
        }

        $existing_items = get_option(ZW_CACHEMAN_QUEUE, []);

        // Combine and deduplicate based on URL and type
        $all_items = [];
        $unique_keys = [];

        // Process existing items first
        foreach ($existing_items as $item) {
            $key = $item['type'] . '|' . $item['url'];
            if (!isset($unique_keys[$key])) {
                $unique_keys[$key] = true;
                $all_items[] = $item;
            }
        }

        // Add new items if not already in queue
        foreach ($purge_items as $item) {
            $key = $item['type'] . '|' . $item['url'];
            if (!isset($unique_keys[$key])) {
                $unique_keys[$key] = true;
                $all_items[] = $item;
            }
        }

        update_option(ZW_CACHEMAN_QUEUE, $all_items);

        $this->debug_log('Added ' . count($purge_items) . ' purge items to queue. Total in queue: ' . count($all_items));
    }

    /**
     * Process the queue - called by WP-Cron
     *
     * @return void
     */
    public function process_queue()
    {
        $queue = get_option(ZW_CACHEMAN_QUEUE, []);
        if (empty($queue)) {
            $this->debug_log('Queue is empty. Nothing to process.');
            return;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        $batch_size = !empty($settings['batch_size']) ? (int)$settings['batch_size'] : 30;

        // Take a batch of items from the queue
        $items_to_process = array_slice($queue, 0, $batch_size);
        $remaining_items = array_slice($queue, $batch_size);

        $this->debug_log('Processing ' . count($items_to_process) . ' items from queue');

        // Process the batch using the API's process_purge_items method
        $success = $this->api->process_purge_items($items_to_process);

        if ($success) {
            // Update the queue with remaining items
            update_option(ZW_CACHEMAN_QUEUE, $remaining_items);
            $this->debug_log('Successfully processed batch. ' . count($remaining_items) . ' items remaining in queue.');
        } else {
            $this->debug_log('Failed to process batch. Will retry next run.');
        }

        // Ensure WP-Cron is still scheduled
        if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
            wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
            $this->debug_log('Re-scheduled missing cron job.');
        }
    }
}
