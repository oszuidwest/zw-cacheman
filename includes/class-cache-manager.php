<?php

/**
 * Core Cache Manager functionality.
 */

namespace ZW_CACHEMAN_Core;

/**
 * Handles the core cache purging functionality.
 */
readonly class CachemanManager
{
    /**
     * Constructor
     *
     * @param CachemanAPI       $api       The API handler instance.
     * @param CachemanUrlDelver $url_delver The URL delver instance.
     * @param CachemanLogger    $logger     The logger instance.
     */
    public function __construct(
        private CachemanAPI $api,
        private CachemanUrlDelver $url_delver,
        private CachemanLogger $logger
    ) {
        // Hook into post status transitions
        add_action('transition_post_status', $this->handle_post_status_change(...), 10, 3);

        // Hook into post deletion
        add_action('before_delete_post', $this->handle_post_deletion(...), 10, 2);

        // Hook into taxonomy term changes
        add_action('created_term', $this->handle_term_change(...), 10, 3);
        add_action('edited_term', $this->handle_term_change(...), 10, 3);
        add_action('delete_term', $this->handle_term_deletion(...), 10, 3);

        // Set up cron handler
        add_action(ZW_CACHEMAN_CRON_HOOK, $this->process_queue(...));

        // Check if cron is scheduled
        if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
            wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
            $this->logger->debug('Manager', 'Scheduled missing cron job');
        }

        // Add debug logging for cron scheduling checks (WP 6.8+)
        add_filter('wp_next_scheduled', $this->log_cron_check(...), 10, 3);
    }

    /**
     * Log cron scheduling checks for debugging (WP 6.8+)
     *
     * @param int|false            $timestamp  Unix timestamp of next scheduled event, or false.
     * @param object|null          $next_event The next scheduled event object.
     * @param string               $hook       The hook name being checked.
     * @return int|false The unmodified timestamp.
     */
    public function log_cron_check(int|false $timestamp, ?object $next_event, string $hook): int|false
    {
        // Only log checks for our own cron hook, and only once per request
        static $logged = false;

        if ($hook === ZW_CACHEMAN_CRON_HOOK && !$logged) {
            $logged = true;

            if ($timestamp) {
                $this->logger->debug(
                    'Cron',
                    'Next scheduled run: ' . gmdate('Y-m-d H:i:s', $timestamp) . ' UTC (in ' . ($timestamp - time()) . ' seconds)'
                );
            } else {
                $this->logger->debug('Cron', 'Hook not scheduled');
            }
        }

        return $timestamp;
    }

    /**
     * Handle post status changes
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post Post object.
     */
    public function handle_post_status_change(string $new_status, string $old_status, \WP_Post $post): void
    {
        // Skip if autosave or revision
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return;
        }

        $this->logger->debug('Manager', 'Post ' . $post->ID . ' (' . $post->post_title . ') status changed from ' . $old_status . ' to ' . $new_status);

        // Only process on publish/unpublish
        if ('publish' === $new_status || 'publish' === $old_status) {
            // High priority purge items to process immediately
            $high_priority_items = $this->url_delver->get_high_priority_purge_items($post);

            if (!empty($high_priority_items)) {
                $this->logger->debug('Manager', 'Processing ' . count($high_priority_items) . ' high priority purge items for post ID ' . $post->ID);
                $result = $this->api->process_purge_items($high_priority_items);

                if (!$result) {
                    $this->logger->error('Manager', 'Failed to process high priority purge items for post ID ' . $post->ID);
                }
            } else {
                $this->logger->debug('Manager', 'No high priority purge items found for post ID ' . $post->ID);
            }

            // Queue low priority items for later processing
            $low_priority_items = $this->url_delver->get_low_priority_purge_items($post);

            if (!empty($low_priority_items)) {
                $this->logger->debug('Manager', 'Queueing ' . count($low_priority_items) . ' low priority purge items for post ID ' . $post->ID);
                $this->queue_purge_items($low_priority_items);
            } else {
                $this->logger->debug('Manager', 'No low priority purge items found for post ID ' . $post->ID);
            }
        }
    }

    /**
     * Handle post deletion
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function handle_post_deletion(int $post_id, \WP_Post $post): void
    {
        // Skip if autosave or revision
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return;
        }

        $this->logger->debug('Manager', 'Post ' . $post_id . ' (' . $post->post_title . ') was deleted');

        // Get purge items for a deleted post
        $purge_items = $this->url_delver->get_deleted_post_purge_items($post_id, $post);

        if (!empty($purge_items)) {
            $this->logger->debug('Manager', 'Processing ' . count($purge_items) . ' purge items for deleted post ID ' . $post_id);
            $result = $this->api->process_purge_items($purge_items);

            if (!$result) {
                $this->logger->error('Manager', 'Failed to process purge items for deleted post ID ' . $post_id);
            }
        } else {
            $this->logger->debug('Manager', 'No purge items found for deleted post ID ' . $post_id);
        }
    }

    /**
     * Handle taxonomy term changes (create/edit)
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     */
    public function handle_term_change(int $term_id, int $tt_id, string $taxonomy): void
    {
        $this->logger->debug('Manager', 'Term ' . $term_id . ' in taxonomy ' . $taxonomy . ' was created or updated');

        // Get the term
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term)) {
            $this->logger->error('Manager', 'Failed to get term: ' . $term->get_error_message());
            return;
        }

        // High priority purge items to process immediately
        $high_priority_items = $this->url_delver->get_high_priority_term_purge_items($term, $taxonomy);

        if (!empty($high_priority_items)) {
            $this->logger->debug('Manager', 'Processing ' . count($high_priority_items) . ' high priority purge items for term ID ' . $term_id);
            $result = $this->api->process_purge_items($high_priority_items);

            if (!$result) {
                $this->logger->error('Manager', 'Failed to process high priority purge items for term ID ' . $term_id);
            }
        } else {
            $this->logger->debug('Manager', 'No high priority purge items found for term ID ' . $term_id);
        }

        // Queue low priority items for later processing
        $low_priority_items = $this->url_delver->get_low_priority_term_purge_items($term, $taxonomy);

        if (!empty($low_priority_items)) {
            $this->logger->debug('Manager', 'Queueing ' . count($low_priority_items) . ' low priority purge items for term ID ' . $term_id);
            $this->queue_purge_items($low_priority_items);
        } else {
            $this->logger->debug('Manager', 'No low priority purge items found for term ID ' . $term_id);
        }
    }

    /**
     * Handle taxonomy term deletion
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     */
    public function handle_term_deletion(int $term_id, int $tt_id, string $taxonomy): void
    {
        $this->logger->debug('Manager', 'Term ' . $term_id . ' in taxonomy ' . $taxonomy . ' was deleted');

        // Get purge items for a deleted term
        $purge_items = $this->url_delver->get_deleted_term_purge_items($term_id, $taxonomy);

        if (!empty($purge_items)) {
            $this->logger->debug('Manager', 'Processing ' . count($purge_items) . ' purge items for deleted term ID ' . $term_id);
            $result = $this->api->process_purge_items($purge_items);

            if (!$result) {
                $this->logger->error('Manager', 'Failed to process purge items for deleted term ID ' . $term_id);
            }
        } else {
            $this->logger->debug('Manager', 'No purge items found for deleted term ID ' . $term_id);
        }
    }

    /**
     * Queue purge items for later processing
     *
     * @param array<array{type: PurgeType, url: string}> $purge_items Items to add to the queue
     */
    public function queue_purge_items(array $purge_items): void
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
            $key = $item['type']->value . '|' . $item['url'];
            if (!isset($unique_keys[$key])) {
                $unique_keys[$key] = true;
                $all_items[] = $item;
            }
        }

        // Add new items if not already in queue
        foreach ($purge_items as $item) {
            $key = $item['type']->value . '|' . $item['url'];
            if (!isset($unique_keys[$key])) {
                $unique_keys[$key] = true;
                $all_items[] = $item;
            }
        }

        $added_count = count($all_items) - count($existing_items);
        $this->logger->debug('Manager', 'Added ' . $added_count . ' new purge items to queue. Total in queue: ' . count($all_items));

        update_option(ZW_CACHEMAN_QUEUE, $all_items, false);
    }

    /**
     * Process the queue - called by WP-Cron
     */
    public function process_queue(): void
    {
        $queue = get_option(ZW_CACHEMAN_QUEUE, []);
        if (empty($queue)) {
            $this->logger->debug('Manager', 'Queue is empty. Nothing to process.');
            return;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        $batch_size = !empty($settings['batch_size']) ? (int)$settings['batch_size'] : 30;

        // Take a batch of items from the queue
        $items_to_process = array_slice($queue, 0, $batch_size);
        $remaining_items = array_slice($queue, $batch_size);

        $this->logger->debug('Manager', 'Processing ' . count($items_to_process) . ' items from queue (' . count($remaining_items) . ' items will remain)');

        // Process the batch using the API's process_purge_items method
        $success = $this->api->process_purge_items($items_to_process);

        if ($success) {
            // Update the queue with remaining items (autoload disabled for performance)
            update_option(ZW_CACHEMAN_QUEUE, $remaining_items, false);
            $this->logger->debug('Manager', 'Successfully processed batch. ' . count($remaining_items) . ' items remaining in queue.');
        } else {
            $this->logger->error('Manager', 'Failed to process batch of ' . count($items_to_process) . ' items. Will retry next run.');
        }

        // Ensure WP-Cron is still scheduled
        if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
            wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
            $this->logger->debug('Manager', 'Re-scheduled missing cron job.');
        }
    }
}
