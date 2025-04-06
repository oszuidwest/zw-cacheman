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
     * Constructor
     *
     * @param CachemanAPI $api The API handler instance.
     */
    public function __construct($api)
    {
        $this->api = $api;

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
            // High priority URLs to purge immediately
            $high_priority_urls = $this->get_high_priority_urls($post);
            $this->api->purge_urls($high_priority_urls);

            // Queue low priority URLs for later processing
            $low_priority_urls = $this->get_low_priority_urls($post);
            $this->queue_urls($low_priority_urls);
        }
    }

    /**
     * Get high priority URLs for a post
     *
     * @param \WP_Post $post Post object.
     * @return array List of URLs.
     */
    public function get_high_priority_urls($post)
    {
        // Basic post URLs
        $permalink = get_permalink($post->ID);
        $home_url = get_home_url();
        $feed_link = get_feed_link();
        $archive_link = get_post_type_archive_link($post->post_type);

        $urls = [];

        // Only add valid URLs
        if ($permalink) {
            $urls[] = trailingslashit($permalink);
        }
        if ($home_url) {
            $urls[] = trailingslashit($home_url);
        }
        if ($feed_link) {
            $urls[] = trailingslashit($feed_link);
        }
        if ($archive_link) {
            $urls[] = trailingslashit($archive_link);
        }

        // Add REST API endpoints
        $post_type = $post->post_type;
        $post_obj = get_post_type_object($post_type);

        if ($post_obj) {
            $rest_base = !empty($post_obj->rest_base) ? $post_obj->rest_base : $post_type;

            $rest_post_url = rest_url('wp/v2/' . $rest_base . '/' . $post->ID);
            $rest_home_url = rest_url();
            $rest_archive_url = rest_url('wp/v2/' . $rest_base);

            if ($rest_post_url) {
                $urls[] = trailingslashit($rest_post_url);
            }
            if ($rest_home_url) {
                $urls[] = trailingslashit($rest_home_url);
            }
            if ($rest_archive_url) {
                $urls[] = trailingslashit($rest_archive_url);
            }
        }

        // Combine, filter, and validate URLs
        $filtered_urls = array_values(array_unique(array_filter($urls, function ($url) {
            return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
        })));

        $this->debug_log('Generated ' . count($filtered_urls) . ' high priority URLs for purging');

        return $filtered_urls;
    }

    /**
     * Get low priority URLs for a post (taxonomy, author archives, etc.)
     *
     * @param \WP_Post $post Post object.
     * @return array List of URLs.
     */
    public function get_low_priority_urls($post)
    {
        $urls = [];

        // Get taxonomy archive URLs
        $post_type = get_post_type($post->ID);
        $taxonomies = get_object_taxonomies($post_type);

        if (!empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $term_link = get_term_link($term, $taxonomy);
                        if (!is_wp_error($term_link) && !empty($term_link)) {
                            $urls[] = trailingslashit($term_link);

                            // Add REST API endpoint for taxonomy
                            $tax_obj = get_taxonomy($taxonomy);
                            if (!empty($tax_obj) && !empty($tax_obj->show_in_rest)) {
                                $rest_base = !empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;
                                $rest_url = rest_url('wp/v2/' . $rest_base . '/' . $term->term_id);
                                if (!empty($rest_url)) {
                                    $urls[] = trailingslashit($rest_url);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Get author archive URL if post type supports author
        if (post_type_supports($post_type, 'author')) {
            $author_url = get_author_posts_url((int)$post->post_author);
            if (!empty($author_url)) {
                $urls[] = trailingslashit($author_url);

                $author_rest_url = rest_url('wp/v2/users/' . $post->post_author);
                if (!empty($author_rest_url)) {
                    $urls[] = trailingslashit($author_rest_url);
                }
            }
        }

        // Filter and validate URLs
        $filtered_urls = array_values(array_unique(array_filter($urls, function ($url) {
            return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
        })));

        $this->debug_log('Generated ' . count($filtered_urls) . ' low priority URLs for queuing');

        return $filtered_urls;
    }

    /**
     * Add URLs to the processing queue
     *
     * @param array $urls URLs to add to the queue.
     * @return void
     */
    public function queue_urls($urls)
    {
        if (empty($urls)) {
            return;
        }

        $existing_urls = get_option(ZW_CACHEMAN_QUEUE, []);
        $all_urls = array_unique(array_merge($existing_urls, $urls));
        update_option(ZW_CACHEMAN_QUEUE, $all_urls);

        $this->debug_log('Added ' . count($urls) . ' URLs to queue. Total in queue: ' . count($all_urls));
    }

    /**
     * Process the URL queue - called by WP-Cron
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

        // Take a batch of URLs from the queue
        $urls_to_process = array_slice($queue, 0, $batch_size);
        $remaining_urls = array_slice($queue, $batch_size);

        $this->debug_log('Processing ' . count($urls_to_process) . ' URLs from queue');

        // Process the batch
        $success = $this->api->purge_urls($urls_to_process);

        if ($success) {
            // Update the queue with remaining URLs
            update_option(ZW_CACHEMAN_QUEUE, $remaining_urls);
            $this->debug_log('Successfully processed batch. ' . count($remaining_urls) . ' URLs remaining in queue.');
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
