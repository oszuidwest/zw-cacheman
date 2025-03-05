<?php

namespace ZW_CACHEMAN_Core;

if (! defined('ABSPATH')) {
    exit;
}

use WP_Post;

/**
 * Class CacheManager
 *
 * Orchestrates cache purging, URL resolution, and queue processing.
 */
class CacheManager
{
    /**
     * Singleton instance.
     *
     * @var CacheManager
     */
    private static $instance;

    /**
     * Plugin settings.
     *
     * @var array
     */
    private $options;

    /**
     * Instance of the API handler.
     *
     * @var CacheManagerApi
     */
    private $api;

    /**
     * Instance of the URL resolver.
     *
     * @var CacheManagerUrlResolver
     */
    private $url_resolver;

    /**
     * Instance of the queue handler.
     *
     * @var CacheManagerQueue
     */
    private $queue;

    /**
     * Private constructor.
     */
    private function __construct()
    {
        $this->options = get_option('zw_cacheman_settings', []);

        // Initialize helper components.
        $this->api          = CacheManagerApi::get_instance();
        $this->url_resolver = CacheManagerUrlResolver::get_instance();
        $this->queue        = CacheManagerQueue::get_instance();

        // Hook into post status transitions.
        add_action('transition_post_status', [ $this, 'handle_post_status_change' ], 10, 3);

        // Register a custom cron schedule and processing hook.
        add_filter('cron_schedules', [ $this, 'add_cron_interval' ]);
        add_action(ZW_CACHEMAN_CRON_HOOK, [ $this, 'process_queued_low_priority_urls' ]);
    }

    /**
     * Returns the singleton instance.
     *
     * @return CacheManager
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retrieve a plugin option.
     *
     * @param string $option Option name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get_option($option, $default = false)
    {
        return isset($this->options[ $option ]) ? $this->options[ $option ] : $default;
    }

    /**
     * Debug logging.
     *
     * @param string $message Debug message.
     */
    public function debug_log($message)
    {
        if ($this->get_option('zw_cacheman_debug_mode', false)) {
            error_log('[ZW Cacheman] ' . $message);
        }
    }

    /**
     * Adds a custom cron schedule ("every_minute").
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_interval($schedules)
    {
        if (! isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => esc_html__('Every minute', 'zw-cacheman'),
            ];
        }
        return $schedules;
    }

    /**
     * Handles post status changes to purge URLs.
     *
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param WP_Post $post       Post object.
     */
    public function handle_post_status_change($new_status, $old_status, $post)
    {
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return;
        }

        $this->debug_log('Post status changed from ' . $old_status . ' to ' . $new_status . ' for post ID ' . $post->ID . '.');

        if ('publish' === $new_status || 'publish' === $old_status) {
            // Get primary web URLs.
            $web_urls = $this->url_resolver->get_web_urls($post);

            // Get matching REST endpoints.
            $rest_urls = [];
            foreach ($web_urls as $url) {
                $rest_endpoint = $this->url_resolver->get_matching_rest_endpoint($url, [ 'type' => 'post', 'post' => $post ]);
                if (! empty($rest_endpoint)) {
                    $rest_urls[] = $rest_endpoint;
                }
            }
            $all_high_priority_urls = array_unique(array_merge($web_urls, $rest_urls));

            // Immediately purge high-priority URLs.
            $this->api->purge_urls($all_high_priority_urls);

            // Queue associated taxonomy and author URLs.
            $associated_urls = $this->url_resolver->get_associated_urls($post->ID);
            if (! empty($associated_urls)) {
                $this->queue->queue_low_priority_urls($associated_urls);
            }
        }
    }

    /**
     * Processes queued low-priority URLs.
     */
    public function process_queued_low_priority_urls()
    {
        $queued_urls = get_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, []);
        if (empty($queued_urls)) {
            $this->debug_log('No low-priority URLs to process.');
            return;
        }
        $batch_size = (int) $this->get_option('zw_cacheman_batch_size', 30);
        $urls_to_process = array_slice($queued_urls, 0, $batch_size);
        $remaining_urls = array_slice($queued_urls, $batch_size);
        $this->debug_log('Processing ' . count($urls_to_process) . ' low-priority URLs: ' . implode(', ', $urls_to_process));
        if ($this->api->purge_urls($urls_to_process)) {
            update_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, $remaining_urls);
            $this->debug_log('Successfully processed ' . count($urls_to_process) . ' low-priority URLs.');
        } else {
            $this->debug_log('Failed to process queued low-priority URLs.');
        }
    }

    /**
     * Tests the Cloudflare API connection.
     *
     * @param string $zone_id The Cloudflare Zone ID.
     * @param string $api_key The Cloudflare API Key.
     * @return array
     */
    public function test_cloudflare_connection($zone_id, $api_key)
    {
        return $this->api->test_cloudflare_connection($zone_id, $api_key);
    }

    /**
     * Sanitizes plugin settings.
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input)
    {
        $sanitized = [];
        $old = get_option('zw_cacheman_settings', []);

        if (isset($input['zw_cacheman_zone_id'])) {
            $sanitized['zw_cacheman_zone_id'] = sanitize_text_field($input['zw_cacheman_zone_id']);
        }
        if (isset($input['zw_cacheman_api_key'])) {
            $sanitized['zw_cacheman_api_key'] = sanitize_text_field($input['zw_cacheman_api_key']);
        }
        if (isset($input['zw_cacheman_batch_size'])) {
            $sanitized['zw_cacheman_batch_size'] = absint($input['zw_cacheman_batch_size']);
            if ($sanitized['zw_cacheman_batch_size'] < 1) {
                $sanitized['zw_cacheman_batch_size'] = 30;
                add_settings_error(
                    'zw_cacheman_settings',
                    'zw_cacheman_batch_size_error',
                    esc_html__('Batch size must be at least 1. Reset to default (30).', 'zw-cacheman'),
                    'error'
                );
            }
        }
        $sanitized['zw_cacheman_debug_mode'] = isset($input['zw_cacheman_debug_mode']) ? 1 : 0;

        $api_changed = ( ! isset($old['zw_cacheman_zone_id']) || $sanitized['zw_cacheman_zone_id'] !== $old['zw_cacheman_zone_id'] ) ||
                       ( ! isset($old['zw_cacheman_api_key']) || $sanitized['zw_cacheman_api_key'] !== $old['zw_cacheman_api_key'] );
        if ($api_changed && ! empty($sanitized['zw_cacheman_zone_id']) && ! empty($sanitized['zw_cacheman_api_key'])) {
            $result = $this->test_cloudflare_connection($sanitized['zw_cacheman_zone_id'], $sanitized['zw_cacheman_api_key']);
            if ($result['success']) {
                add_settings_error(
                    'zw_cacheman_settings',
                    'zw_cacheman_api_success',
                    esc_html__('Cloudflare API connection successful!', 'zw-cacheman'),
                    'success'
                );
            } else {
                add_settings_error(
                    'zw_cacheman_settings',
                    'zw_cacheman_api_error',
                    sprintf(esc_html__('Cloudflare API connection failed: %s', 'zw-cacheman'), $result['message']),
                    'error'
                );
            }
        }
        return $sanitized;
    }
}
