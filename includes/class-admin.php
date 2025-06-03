<?php
/**
 * Admin interface for ZuidWest Cache Manager
 */

namespace ZW_CACHEMAN_Core;

/**
 * Handles the admin interface and settings
 */
class CachemanAdmin
{
    /**
     * Cache Manager instance
     *
     * @var CachemanManager
     */
    private $manager;

    /**
     * API instance
     *
     * @var CachemanAPI
     */
    private $api;

    /**
     * Logger instance
     *
     * @var CachemanLogger
     */
    private $logger;

    /**
     * Default settings
     *
     * @var array
     */
    private $default_settings = [
        'zone_id'    => '',
        'api_key'    => '',
        'batch_size' => 30,
        'debug_mode' => false
    ];

    /**
     * Constructor
     *
     * @param CachemanManager $manager The manager instance.
     * @param CachemanAPI     $api     The API instance.
     * @param CachemanLogger  $logger  The logger instance.
     */
    public function __construct($manager, $api, $logger)
    {
        $this->manager = $manager;
        $this->api     = $api;
        $this->logger  = $logger;

        // Register admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    /**
     * Enqueue admin styles
     *
     * @param string $hook The current admin page.
     * @return void
     */
    public function enqueue_admin_styles($hook)
    {
        // Only load on our settings page
        if ('settings_page_zw_cacheman_settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'zw-cacheman-admin',
            ZW_CACHEMAN_URL . 'assets/admin-style.css',
            [],
            '1.5.0.' . time()
        );
    }

    /**
     * Add admin menu page
     *
     * @return void
     */
    public function add_admin_menu()
    {
        add_options_page(
            __('ZuidWest Cache Manager Settings', 'zw-cacheman'),
            __('ZuidWest Cache', 'zw-cacheman'),
            'manage_options',
            'zw_cacheman_settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings
     *
     * @return void
     */
    public function register_settings()
    {
        // Define a static sanitize callback for better security
        $sanitize_callback = function ($input) {
            // Forward to instance method while maintaining static entry point
            return $this->sanitize_settings($input);
        };

        register_setting(
            'zw_cacheman_settings',
            ZW_CACHEMAN_SETTINGS,
            [
                'type'              => 'array',
                'sanitize_callback' => $sanitize_callback,
                'description'       => __('Settings for ZuidWest Cache Manager', 'zw-cacheman'),
            ]
        );

        add_settings_section(
            'zw_cacheman_main_section',
            '',
            function () {
                echo '<p>' . esc_html__('Enter your Cloudflare API settings below:', 'zw-cacheman') . '</p>';
            },
            'zw_cacheman_settings'
        );

        // Add settings fields
        add_settings_field(
            'zone_id',
            __('Zone ID', 'zw-cacheman'),
            [$this, 'render_field'],
            'zw_cacheman_settings',
            'zw_cacheman_main_section',
            ['name' => 'zone_id', 'type' => 'text']
        );

        add_settings_field(
            'api_key',
            __('API Key', 'zw-cacheman'),
            [$this, 'render_field'],
            'zw_cacheman_settings',
            'zw_cacheman_main_section',
            ['name' => 'api_key', 'type' => 'password']
        );

        add_settings_field(
            'batch_size',
            __('Batch Size', 'zw-cacheman'),
            [$this, 'render_field'],
            'zw_cacheman_settings',
            'zw_cacheman_main_section',
            ['name' => 'batch_size', 'type' => 'number', 'default' => 30]
        );

        add_settings_field(
            'debug_mode',
            __('Debug Mode', 'zw-cacheman'),
            [$this, 'render_field'],
            'zw_cacheman_settings',
            'zw_cacheman_main_section',
            ['name' => 'debug_mode', 'type' => 'checkbox']
        );
    }

    /**
     * Render settings field
     *
     * @param array $args Field arguments.
     * @return void
     */
    public function render_field($args)
    {
        $settings = get_option(ZW_CACHEMAN_SETTINGS, $this->default_settings);
        $name = $args['name'];
        $value = isset($settings[$name]) ? $settings[$name] : (isset($args['default']) ? $args['default'] : '');

        switch ($args['type']) {
            case 'text':
            case 'password':
                printf(
                    '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
                    esc_attr($args['type']),
                    esc_attr($name),
                    esc_attr(ZW_CACHEMAN_SETTINGS),
                    esc_attr($name),
                    esc_attr($value)
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
                    esc_attr($name),
                    esc_attr(ZW_CACHEMAN_SETTINGS),
                    esc_attr($name),
                    esc_attr($value)
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s[%s]" value="1" %s />',
                    esc_attr($name),
                    esc_attr(ZW_CACHEMAN_SETTINGS),
                    esc_attr($name),
                    checked(1, $value, false)
                );
                break;
        }
    }

    /**
     * Sanitize settings
     *
     * @param array $input Raw input values.
     * @return array Sanitized values.
     */
    public function sanitize_settings($input)
    {
        $sanitized = [];
        $old_settings = get_option(ZW_CACHEMAN_SETTINGS, $this->default_settings);

        // Sanitize text fields
        $sanitized['zone_id'] = isset($input['zone_id']) ? sanitize_text_field($input['zone_id']) : '';
        $sanitized['api_key'] = isset($input['api_key']) && !empty($input['api_key'])
            ? sanitize_text_field($input['api_key'])
            : $old_settings['api_key']; // Keep old API key if empty (to prevent accidental clear)

        // Sanitize numeric fields
        $sanitized['batch_size'] = isset($input['batch_size']) ? intval($input['batch_size']) : 30;
        if ($sanitized['batch_size'] < 1) {
            $sanitized['batch_size'] = 30;
            add_settings_error(
                'zw_cacheman_settings',
                'invalid_batch_size',
                __('Batch size must be at least 1. Reset to default (30).', 'zw-cacheman'),
                'error'
            );
        }

        // Sanitize checkbox to boolean
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? true : false;

        // If debug mode setting changed, update the logger
        if ($sanitized['debug_mode'] !== $old_settings['debug_mode']) {
            $this->logger->set_debug_mode($sanitized['debug_mode']);

            if ($sanitized['debug_mode']) {
                $this->logger->debug('Admin', 'Debug mode enabled');
            }
        }

        // Test API connection if credentials changed
        $credentials_changed = (
            $sanitized['zone_id'] !== $old_settings['zone_id'] ||
            $sanitized['api_key'] !== $old_settings['api_key']
        );

        if ($credentials_changed && !empty($sanitized['zone_id']) && !empty($sanitized['api_key'])) {
            $test_result = $this->api->test_connection($sanitized['zone_id'], $sanitized['api_key']);

            if ($test_result['success']) {
                // Cache successful connection status for 1 hour
                set_transient('zw_cacheman_connection_status', 'connected', HOUR_IN_SECONDS);

                add_settings_error(
                    'zw_cacheman_settings',
                    'connection_success',
                    __('Cloudflare API connection successful!', 'zw-cacheman') . ' ' . $test_result['message'],
                    'success'
                );
            } else {
                // Clear connection status on failure
                delete_transient('zw_cacheman_connection_status');

                add_settings_error(
                    'zw_cacheman_settings',
                    'connection_error',
                    __('Cloudflare API connection failed: ', 'zw-cacheman') . $test_result['message'],
                    'error'
                );
            }
        }

        return $sanitized;
    }

    /**
     * Render the settings page
     *
     * @return void
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, $this->default_settings);
        $queue = get_option(ZW_CACHEMAN_QUEUE, []);
        $queue_count = count($queue);
        $next_run = wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK);

        // Check if we have credentials
        $has_credentials = !empty($settings['zone_id']) && !empty($settings['api_key']);

        // Get cached connection status (valid for 1 hour)
        $connection_status = get_transient('zw_cacheman_connection_status');
        $is_connected = $has_credentials && $connection_status === 'connected';
        ?>
        <div class="wrap zw-cacheman-wrap">
            <h1><?php echo esc_html__('ZuidWest Cache Manager', 'zw-cacheman'); ?></h1>
            
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        
                        <div class="postbox">
                            <h2><?php echo esc_html__('Cloudflare API Settings', 'zw-cacheman'); ?></h2>
                            <div class="inside">
                                <form method="post" action="options.php">
                                    <?php
                                    settings_fields('zw_cacheman_settings');
                                    do_settings_sections('zw_cacheman_settings');
                                    submit_button();
                                    ?>
                                </form>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h2><?php echo esc_html__('Queue Management', 'zw-cacheman'); ?></h2>
                            <div class="inside">
                                <?php if ($queue_count > 0) : ?>
                                    <p>
                                        <form method="post" action="" style="display: inline;">
                                            <?php wp_nonce_field('zw_cacheman_force_cron', 'zw_cacheman_cron_nonce'); ?>
                                            <input type="hidden" name="zw_cacheman_action" value="force_cron">
                                            <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Process Queue Now', 'zw-cacheman'); ?>">
                                        </form>
                                    </p>
                                    
                                    <details class="zw-cacheman-queue">
                                        <summary>
                                            <span><?php echo esc_html__('View queued items', 'zw-cacheman'); ?></span>
                                            <span class="zw-cacheman-badge"><?php echo esc_html((string) $queue_count); ?></span>
                                        </summary>
                                        <div class="zw-cacheman-details-content">
                                            <?php foreach ($queue as $item) : ?>
                                                <div class="zw-cacheman-list-item">
                                                    <code class="zw-cacheman-queue-type">
                                                        <?php echo esc_html(strtoupper($item['type'])); ?>
                                                    </code>
                                                    <span class="zw-cacheman-break-word">
                                                        <?php echo esc_url($item['url']); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                    
                                    <div style="text-align: right; margin-top: 15px;">
                                        <form method="post" action="" style="display: inline;">
                                            <?php wp_nonce_field('zw_cacheman_clear_queue', 'zw_cacheman_queue_nonce'); ?>
                                            <input type="hidden" name="zw_cacheman_action" value="clear_queue">
                                            <a href="#" class="zw-cacheman-danger-link" onclick="if(confirm('<?php echo esc_js(__('Are you sure you want to clear the queue?', 'zw-cacheman')); ?>')) { this.closest('form').submit(); } return false;"><?php echo esc_html__('Clear queue', 'zw-cacheman'); ?></a>
                                        </form>
                                    </div>
                                <?php else : ?>
                                    <p><?php echo esc_html__('The queue is empty. URLs will be added automatically when content changes.', 'zw-cacheman'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($settings['debug_mode'])) : ?>
                        <div class="postbox">
                            <h2><?php echo esc_html__('Debug Logs', 'zw-cacheman'); ?></h2>
                            <div class="inside">
                                <p>
                                    <strong><?php echo esc_html__('Log Directory:', 'zw-cacheman'); ?></strong><br>
                                    <code style="background: #f0f0f0; padding: 4px 8px; display: inline-block; margin-top: 5px;"><?php echo esc_html(dirname($this->logger->get_current_log_path())); ?></code>
                                </p>
                                
                                <?php
                                $log_files = $this->logger->get_log_files(30); // Get last 30 days of logs
                                if (!empty($log_files)) :
                                    ?>
                                    <h4><?php echo esc_html__('Available Log Files:', 'zw-cacheman'); ?></h4>
                                    <div class="mt-15">
                                        <?php
                                        foreach ($log_files as $log_file) :
                                            $filename = basename($log_file);
                                            $date_match = [];
                                            if (preg_match('/debug-(\d{4}-\d{2}-\d{2})\.log/', $filename, $date_match)) {
                                                $log_date = $date_match[1];
                                                $display_date = date_i18n(get_option('date_format'), strtotime($log_date));
                                                $file_size = filesize($log_file);
                                                $file_size_human = size_format($file_size);
                                                ?>
                                                <details class="zw-cacheman-log-file">
                                                    <summary>
                                                        <span><?php echo esc_html($display_date); ?></span>
                                                        <span class="zw-cacheman-badge"><?php echo esc_html($file_size_human); ?></span>
                                                    </summary>
                                                    <div class="zw-cacheman-details-content">
                                                        <?php
                                                        $log_content = @file_get_contents($log_file);
                                                        if ($log_content) {
                                                            $lines = explode("\n", trim($log_content));
                                                            $total_lines = count($lines);
                                                            $display_lines = array_slice($lines, -100); // Show last 100 lines

                                                            if ($total_lines > 100) {
                                                                echo '<p class="zw-cacheman-log-truncated">';
                                                                /* translators: %d: number of log entries */
                                                                printf(esc_html__('Showing last 100 of %d entries', 'zw-cacheman'), $total_lines);
                                                                echo '</p>';
                                                            }
                                                            ?>
                                                            <pre class="zw-cacheman-log-viewer"><?php echo esc_html(implode("\n", $display_lines)); ?></pre>
                                                            <?php
                                                        } else {
                                                            echo '<p>' . esc_html__('Unable to read log file.', 'zw-cacheman') . '</p>';
                                                        }
                                                        ?>
                                                    </div>
                                                </details>
                                                <?php
                                            }
                                        endforeach;
                                        ?>
                                    </div>
                                <?php else : ?>
                                    <p><?php echo esc_html__('No log files found.', 'zw-cacheman'); ?></p>
                                <?php endif; ?>
                                
                                <div style="text-align: right; margin-top: 20px;">
                                    <form method="post" action="" style="display: inline;">
                                        <?php wp_nonce_field('zw_cacheman_clear_logs', 'zw_cacheman_clear_logs_nonce'); ?>
                                        <input type="hidden" name="zw_cacheman_action" value="clear_logs">
                                        <a href="#" class="zw-cacheman-danger-link" onclick="if(confirm('<?php echo esc_js(__('Delete all log files? This cannot be undone.', 'zw-cacheman')); ?>')) { this.closest('form').submit(); } return false;"><?php echo esc_html__('Clear all logs', 'zw-cacheman'); ?></a>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                    
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2><?php echo esc_html__('Dashboard', 'zw-cacheman'); ?></h2>
                            <div class="inside">
                                <div class="main-status">
                                    <p class="zw-cacheman-status-item">
                                        <strong><?php echo esc_html__('Connection Status', 'zw-cacheman'); ?></strong><br>
                                        <?php if ($is_connected) : ?>
                                            <span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__('Connected to Cloudflare', 'zw-cacheman'); ?>
                                        <?php elseif ($has_credentials) : ?>
                                            <span class="dashicons dashicons-warning"></span> <?php echo esc_html__('Connection failed', 'zw-cacheman'); ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-dismiss"></span> <?php echo esc_html__('No credentials', 'zw-cacheman'); ?>
                                        <?php endif; ?>
                                    </p>
                                    
                                    <p class="zw-cacheman-status-item">
                                        <strong><?php echo esc_html__('Queue Status', 'zw-cacheman'); ?></strong><br>
                                        <span class="dashicons dashicons-list-view <?php echo $queue_count > 0 ? 'zw-cacheman-has-items' : 'zw-cacheman-empty'; ?>"></span>
                                        <?php
                                        if ($queue_count > 0) {
                                            printf(
                                                /* translators: %d: number of URLs in the queue */
                                                esc_html(_n('%d URL pending', '%d URLs pending', $queue_count, 'zw-cacheman')),
                                                $queue_count
                                            );
                                        } else {
                                            echo esc_html__('Queue empty', 'zw-cacheman');
                                        }
                                        ?>
                                    </p>
                                    
                                    <p class="zw-cacheman-status-item">
                                        <strong><?php echo esc_html__('Cron Status', 'zw-cacheman'); ?></strong><br>
                                        <?php if ($next_run) : ?>
                                            <span class="dashicons dashicons-backup"></span> 
                                            <?php
                                            $seconds = $next_run - time();
                                            if ($seconds > 0) {
                                                $minutes = floor($seconds / 60);
                                                if ($minutes > 0) {
                                                    /* translators: %d: minutes until next run */
                                                    printf(esc_html__('Next run in %d min', 'zw-cacheman'), $minutes);
                                                } else {
                                                    /* translators: %d: seconds until next run */
                                                    printf(esc_html__('Next run in %ds', 'zw-cacheman'), $seconds);
                                                }
                                            } else {
                                                echo esc_html__('Running soon', 'zw-cacheman');
                                            }
                                            ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-warning zw-cacheman-error"></span> <?php echo esc_html__('Not scheduled', 'zw-cacheman'); ?>
                                        <?php endif; ?>
                                    </p>
                                    
                                    <p class="zw-cacheman-status-item">
                                        <strong><?php echo esc_html__('Debug Mode', 'zw-cacheman'); ?></strong><br>
                                        <?php echo $settings['debug_mode'] ?
                                            '<span class="dashicons dashicons-info"></span> ' . esc_html__('Logging enabled', 'zw-cacheman') :
                                            '<span class="dashicons dashicons-minus"></span> ' . esc_html__('Logging disabled', 'zw-cacheman'); ?>
                                    </p>
                                </div>
                                
                                <?php if ($has_credentials) : ?>
                                <hr>
                                <p>
                                    <form method="post" action="">
                                        <?php wp_nonce_field('zw_cacheman_test_connection', 'zw_cacheman_test_nonce'); ?>
                                        <input type="hidden" name="zw_cacheman_action" value="test_connection">
                                        <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Test Connection', 'zw-cacheman'); ?>" style="width: 100%;">
                                    </form>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle admin actions (test connection, force cron, clear queue, etc.)
     *
     * @return void
     */
    public function handle_admin_actions()
    {
        if (!isset($_POST['zw_cacheman_action']) || !current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['zw_cacheman_action']));

        switch ($action) {
            case 'test_connection':
                check_admin_referer('zw_cacheman_test_connection', 'zw_cacheman_test_nonce');
                $settings = get_option(ZW_CACHEMAN_SETTINGS, $this->default_settings);

                if (empty($settings['zone_id']) || empty($settings['api_key'])) {
                    $redirect_args = ['zw_message' => 'missing_credentials'];
                } else {
                    $this->logger->debug('Admin', 'Manual connection test initiated');
                    $result = $this->api->test_connection($settings['zone_id'], $settings['api_key']);

                    // Update connection status transient
                    if ($result['success']) {
                        set_transient('zw_cacheman_connection_status', 'connected', HOUR_IN_SECONDS);
                    } else {
                        delete_transient('zw_cacheman_connection_status');
                    }

                    $redirect_args = [
                        'zw_message' => $result['success'] ? 'connection_success' : 'connection_error',
                        'zw_details' => urlencode($result['message'])
                    ];
                }
                break;

            case 'force_cron':
                check_admin_referer('zw_cacheman_force_cron', 'zw_cacheman_cron_nonce');
                $this->logger->debug('Admin', 'Manual queue processing initiated');
                $this->manager->process_queue();

                // Ensure cron is scheduled
                if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
                    wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
                    $this->logger->debug('Admin', 'Re-scheduled missing cron job');
                }

                $redirect_args = ['zw_message' => 'cron_executed'];
                break;

            case 'clear_queue':
                check_admin_referer('zw_cacheman_clear_queue', 'zw_cacheman_queue_nonce');
                $queue = get_option(ZW_CACHEMAN_QUEUE, []);
                $queue_count = count($queue);
                $this->logger->debug('Admin', 'Manually cleared queue with ' . $queue_count . ' items');
                delete_option(ZW_CACHEMAN_QUEUE);
                $redirect_args = ['zw_message' => 'queue_cleared'];
                break;


            case 'clear_logs':
                check_admin_referer('zw_cacheman_clear_logs', 'zw_cacheman_clear_logs_nonce');
                $success = $this->logger->clear_logs();
                $this->logger->debug('Admin', 'All logs cleared');
                $redirect_args = [
                    'zw_message' => $success ? 'logs_cleared' : 'logs_clear_failed'
                ];
                break;

            default:
                return;
        }

        // Redirect back to settings page with message
        wp_redirect(add_query_arg(
            array_merge(['page' => 'zw_cacheman_settings'], $redirect_args),
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Display admin notices
     *
     * @return void
     */
    public function admin_notices()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'zw_cacheman_settings') {
            return;
        }

        // Check for status message
        if (isset($_GET['zw_message'])) {
            $message = sanitize_text_field(wp_unslash($_GET['zw_message']));
            $details = isset($_GET['zw_details']) ? urldecode(sanitize_text_field(wp_unslash($_GET['zw_details']))) : '';

            $notices = [
                'queue_cleared'     => ['success', __('Cache queue has been cleared.', 'zw-cacheman')],
                'connection_success' => ['success', __('Cloudflare API connection successful!', 'zw-cacheman') . ' ' . $details],
                'connection_error'  => ['error', __('Cloudflare API connection failed: ', 'zw-cacheman') . $details],
                'missing_credentials' => ['error', __('Please enter both Zone ID and API Key to test the connection.', 'zw-cacheman')],
                'cron_executed'     => ['success', __('Queue processing has been manually executed.', 'zw-cacheman')],
                'logs_cleared'      => ['success', __('All log files have been deleted.', 'zw-cacheman')],
                'logs_clear_failed' => ['error', __('Failed to delete some log files.', 'zw-cacheman')]
            ];

            if (isset($notices[$message])) {
                list($type, $text) = $notices[$message];
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr($type),
                    esc_html($text)
                );
            }
        }

        // Check if cron is scheduled
        if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Warning:', 'zw-cacheman') . '</strong> ';
            echo esc_html__('The WP-Cron job for cache processing is not scheduled. This will prevent automatic processing of the queue.', 'zw-cacheman');
            echo '</p></div>';
        }
    }
}
