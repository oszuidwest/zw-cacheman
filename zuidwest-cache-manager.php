<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges Cloudflare cache for high-priority URLs immediately on post publishing and edits. Also queues associated taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 0.5
 * Author: Streekomroep ZuidWest
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('ZWCACHE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZWCACHE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZWCACHE_LOW_PRIORITY_STORE', 'zwcache_purge_urls');
define('ZWCACHE_CRON_HOOK', 'zwcache_manager_cron_hook');

/**
 * Class ZuidWestCacheManager
 */
class ZuidWestCacheManager {
    /**
     * Instance of this class.
     *
     * @var ZuidWestCacheManager
     */
    protected static $instance = null;

    /**
     * Plugin options
     *
     * @var array
     */
    protected $options;

    /**
     * Initialize the plugin.
     */
    private function __construct() {
        $this->options = get_option('zwcache_settings', []);
        
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
        // Post status transition hook
        add_action('transition_post_status', [$this, 'handle_post_status_change'], 10, 3);
        
        // Cron hooks
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action(ZWCACHE_CRON_HOOK, [$this, 'process_queued_low_priority_urls']);
    }

    /**
     * Return an instance of this class.
     *
     * @return ZuidWestCacheManager A single instance of this class.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Adds settings link to the plugins page.
     *
     * @param array $links Plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=zwcache_manager">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register the administration menu for this plugin.
     */
    public function add_admin_menu() {
        add_options_page(
            'ZuidWest Cache Manager Settings',
            'ZuidWest Cache',
            'manage_options',
            'zwcache_manager',
            [$this, 'render_options_page']
        );
    }

    /**
     * Initialize settings for the admin page.
     */
    public function settings_init() {
        register_setting('zwcachePlugin', 'zwcache_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section(
            'zwcache_pluginPage_section',
            'Cloudflare API Settings',
            [$this, 'settings_section_callback'],
            'zwcachePlugin'
        );

        // Define fields with a compact array structure
        $fields = [
            ['zwcache_zone_id', 'Zone ID', 'text', ''],
            ['zwcache_api_key', 'API Key', 'password', ''],
            ['zwcache_batch_size', 'Batch Size', 'number', 30],
            ['zwcache_debug_mode', 'Debug Mode', 'checkbox', 0]
        ];

        // Register all fields in a loop
        foreach ($fields as $field) {
            add_settings_field(
                $field[0], $field[1], [$this, 'render_settings_field'],
                'zwcachePlugin', 'zwcache_pluginPage_section',
                ['label_for' => $field[0], 'type' => $field[2], 'default' => $field[3]]
            );
        }
    }

    /**
     * Sanitize the settings input.
     *
     * @param array $input The settings input.
     * @return array The sanitized settings.
     */
    public function sanitize_settings($input) {
        $sanitized_input = [];
        $old_settings = get_option('zwcache_settings', []);

        if (isset($input['zwcache_zone_id'])) {
            $sanitized_input['zwcache_zone_id'] = sanitize_text_field($input['zwcache_zone_id']);
        }

        if (isset($input['zwcache_api_key'])) {
            $sanitized_input['zwcache_api_key'] = sanitize_text_field($input['zwcache_api_key']);
        }

        if (isset($input['zwcache_batch_size'])) {
            $sanitized_input['zwcache_batch_size'] = absint($input['zwcache_batch_size']);
            if ($sanitized_input['zwcache_batch_size'] < 1) {
                $sanitized_input['zwcache_batch_size'] = 30;
                add_settings_error(
                    'zwcache_settings',
                    'zwcache_batch_size_error',
                    'Batch size must be at least 1. Reset to default (30).',
                    'error'
                );
            }
        }

        $sanitized_input['zwcache_debug_mode'] = isset($input['zwcache_debug_mode']) ? 1 : 0;

        // Check if API credentials have changed
        $api_changed = 
            (!isset($old_settings['zwcache_zone_id']) || $sanitized_input['zwcache_zone_id'] !== $old_settings['zwcache_zone_id']) ||
            (!isset($old_settings['zwcache_api_key']) || $sanitized_input['zwcache_api_key'] !== $old_settings['zwcache_api_key']);

        // Test API connection if credentials are set and have changed
        if ($api_changed && !empty($sanitized_input['zwcache_zone_id']) && !empty($sanitized_input['zwcache_api_key'])) {
            $test_result = $this->test_cloudflare_connection($sanitized_input['zwcache_zone_id'], $sanitized_input['zwcache_api_key']);
            
            if ($test_result['success']) {
                add_settings_error(
                    'zwcache_settings',
                    'zwcache_api_success',
                    'Cloudflare API connection successful!',
                    'success'
                );
            } else {
                // Still save the settings but show warning
                add_settings_error(
                    'zwcache_settings',
                    'zwcache_api_error',
                    sprintf(
                        'Cloudflare API connection failed: %s',
                        $test_result['message']
                    ),
                    'error'
                );
            }
        }

        return $sanitized_input;
    }
    
    /**
     * Test connection to Cloudflare API.
     *
     * @param string $zone_id The Cloudflare Zone ID.
     * @param string $api_key The Cloudflare API Key.
     * @return array Result with success status and message.
     */
    public function test_cloudflare_connection($zone_id, $api_key) {
        // Check Zone details endpoint
        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id;
        
        $response = wp_remote_get($api_endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ]
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);
        
        if ($response_code === 200 && isset($body_json['success']) && $body_json['success'] === true) {
            // Also log zone name for verification
            $zone_name = isset($body_json['result']['name']) ? $body_json['result']['name'] : 'unknown';
            $this->debug_log('API Connection test successful. Connected to zone: ' . $zone_name);
            
            return [
                'success' => true,
                'message' => sprintf('Connected to zone: %s', $zone_name)
            ];
        } else {
            $error_message = isset($body_json['errors'][0]['message']) 
                ? $body_json['errors'][0]['message'] 
                : sprintf('Unknown error (HTTP code: %d)', $response_code);
            
            $this->debug_log('API Connection test failed: ' . $error_message);
            
            return [
                'success' => false,
                'message' => $error_message
            ];
        }
    }

    /**
     * Render a settings field.
     *
     * @param array $args Field arguments.
     */
    public function render_settings_field($args) {
        $value = isset($this->options[$args['label_for']]) ? $this->options[$args['label_for']] : $args['default'];
        $field_id = esc_attr($args['label_for']);
        
        $field_types = [
            'text' => '<input type="text" id="%1$s" name="zwcache_settings[%1$s]" value="%2$s" class="regular-text">',
            'number' => '<input type="number" id="%1$s" name="zwcache_settings[%1$s]" value="%2$s" class="regular-text">',
            'password' => '<input type="password" id="%1$s" name="zwcache_settings[%1$s]" value="%2$s" class="regular-text">',
            'checkbox' => '<input type="checkbox" id="%1$s" name="zwcache_settings[%1$s]" value="1" %2$s>'
        ];
        
        if (isset($field_types[$args['type']])) {
            printf(
                $field_types[$args['type']],
                $field_id,
                $args['type'] === 'checkbox' ? ($value ? 'checked' : '') : esc_attr($value)
            );
        }
    }

    /**
     * Callback for the settings section.
     */
    public function settings_section_callback() {
        echo '<p>' . esc_html('Enter your Cloudflare API settings below:') . '</p>';
    }

    /**
     * Render the options page.
     */
    public function render_options_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('ZuidWest Cache Manager Settings'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('zwcachePlugin');
                do_settings_sections('zwcachePlugin');
                submit_button();
                ?>
            </form>
            
            <?php
            $this->render_section('Connection Test', $this->get_connection_test_content());
            $this->render_section('WP-Cron Status', $this->get_cron_status_content());
            $this->render_section('Queue Status', $this->get_queue_status_content());
            ?>
        </div>
        <?php
    }

    /**
     * Render a section with title and content.
     *
     * @param string $title   Section title.
     * @param string $content Section content HTML.
     */
    private function render_section($title, $content) {
        ?>
        <hr>
        <h2><?php echo esc_html($title); ?></h2>
        <?php echo $content; ?>
        <?php
    }

    /**
     * Get connection test section content.
     *
     * @return string HTML content for the connection test section.
     */
    private function get_connection_test_content() {
        ob_start();
        $zone_id = $this->get_option('zwcache_zone_id');
        $api_key = $this->get_option('zwcache_api_key');
        
        if (empty($zone_id) || empty($api_key)) {
            echo '<p>' . esc_html('Please enter your Cloudflare Zone ID and API Key in the settings above to test the connection.') . '</p>';
        } else {
            ?>
            <p><?php esc_html_e('Test your Cloudflare API connection:'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('zwcache_test_connection', 'zwcache_test_nonce'); ?>
                <input type="hidden" name="action" value="test_connection">
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Test Connection'); ?>">
            </form>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Get WP-Cron status section content.
     *
     * @return string HTML content for the WP-Cron status section.
     */
    private function get_cron_status_content() {
        ob_start();
        $next_run = wp_next_scheduled(ZWCACHE_CRON_HOOK);
        
        if ($next_run) {
            $time_diff = $next_run - time();
            if ($time_diff > 0) {
                printf(
                    '<p>' . esc_html('Next scheduled run: %1$s (in %2$d seconds)') . '</p>',
                    date_i18n('Y-m-d H:i:s', $next_run),
                    $time_diff
                );
            } else {
                echo '<p>' . esc_html('WP-Cron job is scheduled but overdue. This could indicate an issue with WP-Cron.') . '</p>';
            }
        } else {
            echo '<p class="notice notice-error">' . esc_html('WP-Cron job is not scheduled! This is a problem.') . '</p>';
        }
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('zwcache_force_cron', 'zwcache_cron_nonce'); ?>
            <input type="hidden" name="action" value="force_cron">
            <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Force Process Queue Now'); ?>">
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Get queue status section content.
     *
     * @return string HTML content for the queue status section.
     */
    private function get_queue_status_content() {
        ob_start();
        $queued_urls = get_option(ZWCACHE_LOW_PRIORITY_STORE, []);
        $count = count($queued_urls);
        ?>
        <p>
            <?php printf(
                esc_html('URLs currently in queue: %d'),
                $count
            ); ?>
        </p>
        
        <?php if ($count > 0) : ?>
            <form method="post" action="">
                <?php wp_nonce_field('zwcache_clear_queue', 'zwcache_nonce'); ?>
                <input type="hidden" name="action" value="clear_queue">
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Clear Queue'); ?>">
            </form>
            
            <br>
            <details>
                <summary><?php esc_html_e('Show queued URLs'); ?></summary>
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
     * Get a plugin option.
     *
     * @param string $option_name Option name.
     * @param mixed  $default     Default value.
     * @return mixed Option value.
     */
    public function get_option($option_name, $default = false) {
        return isset($this->options[$option_name]) ? $this->options[$option_name] : $default;
    }

    /**
     * Log a debug message.
     *
     * @param string $message The message to log.
     */
    public function debug_log($message) {
        if ($this->get_option('zwcache_debug_mode', false)) {
            error_log('[ZuidWest Cache Manager] ' . $message);
        }
    }

    /**
     * Add custom cron interval.
     *
     * @param array $schedules The existing schedules.
     * @return array The modified schedules.
     */
    public function add_cron_interval($schedules) {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => esc_html('Every minute'),
            ];
        }
        return $schedules;
    }

    /**
     * Handle the transition of a post's status and purge or queue URLs as necessary.
     *
     * @param string  $new_status New status of the post.
     * @param string  $old_status Old status of the post.
     * @param WP_Post $post       Post object.
     */
    public function handle_post_status_change($new_status, $old_status, $post) {
        // Skip if it's an autosave or revision
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return;
        }
        
        $this->debug_log('Post status changed from ' . $old_status . ' to ' . $new_status . ' for post ID ' . $post->ID . '.');
        
        // Only take action on publish, update, or unpublish
        if ($new_status === 'publish' || $old_status === 'publish') {
            // High priority URLs for immediate purging
            $high_priority_urls = [
                get_permalink($post->ID),
                get_home_url(),
                get_home_url() . '/feed/',
                get_post_type_archive_link($post->post_type)
            ];
            
            // Filter out any invalid URLs
            $high_priority_urls = array_filter($high_priority_urls);
            
            // Remove duplicates
            $high_priority_urls = array_unique($high_priority_urls);
            
            // Purge high priority URLs immediately
            $this->purge_urls($high_priority_urls);
            
            // Queue low priority URLs for batch processing
            $taxonomy_urls = $this->get_associated_taxonomy_urls($post->ID);
            if (!empty($taxonomy_urls)) {
                $this->queue_low_priority_urls($taxonomy_urls);
            }
        }
    }

    /**
     * Queue URLs for low-priority batch processing.
     *
     * @param array $urls URLs to be queued.
     */
    public function queue_low_priority_urls($urls) {
        if (empty($urls)) {
            return;
        }

        $queued_urls = get_option(ZWCACHE_LOW_PRIORITY_STORE, []);
        
        // Filter out URLs that are already in the queue
        $new_urls = array_filter($urls, function ($url) use ($queued_urls) {
            return !in_array($url, $queued_urls, true);
        });

        if (!empty($new_urls)) {
            // Make sure we're not exceeding a reasonable limit
            $combined_urls = array_merge($queued_urls, $new_urls);
            $combined_urls = array_slice($combined_urls, 0, 1000); // Limit to 1000 URLs in queue
            
            update_option(ZWCACHE_LOW_PRIORITY_STORE, $combined_urls);
            $this->debug_log('Queued ' . count($new_urls) . ' new URLs for low-priority purging.');
        }
    }

    /**
     * Process queued URLs in batches for low-priority purging.
     */
    public function process_queued_low_priority_urls() {
        $start_time = microtime(true);
        $this->debug_log('Processing queued low-priority URLs. Execution time: ' . date('Y-m-d H:i:s'));
        
        $queued_urls = get_option(ZWCACHE_LOW_PRIORITY_STORE, []);
        
        if (empty($queued_urls)) {
            $this->debug_log('No URLs in queue to process.');
            return;
        }
        
        $batch_size = $this->get_option('zwcache_batch_size', 30);
        $batch = array_slice($queued_urls, 0, $batch_size);
        
        $this->debug_log('About to process batch of ' . count($batch) . ' URLs from a total of ' . count($queued_urls) . ' in queue.');
        
        // Attempt to purge the batch
        $purge_result = $this->purge_urls($batch);
        
        if ($purge_result) {
            // Remove processed URLs from queue
            $queued_urls = array_diff($queued_urls, $batch);
            update_option(ZWCACHE_LOW_PRIORITY_STORE, $queued_urls);
            $this->debug_log('Successfully processed and removed ' . count($batch) . ' URLs from queue. ' . count($queued_urls) . ' URLs remaining.');
        } else {
            $this->debug_log('Failed to process ' . count($batch) . ' URLs. Will retry in next run.');
        }
        
        $end_time = microtime(true);
        $execution_time = $end_time - $start_time;
        $this->debug_log('Queue processing completed in ' . round($execution_time, 4) . ' seconds.');
        
        // Force a log write to ensure it's captured immediately
        if ($this->get_option('zwcache_debug_mode', false)) {
            error_log('[ZuidWest Cache Manager] Cron execution completed at ' . date('Y-m-d H:i:s'));
        }
    }

    /**
     * Retrieve URLs for all taxonomies associated with a given post.
     *
     * @param int $post_id ID of the post.
     * @return array URLs associated with the post's taxonomies.
     */
    public function get_associated_taxonomy_urls($post_id) {
        $this->debug_log('Retrieving associated taxonomy URLs for post ID ' . $post_id . '.');
        $urls = [];
        
        // Get all taxonomies for this post type
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type);
        
        if (empty($taxonomies)) {
            return $urls;
        }
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $term_link = get_term_link($term, $taxonomy);
                    if (!is_wp_error($term_link)) {
                        $urls[] = $term_link;
                    }
                }
            }
        }
        
        // Add author archive if this is a post
        if ($post_type === 'post') {
            $post = get_post($post_id);
            $author_url = get_author_posts_url($post->post_author);
            if ($author_url) {
                $urls[] = $author_url;
            }
        }
        
        $this->debug_log(sprintf('Found %d associated taxonomy and author URLs.', count($urls)));
        return $urls;
    }

    /**
     * Purge the specified URLs through the Cloudflare API.
     *
     * @param array $urls URLs to be purged.
     * @return bool True if purge was successful, false otherwise.
     */
    public function purge_urls($urls) {
        if (empty($urls)) {
            return true; // Nothing to purge
        }
        
        $this->debug_log('Attempting to purge URLs: ' . implode(', ', $urls));
        
        $zone_id = $this->get_option('zwcache_zone_id');
        $api_key = $this->get_option('zwcache_api_key');

        // Check if either the zone ID or API key is empty
        if (empty($zone_id) || empty($api_key)) {
            $this->debug_log('Zone ID or API Key is not set. Aborting cache purge.');
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';
        
        $response = wp_remote_post($api_endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['files' => $urls]),
        ]);

        if (is_wp_error($response)) {
            $this->debug_log('Cache purge request failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);
        
        if ($response_code === 200 && isset($body_json['success']) && $body_json['success'] === true) {
            $this->debug_log('Successfully purged ' . count($urls) . ' URLs.');
            return true;
        } else {
            $error_message = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error';
            $this->debug_log('Cache purge failed with code ' . $response_code . ': ' . $error_message);
            return false;
        }
    }
}

/**
 * Handle plugin activation.
 */
function zwcache_activate() {
    // Clear any existing scheduled hook to avoid duplicates
    wp_clear_scheduled_hook(ZWCACHE_CRON_HOOK);
    
    // Schedule the new event
    $scheduled = wp_schedule_event(time(), 'every_minute', ZWCACHE_CRON_HOOK);
    
    // Log activation with scheduling result
    $options = get_option('zwcache_settings', []);
    if (isset($options['zwcache_debug_mode']) && $options['zwcache_debug_mode']) {
        if ($scheduled) {
            error_log('[ZuidWest Cache Manager] Plugin activated and cron job successfully scheduled.');
        } else {
            error_log('[ZuidWest Cache Manager] Plugin activated but cron job scheduling FAILED.');
        }
    }
}
register_activation_hook(__FILE__, 'zwcache_activate');

/**
 * Handle plugin deactivation.
 */
function zwcache_deactivate() {
    // Log deactivation if debug is enabled
    $options = get_option('zwcache_settings', []);
    if (isset($options['zwcache_debug_mode']) && $options['zwcache_debug_mode']) {
        error_log('[ZuidWest Cache Manager] Plugin deactivated and cron job cleared.');
    }
    
    wp_clear_scheduled_hook(ZWCACHE_CRON_HOOK);
}
register_deactivation_hook(__FILE__, 'zwcache_deactivate');

/**
 * Handle admin actions (queue clearing, connection testing, and force cron).
 */
function zwcache_admin_init() {
    // Only process on our admin page and when an action is set
    if (!isset($_POST['action']) || !current_user_can('manage_options')) {
        return;
    }

    $actions = [
        'clear_queue' => [
            'nonce' => 'zwcache_nonce',
            'nonce_action' => 'zwcache_clear_queue',
            'callback' => function() {
                delete_option(ZWCACHE_LOW_PRIORITY_STORE);
                return ['message' => 'queue_cleared'];
            }
        ],
        'test_connection' => [
            'nonce' => 'zwcache_test_nonce',
            'nonce_action' => 'zwcache_test_connection',
            'callback' => function() {
                $zwcache = ZuidWestCacheManager::get_instance();
                $zone_id = $zwcache->get_option('zwcache_zone_id');
                $api_key = $zwcache->get_option('zwcache_api_key');
                
                if (empty($zone_id) || empty($api_key)) {
                    return ['message' => 'missing_credentials'];
                }
                
                $test_result = $zwcache->test_cloudflare_connection($zone_id, $api_key);
                return [
                    'message' => 'connection_' . ($test_result['success'] ? 'success' : 'error'),
                    'details' => urlencode($test_result['message'])
                ];
            }
        ],
        'force_cron' => [
            'nonce' => 'zwcache_cron_nonce',
            'nonce_action' => 'zwcache_force_cron',
            'callback' => function() {
                $zwcache = ZuidWestCacheManager::get_instance();
                $zwcache->debug_log('Manual execution of queue processing triggered from admin.');
                $zwcache->process_queued_low_priority_urls();
                
                // Re-check cron schedule
                if (!wp_next_scheduled(ZWCACHE_CRON_HOOK)) {
                    $scheduled = wp_schedule_event(time(), 'every_minute', ZWCACHE_CRON_HOOK);
                    $zwcache->debug_log('WP-Cron job was ' . ($scheduled ? 'successfully' : 'unsuccessfully') . ' rescheduled during manual run.');
                }
                
                return ['message' => 'cron_executed'];
            }
        ]
    ];

    $action = $_POST['action'];
    
    if (isset($actions[$action])) {
        $handler = $actions[$action];
        if (isset($_POST[$handler['nonce']]) && wp_verify_nonce($_POST[$handler['nonce']], $handler['nonce_action'])) {
            $result = $handler['callback']();
            $query_args = array_merge(['page' => 'zwcache_manager'], $result);
            wp_redirect(add_query_arg($query_args, admin_url('options-general.php')));
            exit;
        }
    }
}
add_action('admin_init', 'zwcache_admin_init');

/**
 * Display admin notices for the plugin.
 */
function zwcache_admin_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'zwcache_manager' || !isset($_GET['message'])) {
        return;
    }

    $message = sanitize_text_field($_GET['message']);
    $details = isset($_GET['details']) ? urldecode($_GET['details']) : '';
    
    $notices = [
        'queue_cleared' => ['success', 'Cache queue has been cleared.'],
        'connection_success' => ['success', 'Cloudflare API connection successful!' . (!empty($details) ? ' ' . $details : '')],
        'connection_error' => ['error', 'Cloudflare API connection failed: ' . $details],
        'missing_credentials' => ['error', 'Please enter both Zone ID and API Key to test the connection.'],
        'cron_executed' => ['success', 'Queue processing has been manually executed.']
    ];
    
    if (isset($notices[$message])) {
        list($type, $text) = $notices[$message];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }
    
    // Check for broken WP-Cron
    if (isset($_GET['page']) && $_GET['page'] === 'zwcache_manager') {
        $next_run = wp_next_scheduled(ZWCACHE_CRON_HOOK);
        if (!$next_run) {
            echo '<div class="notice notice-error"><p><strong>' . 
                esc_html('Warning:') . ' </strong>' .
                esc_html('The WP-Cron job for cache processing is not scheduled. This will prevent automatic processing of the queue.') . 
                '</p></div>';
        }
    }
}
add_action('admin_notices', 'zwcache_admin_notices');

/**
 * Ensure cron job is scheduled on plugin initialization
 */
function zwcache_ensure_cron_scheduled() {
    if (!wp_next_scheduled(ZWCACHE_CRON_HOOK)) {
        $scheduled = wp_schedule_event(time(), 'every_minute', ZWCACHE_CRON_HOOK);
        
        // Log scheduling attempt
        $options = get_option('zwcache_settings', []);
        if (isset($options['zwcache_debug_mode']) && $options['zwcache_debug_mode']) {
            if ($scheduled) {
                error_log('[ZuidWest Cache Manager] Cron job was missing and has been rescheduled successfully.');
            } else {
                error_log('[ZuidWest Cache Manager] Cron job was missing and rescheduling FAILED.');
            }
        }
    }
}

/**
 * Main function to initialize the plugin.
 */
function zwcache_manager_init() {
    return ZuidWestCacheManager::get_instance();
}

// Initialize the plugin
add_action('plugins_loaded', 'zwcache_manager_init');

// Ensure cron job is scheduled on init as well
add_action('init', 'zwcache_ensure_cron_scheduled');