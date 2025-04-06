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
     * Default settings
     *
     * @var array
     */
    private $default_settings = [
        'zone_id' => '',
        'api_key' => '',
        'batch_size' => 30,
        'debug_mode' => false
    ];

    /**
     * Constructor
     *
     * @param CachemanManager $manager The manager instance.
     * @param CachemanAPI     $api     The API instance.
     */
    public function __construct($manager, $api)
    {
        $this->manager = $manager;
        $this->api = $api;

        // Register admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_notices', [$this, 'admin_notices']);
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
                'type' => 'array',
                'sanitize_callback' => $sanitize_callback,
                'description' => __('Settings for ZuidWest Cache Manager', 'zw-cacheman'),
            ]
        );

        add_settings_section(
            'zw_cacheman_main_section',
            __('Cloudflare API Settings', 'zw-cacheman'),
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
        $sanitized['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

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

        // Sanitize checkbox
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? 1 : 0;

        // Test API connection if credentials changed
        $credentials_changed = (
            $sanitized['zone_id'] !== $old_settings['zone_id'] ||
            $sanitized['api_key'] !== $old_settings['api_key']
        );

        if ($credentials_changed && !empty($sanitized['zone_id']) && !empty($sanitized['api_key'])) {
            $test_result = $this->api->test_connection($sanitized['zone_id'], $sanitized['api_key']);

            if ($test_result['success']) {
                add_settings_error(
                    'zw_cacheman_settings',
                    'connection_success',
                    __('Cloudflare API connection successful!', 'zw-cacheman') . ' ' . $test_result['message'],
                    'success'
                );
            } else {
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ZuidWest Cache Manager Settings', 'zw-cacheman'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('zw_cacheman_settings');
                do_settings_sections('zw_cacheman_settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php echo esc_html__('Connection Test', 'zw-cacheman'); ?></h2>
            <?php if (empty($settings['zone_id']) || empty($settings['api_key'])) : ?>
                <p><?php echo esc_html__('Please enter your Cloudflare Zone ID and API Key to test the connection.', 'zw-cacheman'); ?></p>
            <?php else : ?>
                <p><?php echo esc_html__('Test your Cloudflare API connection:', 'zw-cacheman'); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field('zw_cacheman_test_connection', 'zw_cacheman_test_nonce'); ?>
                    <input type="hidden" name="zw_cacheman_action" value="test_connection">
                    <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Test Connection', 'zw-cacheman'); ?>">
                </form>
            <?php endif; ?>
            
            <hr>
            
            <h2><?php echo esc_html__('WP-Cron Status', 'zw-cacheman'); ?></h2>
            <?php if ($next_run) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: 1: Formatted date, 2: Number of seconds until next run */
                        esc_html__('Next scheduled run: %1$s (in %2$d seconds)', 'zw-cacheman'),
                        date_i18n('Y-m-d H:i:s', $next_run),
                        $next_run - time()
                    );
                    ?>
                </p>
            <?php else : ?>
                <p class="notice notice-error"><?php echo esc_html__('WP-Cron job is not scheduled! This will prevent automatic processing.', 'zw-cacheman'); ?></p>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('zw_cacheman_force_cron', 'zw_cacheman_cron_nonce'); ?>
                <input type="hidden" name="zw_cacheman_action" value="force_cron">
                <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Force Process Queue Now', 'zw-cacheman'); ?>">
            </form>
            
            <hr>
            
            <h2><?php echo esc_html__('Queue Status', 'zw-cacheman'); ?></h2>
            <p><?php
                /* translators: %d: Number of URLs in queue */
                printf(esc_html__('URLs currently in queue: %d', 'zw-cacheman'), $queue_count);
            ?></p>
            
            <?php if ($queue_count > 0) : ?>
                <form method="post" action="">
                    <?php wp_nonce_field('zw_cacheman_clear_queue', 'zw_cacheman_queue_nonce'); ?>
                    <input type="hidden" name="zw_cacheman_action" value="clear_queue">
                    <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Clear Queue', 'zw-cacheman'); ?>">
                </form>
                
                <br>
                
                <details>
                    <summary><?php echo esc_html__('Show queued URLs', 'zw-cacheman'); ?></summary>
                    <div style="max-height: 200px; overflow-y: auto; margin-top: 10px; padding: 10px; background: #f8f8f8; border: 1px solid #ddd;">
                        <ol>
                            <?php foreach ($queue as $url) : ?>
                                <li><?php echo esc_url($url); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle admin actions (test connection, force cron, clear queue)
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
                    $result = $this->api->test_connection($settings['zone_id'], $settings['api_key']);
                    $redirect_args = [
                        'zw_message' => $result['success'] ? 'connection_success' : 'connection_error',
                        'zw_details' => urlencode($result['message'])
                    ];
                }
                break;

            case 'force_cron':
                check_admin_referer('zw_cacheman_force_cron', 'zw_cacheman_cron_nonce');
                $this->manager->process_queue();

                // Ensure cron is scheduled
                if (!wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
                    wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
                }

                $redirect_args = ['zw_message' => 'cron_executed'];
                break;

            case 'clear_queue':
                check_admin_referer('zw_cacheman_clear_queue', 'zw_cacheman_queue_nonce');
                delete_option(ZW_CACHEMAN_QUEUE);
                $redirect_args = ['zw_message' => 'queue_cleared'];
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
                'queue_cleared' => ['success', __('Cache queue has been cleared.', 'zw-cacheman')],
                'connection_success' => ['success', __('Cloudflare API connection successful!', 'zw-cacheman') . ' ' . $details],
                'connection_error' => ['error', __('Cloudflare API connection failed: ', 'zw-cacheman') . $details],
                'missing_credentials' => ['error', __('Please enter both Zone ID and API Key to test the connection.', 'zw-cacheman')],
                'cron_executed' => ['success', __('Queue processing has been manually executed.', 'zw-cacheman')]
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
