<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges Cloudflare cache for high-priority URLs (both the “web” URL and its matching REST endpoint) immediately when posts are published or edited. It also queues related taxonomy URLs (and their REST endpoints) for low-priority batch processing via WP-Cron.
 * Version: 0.6.1
 * Author: Streekomroep ZuidWest
 * License: GPLv3
 */

namespace ZW_CACHEMAN_Core;

use WP_Post;

// Abort execution if this file is accessed directly.
if (! defined('WPINC')) {
    exit;
}

// Define plugin constants.
define('ZW_CACHEMAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZW_CACHEMAN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZW_CACHEMAN_LOW_PRIORITY_STORE', 'zw_cacheman_purge_urls');
define('ZW_CACHEMAN_CRON_HOOK', 'zw_cacheman_manager_cron_hook');
define('ZW_CACHEMAN_TEXT_DOMAIN', 'zw-cacheman');

/**
 * Class CacheManager
 *
 * Handles Cloudflare cache purging and WP-Cron based low-priority URL processing.
 */
class CacheManager
{
    /**
     * Instance of the CacheManager class.
     *
     * @var CacheManager
     */
    protected static $instance = null;

    /**
     * Plugin settings options.
     *
     * @var array
     */
    protected $options;

    /**
     * Constructor. Initializes plugin settings and hooks.
     */
    private function __construct()
    {
        $this->options = get_option('zw_cacheman_settings', []);

        // Add hooks for admin menu and settings initialization.
        add_action('admin_menu', [ $this, 'add_admin_menu' ]);
        add_action('admin_init', [ $this, 'settings_init' ]);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'add_settings_link' ]);

        // Hook into post status transitions to purge URLs.
        add_action('transition_post_status', [ $this, 'handle_post_status_change' ], 10, 3);

        // Add WP-Cron schedule and cron job hook.
        add_filter('cron_schedules', [ $this, 'add_cron_interval' ]);
        add_action(ZW_CACHEMAN_CRON_HOOK, [ $this, 'process_queued_low_priority_urls' ]);
    }

    /**
     * Returns a single instance of the CacheManager class.
     *
     * @return CacheManager A single instance of this class.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Adds a settings link to the plugin's entry on the plugins page.
     *
     * @param array $links Plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="options-general.php?page=zw_cacheman_plugin">' . esc_html__('Settings', 'zw-cacheman') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Registers the plugin options page in the admin menu.
     */
    public function add_admin_menu()
    {
        add_options_page(
            esc_html__('ZuidWest Cache Manager Settings', 'zw-cacheman'),
            esc_html__('ZuidWest Cache', 'zw-cacheman'),
            'manage_options',
            'zw_cacheman_plugin',
            [ $this, 'render_options_page' ]
        );
    }

    /**
     * Initializes the settings for the admin options page.
     */
    public function settings_init()
    {
        register_setting(
            'zw_cacheman_plugin',
            'zw_cacheman_settings',
            [
                'type'              => 'array',
                'default'           => [],
                'sanitize_callback' => __NAMESPACE__ . '\\zw_cacheman_sanitize_settings',
            ]
        );

        add_settings_section(
            'zw_cacheman_plugin_section',
            esc_html__('Cloudflare API Settings', 'zw-cacheman'),
            [ $this, 'settings_section_callback' ],
            'zw_cacheman_plugin'
        );

        // Define the fields using a compact array.
        $fields = [
            [ 'zw_cacheman_zone_id', 'Zone ID', 'text', '' ],
            [ 'zw_cacheman_api_key', 'API Key', 'password', '' ],
            [ 'zw_cacheman_batch_size', 'Batch Size', 'number', 30 ],
            [ 'zw_cacheman_debug_mode', 'Debug Mode', 'checkbox', 0 ],
        ];

        // Register all fields in a loop.
        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[1],
                [ $this, 'render_settings_field' ],
                'zw_cacheman_plugin',
                'zw_cacheman_plugin_section',
                [ 'label_for' => $field[0], 'type' => $field[2], 'default' => $field[3] ]
            );
        }
    }

    /**
     * Sanitizes the settings input.
     *
     * @param array $input The settings input.
     * @return array The sanitized settings.
     */
    public function sanitize_settings($input)
    {
        $sanitized_input = [];
        $old_settings    = get_option('zw_cacheman_settings', []);

        if (isset($input['zw_cacheman_zone_id'])) {
            $sanitized_input['zw_cacheman_zone_id'] = sanitize_text_field($input['zw_cacheman_zone_id']);
        }

        if (isset($input['zw_cacheman_api_key'])) {
            $sanitized_input['zw_cacheman_api_key'] = sanitize_text_field($input['zw_cacheman_api_key']);
        }

        if (isset($input['zw_cacheman_batch_size'])) {
            $sanitized_input['zw_cacheman_batch_size'] = absint($input['zw_cacheman_batch_size']);
            if ($sanitized_input['zw_cacheman_batch_size'] < 1) {
                $sanitized_input['zw_cacheman_batch_size'] = 30;
                add_settings_error(
                    'zw_cacheman_settings',
                    'zw_cacheman_batch_size_error',
                    esc_html__('Batch size must be at least 1. Reset to default (30).', 'zw-cacheman'),
                    'error'
                );
            }
        }

        $sanitized_input['zw_cacheman_debug_mode'] = isset($input['zw_cacheman_debug_mode']) ? 1 : 0;

        // Check if API credentials have changed.
        $api_changed = ( ! isset($old_settings['zw_cacheman_zone_id']) || $sanitized_input['zw_cacheman_zone_id'] !== $old_settings['zw_cacheman_zone_id'] ) ||
            ( ! isset($old_settings['zw_cacheman_api_key']) || $sanitized_input['zw_cacheman_api_key'] !== $old_settings['zw_cacheman_api_key'] );

        // Test the API connection if the credentials are set and have been changed.
        if ($api_changed && ! empty($sanitized_input['zw_cacheman_zone_id']) && ! empty($sanitized_input['zw_cacheman_api_key'])) {
            $test_result = $this->test_cloudflare_connection($sanitized_input['zw_cacheman_zone_id'], $sanitized_input['zw_cacheman_api_key']);

            if ($test_result['success']) {
                add_settings_error(
                    'zw_cacheman_settings',
                    'zw_cacheman_api_success',
                    esc_html__('Cloudflare API connection successful!', 'zw-cacheman'),
                    'success'
                );
            } else {
                // translators: %s is the error message returned from the Cloudflare API.
                $api_error_message = esc_html__('Cloudflare API connection failed: %s', 'zw-cacheman');
                add_settings_error(
                    'zw_cacheman_settings',
                    'zw_cacheman_api_error',
                    sprintf($api_error_message, $test_result['message']),
                    'error'
                );
            }
        }

        return $sanitized_input;
    }

    /**
     * Tests the connection to the Cloudflare API.
     *
     * @param string $zone_id The Cloudflare Zone ID.
     * @param string $api_key The Cloudflare API Key.
     * @return array Result array containing a 'success' status and a message.
     */
    public function test_cloudflare_connection($zone_id, $api_key)
    {
        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id;

        $response = wp_remote_get(
            $api_endpoint,
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body          = wp_remote_retrieve_body($response);
        $body_json     = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $zone_name = isset($body_json['result']['name']) ? $body_json['result']['name'] : 'unknown';
            $this->debug_log('API connection test successful. Connected to zone: ' . $zone_name);

            return [
                'success' => true,
                'message' => sprintf('Connected to zone: %s', $zone_name),
            ];
        } else {
            $error_message = isset($body_json['errors'][0]['message'])
                ? $body_json['errors'][0]['message']
                : sprintf('Unknown error (HTTP code: %d)', $response_code);

            $this->debug_log('API connection test failed: ' . $error_message);

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }
    }

    /**
     * Renders a settings field on the admin options page.
     *
     * @param array $args Field arguments.
     */
    public function render_settings_field($args)
    {
        $value    = isset($this->options[ $args['label_for'] ]) ? $this->options[ $args['label_for'] ] : $args['default'];
        $field_id = esc_attr($args['label_for']);

        $field_types = [
            'text'     => '<input type="text" id="%1$s" name="zw_cacheman_settings[%1$s]" value="%2$s" class="regular-text">',
            'number'   => '<input type="number" id="%1$s" name="zw_cacheman_settings[%1$s]" value="%2$s" class="regular-text">',
            'password' => '<input type="password" id="%1$s" name="zw_cacheman_settings[%1$s]" value="%2$s" class="regular-text">',
            'checkbox' => '<input type="checkbox" id="%1$s" name="zw_cacheman_settings[%1$s]" value="1" %2$s>',
        ];

        if (isset($field_types[ $args['type'] ])) {
            printf(
                $field_types[ $args['type'] ],
                $field_id,
                $args['type'] === 'checkbox' ? ( $value ? 'checked' : '' ) : esc_attr($value)
            );
        }
    }

    /**
     * Callback function to display information for the settings section.
     */
    public function settings_section_callback()
    {
        echo '<p>' . esc_html__('Enter your Cloudflare API settings below:', 'zw-cacheman') . '</p>';
    }

    /**
     * Renders the plugin options page.
     */
    public function render_options_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ZuidWest Cache Manager Settings', 'zw-cacheman'); ?></h1>

            <?php settings_errors(); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('zw_cacheman_plugin');
                do_settings_sections('zw_cacheman_plugin');
                submit_button();
                ?>
            </form>

            <?php
            echo $this->render_section('Connection Test', $this->get_connection_test_content());
            echo $this->render_section('WP-Cron Status', $this->get_cron_status_content());
            echo $this->render_section('Queue Status', $this->get_queue_status_content());
            ?>
        </div>
        <?php
    }

    /**
     * Renders a section with a title and content.
     *
     * @param string $title   The title of the section.
     * @param string $content The HTML content of the section.
     * @return string The rendered HTML section.
     */
    private function render_section($title, $content)
    {
        ob_start();
        ?>
        <hr>
        <h2><?php echo esc_html($title); ?></h2>
        <?php echo $content; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Retrieves HTML content for the connection test section.
     *
     * @return string HTML content for testing the Cloudflare API connection.
     */
    private function get_connection_test_content()
    {
        ob_start();
        $zone_id = $this->get_option('zw_cacheman_zone_id');
        $api_key = $this->get_option('zw_cacheman_api_key');

        if (empty($zone_id) || empty($api_key)) {
            echo '<p>' . esc_html__('Please enter your Cloudflare Zone ID and API Key in the settings above to test the connection.', 'zw-cacheman') . '</p>';
        } else {
            ?>
            <p><?php esc_html_e('Test your Cloudflare API connection:', 'zw-cacheman'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('zw_cacheman_test_connection', 'zw_cacheman_test_nonce'); ?>
                <input type="hidden" name="action" value="test_connection">
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Test Connection', 'zw-cacheman'); ?>">
            </form>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Retrieves HTML content for the WP-Cron status section.
     *
     * @return string HTML content showing the WP-Cron schedule status.
     */
    private function get_cron_status_content()
    {
        ob_start();
        $next_run = wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK);

        if ($next_run) {
            // translators: %1$s is the date/time of the next run; %2$d is the number of seconds until the next run.
            $time_diff         = $next_run - time();
            $next_run_message  = esc_html__('Next scheduled run: %1$s (in %2$d seconds)', 'zw-cacheman');
            printf('<p>' . $next_run_message . '</p>', date_i18n('Y-m-d H:i:s', $next_run), $time_diff);
        } else {
            echo '<p class="notice notice-error">' . esc_html__('WP-Cron job is not scheduled! This is a problem.', 'zw-cacheman') . '</p>';
        }
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('zw_cacheman_force_cron', 'zw_cacheman_cron_nonce'); ?>
            <input type="hidden" name="action" value="force_cron">
            <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Force Process Queue Now', 'zw-cacheman'); ?>">
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Retrieves HTML content for the queue status section.
     *
     * @return string HTML content showing the status of the queued URLs.
     */
    private function get_queue_status_content()
    {
        ob_start();
        $queued_urls = get_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, []);
        $count       = count($queued_urls);
        ?>
        <p>
            <?php
            // translators: %d is the number of URLs currently in the queue.
            $queue_text = esc_html__('URLs currently in queue: %d', 'zw-cacheman');
            printf($queue_text, $count);
            ?>
        </p>

        <?php if ($count > 0) : ?>
            <form method="post" action="">
                <?php wp_nonce_field('zw_cacheman_clear_queue', 'zw_cacheman_nonce'); ?>
                <input type="hidden" name="action" value="clear_queue">
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Clear Queue', 'zw-cacheman'); ?>">
            </form>

            <br>
            <details>
                <summary><?php esc_html_e('Show queued URLs', 'zw-cacheman'); ?></summary>
                <div style="max-height: 200px; overflow-y: auto; margin-top: 10px; padding: 10px; background: #f8f8f8; border: 1px solid #ddd;">
                    <ol>
                        <?php foreach ($queued_urls as $url) : ?>
                            <li><?php echo esc_url($url); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </details>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Retrieves a specific plugin option.
     *
     * @param string $option_name The name of the option.
     * @param mixed  $default     The default value if the option is not set.
     * @return mixed The option value.
     */
    public function get_option($option_name, $default = false)
    {
        return isset($this->options[ $option_name ]) ? $this->options[ $option_name ] : $default;
    }

    /**
     * Logs a debug message if debug mode is enabled.
     *
     * @param string $message The debug message.
     */
    public function debug_log($message)
    {
        if ($this->get_option('zw_cacheman_debug_mode', false)) {
            error_log('[ZW Cacheman] ' . $message);
        }
    }

    /**
     * Adds a custom cron interval for scheduling tasks.
     *
     * @param array $schedules The existing cron schedules.
     * @return array The modified cron schedules including a one-minute interval.
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
     * Handles post status transitions and purges URLs as needed.
     *
     * For each “web” URL (permalink, home, feed, archive) this method determines
     * a corresponding REST endpoint (if available) and purges both immediately.
     * In addition, for the associated taxonomy and author URLs, it uses the universal
     * method to derive matching REST endpoints so that both the original and REST URLs are queued.
     *
     * @param string  $new_status The new status of the post.
     * @param string  $old_status The previous status of the post.
     * @param WP_Post $post       The post object.
     */
    public function handle_post_status_change($new_status, $old_status, $post)
    {
        // Skip autosaves and revisions.
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return;
        }

        $this->debug_log('Post status changed from ' . $old_status . ' to ' . $new_status . ' for post ID ' . $post->ID . '.');

        if ('publish' === $new_status || 'publish' === $old_status) {
            // Build an array of web URLs.
            $web_urls = [
                trailingslashit(get_permalink($post->ID)),
                trailingslashit(get_home_url()),
                trailingslashit(get_feed_link()),
                trailingslashit(get_post_type_archive_link($post->post_type)),
            ];
            $web_urls = array_filter($web_urls);
            $web_urls = array_unique($web_urls);

            // For each web URL, get its corresponding REST endpoint using the universal method.
            $rest_urls = [];
            foreach ($web_urls as $url) {
                $rest_endpoint = $this->get_matching_rest_endpoint($url, [ 'type' => 'post', 'post' => $post ]);
                if (! empty($rest_endpoint)) {
                    $rest_urls[] = $rest_endpoint;
                }
            }

            // Combine web URLs with their REST endpoints and purge immediately.
            $all_high_priority_urls = array_merge($web_urls, $rest_urls);
            $all_high_priority_urls = array_filter($all_high_priority_urls);
            $all_high_priority_urls = array_unique($all_high_priority_urls);
            $this->purge_urls($all_high_priority_urls);

            // Retrieve associated taxonomy and author URLs (with REST endpoints) for low-priority processing.
            $taxonomy_urls = $this->get_associated_taxonomy_urls($post->ID);
            if (! empty($taxonomy_urls)) {
                $this->queue_low_priority_urls($taxonomy_urls);
            }
        }
    }

    /**
     * Universal method to return the matching REST endpoint for a given URL based on context.
     *
     * The context array must include a key 'type' with one of:
     * - 'post'     : Requires a 'post' key with the WP_Post object.
     * - 'taxonomy' : Requires a 'term' key with the WP_Term object.
     * - 'author'   : Requires a 'user_id' key.
     * - 'home'     : No additional context needed.
     *
     * @param string $url     The web URL.
     * @param array  $context Context information.
     * @return string The matching REST endpoint URL or an empty string.
     */
    public function get_matching_rest_endpoint($url, $context)
    {
        $url  = trailingslashit($url);
        $type = isset($context['type']) ? $context['type'] : '';

        switch ($type) {
            case 'post':
                if (empty($context['post']) || ! ( $context['post'] instanceof \WP_Post )) {
                    return '';
                }
                $post      = $context['post'];
                $post_type = $post->post_type;
                $post_obj  = get_post_type_object($post_type);
                $rest_base = ! empty($post_obj->rest_base) ? $post_obj->rest_base : $post_type;
                $permalink = trailingslashit(get_permalink($post->ID));
                $home      = trailingslashit(get_home_url());
                $archive   = trailingslashit(get_post_type_archive_link($post_type));
                // Compare and return the matching REST endpoint.
                if ($url === $permalink) {
                    return trailingslashit(rest_url('wp/v2/' . $rest_base . '/' . $post->ID));
                } elseif ($url === $home) {
                    return trailingslashit(rest_url());
                } elseif ($url === $archive) {
                    return trailingslashit(rest_url('wp/v2/' . $rest_base));
                }
                break;

            case 'taxonomy':
                if (empty($context['term'])) {
                    return '';
                }
                $term     = $context['term'];
                $taxonomy = $term->taxonomy;
                $tax_obj  = get_taxonomy($taxonomy);
                if (! empty($tax_obj->show_in_rest)) {
                    $rest_base = ! empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;
                    return trailingslashit(rest_url('wp/v2/' . $rest_base . '/' . $term->term_id));
                }
                break;

            case 'author':
                if (empty($context['user_id'])) {
                    return '';
                }
                return trailingslashit(rest_url('wp/v2/users/' . intval($context['user_id'])));
            case 'home':
                return trailingslashit(rest_url());
        }
        return '';
    }

    /**
     * Retrieves URLs for all taxonomies (and author) associated with a given post.
     *
     * For each taxonomy term, this method returns both the term link and, via the universal method,
     * the corresponding REST endpoint (if the taxonomy supports REST). Likewise, for the author URL,
     * it returns both the standard author URL and its REST endpoint.
     *
     * @param int $post_id The ID of the post.
     * @return array List of URLs.
     */
    public function get_associated_taxonomy_urls($post_id)
    {
        $this->debug_log('Retrieving associated taxonomy URLs for post ID ' . $post_id . '.');
        $urls      = [];
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type);

        if (! empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy);
                if ($terms && ! is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        // Standard term link.
                        $term_link_raw = get_term_link($term, $taxonomy);
                        if (! is_wp_error($term_link_raw)) {
                            $term_link = trailingslashit($term_link_raw);
                            $urls[]    = $term_link;
                            // Use the universal method to get the REST endpoint.
                            $rest_endpoint = $this->get_matching_rest_endpoint($term_link, [ 'type' => 'taxonomy', 'term' => $term ]);
                            if (! empty($rest_endpoint)) {
                                $urls[] = $rest_endpoint;
                            }
                        }
                    }
                }
            }
        }

        // Process author URL if the post type supports authors.
        if (post_type_supports($post_type, 'author')) {
            $post       = get_post($post_id);
            $author_url = trailingslashit(get_author_posts_url((int) $post->post_author));
            if ($author_url) {
                $urls[] = $author_url;
            }
            $rest_endpoint = $this->get_matching_rest_endpoint($author_url, [ 'type' => 'author', 'user_id' => $post->post_author ]);
            if (! empty($rest_endpoint)) {
                $urls[] = $rest_endpoint;
            }
        }

        $this->debug_log(sprintf('Found %d associated taxonomy and author URLs.', count($urls)));
        return $urls;
    }

    /**
     * Purges the specified URLs through the Cloudflare API.
     *
     * @param array $urls URLs to be purged.
     * @return bool True if the purge was successful, false otherwise.
     */
    public function purge_urls($urls)
    {
        if (empty($urls)) {
            return true;
        }

        $this->debug_log('Attempting to purge the following URLs: ' . implode(', ', $urls));
        $zone_id = $this->get_option('zw_cacheman_zone_id');
        $api_key = $this->get_option('zw_cacheman_api_key');

        if (empty($zone_id) || empty($api_key)) {
            $this->debug_log('Zone ID or API Key is not set. Aborting cache purge.');
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';
        $response     = wp_remote_post(
            $api_endpoint,
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([ 'files' => $urls ]),
            ]
        );

        if (is_wp_error($response)) {
            $this->debug_log('Cache purge request failed: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body          = wp_remote_retrieve_body($response);
        $body_json     = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $this->debug_log('Successfully purged ' . count($urls) . ' URLs.');
            return true;
        } else {
            $error_message = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error';
            $this->debug_log('Cache purge failed with HTTP code ' . $response_code . ': ' . $error_message);
            return false;
        }
    }

    /**
     * Queues the specified URLs for low-priority processing.
     *
     * @param array $urls URLs to be queued.
     */
    public function queue_low_priority_urls($urls)
    {
        $existing_urls = get_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, []);
        $all_urls      = array_merge($existing_urls, $urls);
        $all_urls      = array_unique($all_urls);
        update_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, $all_urls);
        $this->debug_log('Queued ' . count($urls) . ' low-priority URLs for later processing.');
    }

    /**
     * Processes queued low-priority URLs.
     *
     * Retrieves any queued URLs from the low-priority store, attempts to purge a limited
     * batch of URLs (as defined by the 'zw_cacheman_batch_size' setting), and then updates
     * the queue with any remaining URLs.
     */
    public function process_queued_low_priority_urls()
    {
        $queued_urls = get_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, []);
        if (empty($queued_urls)) {
            $this->debug_log('No low-priority URLs to process.');
            return;
        }

        // Get the batch size limit from plugin settings, defaulting to 30 if not set.
        $batch_size = (int) $this->get_option('zw_cacheman_batch_size', 30);
        // Extract the URLs to process in this batch.
        $urls_to_process = array_slice($queued_urls, 0, $batch_size);
        // Extract the remaining URLs in the queue.
        $remaining_urls = array_slice($queued_urls, $batch_size);

        $this->debug_log('Processing queued low-priority URLs (batch of ' . count($urls_to_process) . '): ' . implode(', ', $urls_to_process));
        if ($this->purge_urls($urls_to_process)) {
            // Update the queue with the remaining URLs.
            update_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, $remaining_urls);
            $this->debug_log('Successfully processed ' . count($urls_to_process) . ' queued low-priority URLs.');
        } else {
            $this->debug_log('Failed to process queued low-priority URLs.');
        }
    }
}

/**
 * Sanitization callback for the plugin settings.
 *
 * @param array $input The settings input.
 * @return array The sanitized settings.
 */
function zw_cacheman_sanitize_settings($input)
{
    return CacheManager::get_instance()->sanitize_settings($input);
}

/**
 * Handles plugin activation tasks.
 */
function zw_cacheman_activate()
{
    wp_clear_scheduled_hook(ZW_CACHEMAN_CRON_HOOK);
    $scheduled = wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
    $options   = get_option('zw_cacheman_settings', []);
    if (isset($options['zw_cacheman_debug_mode']) && $options['zw_cacheman_debug_mode']) {
        if ($scheduled) {
            error_log('[ZW Cacheman] Plugin activated and cron job successfully scheduled.');
        } else {
            error_log('[ZW Cacheman] Plugin activated but cron job scheduling FAILED.');
        }
    }
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\zw_cacheman_activate');

/**
 * Handles plugin deactivation tasks.
 */
function zw_cacheman_deactivate()
{
    $options = get_option('zw_cacheman_settings', []);
    if (isset($options['zw_cacheman_debug_mode']) && $options['zw_cacheman_debug_mode']) {
        error_log('[ZW Cacheman] Plugin deactivated and cron job cleared.');
    }
    wp_clear_scheduled_hook(ZW_CACHEMAN_CRON_HOOK);
}
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\zw_cacheman_deactivate');

/**
 * Handles various admin actions including clearing the queue, testing the API connection, and forcing cron execution.
 */
function zw_cacheman_admin_init()
{
    if (! isset($_POST['action']) || ! current_user_can('manage_options')) {
        return;
    }

    $action  = sanitize_text_field(wp_unslash($_POST['action']));
    $actions = [
        'clear_queue'    => [
            'nonce'        => 'zw_cacheman_nonce',
            'nonce_action' => 'zw_cacheman_clear_queue',
            'callback'     => function () {
                delete_option(ZW_CACHEMAN_LOW_PRIORITY_STORE);
                return [ 'message' => 'queue_cleared' ];
            },
        ],
        'test_connection' => [
            'nonce'        => 'zw_cacheman_test_nonce',
            'nonce_action' => 'zw_cacheman_test_connection',
            'callback'     => function () {
                $cacheman = CacheManager::get_instance();
                $zone_id  = $cacheman->get_option('zw_cacheman_zone_id');
                $api_key  = $cacheman->get_option('zw_cacheman_api_key');
                if (empty($zone_id) || empty($api_key)) {
                    return [ 'message' => 'missing_credentials' ];
                }
                $test_result = $cacheman->test_cloudflare_connection($zone_id, $api_key);
                return [
                    'message' => 'connection_' . ( $test_result['success'] ? 'success' : 'error' ),
                    'details' => urlencode($test_result['message']),
                ];
            },
        ],
        'force_cron'     => [
            'nonce'        => 'zw_cacheman_cron_nonce',
            'nonce_action' => 'zw_cacheman_force_cron',
            'callback'     => function () {
                $cacheman = CacheManager::get_instance();
                $cacheman->debug_log('Manual execution of queue processing triggered from admin.');
                $cacheman->process_queued_low_priority_urls();
                if (! wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
                    $scheduled = wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
                    $cacheman->debug_log('WP-Cron job was ' . ( $scheduled ? 'successfully' : 'unsuccessfully' ) . ' rescheduled during manual run.');
                }
                return [ 'message' => 'cron_executed' ];
            },
        ],
    ];

    if (isset($actions[ $action ])) {
        $handler = $actions[ $action ];
        if (isset($_POST[ $handler['nonce'] ]) && wp_verify_nonce(sanitize_key(wp_unslash($_POST[ $handler['nonce'] ])), $handler['nonce_action'])) {
            $result     = $handler['callback']();
            $query_args = array_merge([ 'page' => 'zw_cacheman_plugin' ], $result);
            wp_redirect(add_query_arg($query_args, admin_url('options-general.php')));
            exit;
        }
    }
}
add_action('admin_init', __NAMESPACE__ . '\zw_cacheman_admin_init');

/**
 * Displays admin notices based on plugin actions.
 */
function zw_cacheman_admin_notices()
{
    if (! isset($_GET['page']) || 'zw_cacheman_plugin' !== $_GET['page'] || ! isset($_GET['message'])) {
        return;
    }

    $message = '';
    if (isset($_GET['message'])) {
        $message = sanitize_text_field(wp_unslash($_GET['message']));
    }

    $details = '';
    if (isset($_GET['details'])) {
        $details = sanitize_text_field(wp_unslash($_GET['details']));
        $details = urldecode($details);
    }

    $notices = [
        'queue_cleared'       => [ 'success', __('Cache queue has been cleared.', 'zw-cacheman') ],
        'connection_success'  => [ 'success', __('Cloudflare API connection successful!', 'zw-cacheman') . ( ! empty($details) ? ' ' . $details : '' ) ],
        'connection_error'    => [ 'error', __('Cloudflare API connection failed: ', 'zw-cacheman') . $details ],
        'missing_credentials' => [ 'error', __('Please enter both Zone ID and API Key to test the connection.', 'zw-cacheman') ],
        'cron_executed'       => [ 'success', __('Queue processing has been manually executed.', 'zw-cacheman') ],
    ];

    if (isset($notices[ $message ])) {
        list( $type, $text ) = $notices[ $message ];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }

    if (isset($_GET['page']) && 'zw_cacheman_plugin' === $_GET['page']) {
        $next_run = wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK);
        if (! $next_run) {
            echo '<div class="notice notice-error"><p><strong>' .
                 esc_html__('Warning:', 'zw-cacheman') . ' </strong>' .
                 esc_html__('The WP-Cron job for cache processing is not scheduled. This will prevent automatic processing of the queue.', 'zw-cacheman') .
                 '</p></div>';
        }
    }
}
add_action('admin_notices', __NAMESPACE__ . '\zw_cacheman_admin_notices');

/**
 * Ensures that the cron job for processing queued URLs is scheduled on plugin initialization.
 */
function zw_cacheman_ensure_cron_scheduled()
{
    if (! wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
        $scheduled = wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
        $options   = get_option('zw_cacheman_settings', []);
        if (isset($options['zw_cacheman_debug_mode']) && $options['zw_cacheman_debug_mode']) {
            if ($scheduled) {
                error_log('[ZW Cacheman] Cron job was missing and has been rescheduled successfully.');
            } else {
                error_log('[ZW Cacheman] Cron job was missing and rescheduling FAILED.');
            }
        }
    }
}
add_action('init', __NAMESPACE__ . '\zw_cacheman_ensure_cron_scheduled');

/**
 * Initializes the plugin by returning a CacheManager instance.
 */
function zw_cacheman_manager_init()
{
    return CacheManager::get_instance();
}

add_action('plugins_loaded', __NAMESPACE__ . '\zw_cacheman_manager_init');
