<?php
if (! defined('ABSPATH')) {
    exit;
}

namespace ZW_CACHEMAN_Core;

/**
 * Class CacheManagerAdmin
 *
 * Handles the plugin's admin interface including settings pages, admin actions, and notices.
 */
class CacheManagerAdmin
{
    /**
     * Core CacheManager instance.
     *
     * @var CacheManager
     */
    protected $cache_manager;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->cache_manager = CacheManager::get_instance();

        // Register the admin menu and settings.
        add_action('admin_menu', [ $this, 'add_admin_menu' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);

        // Process admin actions and display notices.
        add_action('admin_init', [ $this, 'process_admin_actions' ]);
        add_action('admin_notices', [ $this, 'admin_notices' ]);
    }

    /**
     * Adds an options page to the WordPress admin.
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
     * Registers the plugin settings and fields.
     */
    public function register_settings()
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

        $fields = [
            [ 'zw_cacheman_zone_id', 'Zone ID', 'text', '' ],
            [ 'zw_cacheman_api_key', 'API Key', 'password', '' ],
            [ 'zw_cacheman_batch_size', 'Batch Size', 'number', 30 ],
            [ 'zw_cacheman_debug_mode', 'Debug Mode', 'checkbox', 0 ],
        ];

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
     * Callback to output the settings section description.
     */
    public function settings_section_callback()
    {
        echo '<p>' . esc_html__('Enter your Cloudflare API settings below:', 'zw-cacheman') . '</p>';
    }

    /**
     * Renders an individual settings field.
     *
     * @param array $args Field arguments.
     */
    public function render_settings_field($args)
    {
        $options  = get_option('zw_cacheman_settings', []);
        $value    = isset($options[ $args['label_for'] ]) ? $options[ $args['label_for'] ] : $args['default'];
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
     * Renders the complete options page.
     */
    public function render_options_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ZuidWest Cache Manager Settings', 'zw-cacheman'); ?></h1>
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
     * Helper to render a titled section.
     *
     * @param string $title   Section title.
     * @param string $content Section HTML content.
     * @return string Rendered HTML.
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
     * Generates HTML for the Cloudflare connection test section.
     *
     * @return string
     */
    private function get_connection_test_content()
    {
        ob_start();
        $options = get_option('zw_cacheman_settings', []);
        $zone_id = isset($options['zw_cacheman_zone_id']) ? $options['zw_cacheman_zone_id'] : '';
        $api_key = isset($options['zw_cacheman_api_key']) ? $options['zw_cacheman_api_key'] : '';
        if (empty($zone_id) || empty($api_key)) {
            echo '<p>' . esc_html__('Please enter your Cloudflare Zone ID and API Key to test the connection.', 'zw-cacheman') . '</p>';
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
     * Generates HTML for the WP-Cron status section.
     *
     * @return string
     */
    private function get_cron_status_content()
    {
        ob_start();
        $next_run = wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK);
        if ($next_run) {
            $time_diff = $next_run - time();
            printf(
                '<p>' . esc_html__('Next scheduled run: %1$s (in %2$d seconds)', 'zw-cacheman') . '</p>',
                date_i18n('Y-m-d H:i:s', $next_run),
                $time_diff
            );
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
     * Generates HTML for the URL queue status section.
     *
     * @return string
     */
    private function get_queue_status_content()
    {
        ob_start();
        $queued_urls = get_option(ZW_CACHEMAN_LOW_PRIORITY_STORE, []);
        $count = count($queued_urls);
        ?>
        <p><?php printf(esc_html__('URLs currently in queue: %d', 'zw-cacheman'), $count); ?></p>
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
        <?php endif;
        return ob_get_clean();
    }

    /**
     * Processes admin POST actions such as clearing the queue, testing the connection, or forcing the cron run.
     */
    public function process_admin_actions()
    {
        if (! isset($_POST['action']) || ! current_user_can('manage_options')) {
            return;
        }
        $action = sanitize_text_field(wp_unslash($_POST['action']));

        if ('clear_queue' === $action) {
            check_admin_referer('zw_cacheman_clear_queue', 'zw_cacheman_nonce');
            delete_option(ZW_CACHEMAN_LOW_PRIORITY_STORE);
            $result = [ 'message' => 'queue_cleared' ];
        } elseif ('test_connection' === $action) {
            check_admin_referer('zw_cacheman_test_connection', 'zw_cacheman_test_nonce');
            $zone_id = isset($_POST['zw_cacheman_zone_id']) ? sanitize_text_field(wp_unslash($_POST['zw_cacheman_zone_id'])) : '';
            $api_key = isset($_POST['zw_cacheman_api_key']) ? sanitize_text_field(wp_unslash($_POST['zw_cacheman_api_key'])) : '';
            if (empty($zone_id) || empty($api_key)) {
                $result = [ 'message' => 'missing_credentials' ];
            } else {
                $connection_result = $this->cache_manager->test_cloudflare_connection($zone_id, $api_key);
                $result = [
                    'message' => 'connection_' . ( $connection_result['success'] ? 'success' : 'error' ),
                    'details' => urlencode($connection_result['message']),
                ];
            }
        } elseif ('force_cron' === $action) {
            check_admin_referer('zw_cacheman_force_cron', 'zw_cacheman_cron_nonce');
            $this->cache_manager->debug_log('Manual execution of queue processing triggered from admin.');
            $this->cache_manager->process_queued_low_priority_urls();
            if (! wp_next_scheduled(ZW_CACHEMAN_CRON_HOOK)) {
                wp_schedule_event(time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK);
            }
            $result = [ 'message' => 'cron_executed' ];
        } else {
            return;
        }
        $query_args = array_merge([ 'page' => 'zw_cacheman_plugin' ], $result);
        wp_redirect(add_query_arg($query_args, admin_url('options-general.php')));
        exit;
    }

    /**
     * Displays admin notices based on actions.
     */
    public function admin_notices()
    {
        if (! isset($_GET['page']) || 'zw_cacheman_plugin' !== $_GET['page'] || ! isset($_GET['message'])) {
            return;
        }

        $message = sanitize_text_field(wp_unslash($_GET['message']));
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
}
