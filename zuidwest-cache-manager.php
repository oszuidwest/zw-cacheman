<?php
/**
 * Plugin Name: ZuidWest Cache Manager
 * Description: Purges cache for high-priority URLs immediately on post publishing and edits. Also queues associated taxonomy URLs for low-priority batch processing via WP-Cron.
 * Version: 0.1
 * Author: Streekomroep ZuidWest
 */

add_action('admin_menu', 'zwcache_add_admin_menu');
function zwcache_add_admin_menu()
{
    add_options_page('ZuidWest Cache Manager Settings', 'ZuidWest Cache', 'manage_options', 'zwcache_manager', 'zwcache_options_page');
}

add_action('admin_init', 'zwcache_settings_init');
function zwcache_settings_init()
{
    register_setting('zwcachePlugin', 'zwcache_settings');
    add_settings_section('zwcache_pluginPage_section', 'API Settings', 'zwcache_settings_section_callback', 'zwcachePlugin');

    $fields = [
        ['zwcache_zone_id', 'Zone ID', 'text'],
        ['zwcache_api_key', 'API Key', 'text'],
        ['zwcache_batch_size', 'Batch Size', 'number', 30],
        ['zwcache_debug_mode', 'Debug Mode', 'checkbox', 1]
    ];

    foreach ($fields as $field) {
        add_settings_field($field[0], $field[1], 'zwcache_render_settings_field', 'zwcachePlugin', 'zwcache_pluginPage_section', ['label_for' => $field[0], 'type' => $field[2], 'default' => $field[3] ?? '']);
    }
}

function zwcache_render_settings_field($args)
{
    $options = get_option('zwcache_settings');
    $value = $options[$args['label_for']] ?? $args['default'];
    $checked = ($value) ? 'checked' : '';

    echo match ($args['type']) {
        'text', 'number' => "<input type='" . $args['type'] . "' id='" . $args['label_for'] . "' name='zwcache_settings[" . $args['label_for'] . "]' value='" . $value . "'>",
        'checkbox' => "<input type='checkbox' id='" . $args['label_for'] . "' name='zwcache_settings[" . $args['label_for'] . "]' value='1' " . $checked . '>',
        default => ''
    };
}

function zwcache_settings_section_callback()
{
    echo 'Enter your settings below:';
}

function zwcache_options_page()
{
    ?>
    <div class="wrap">
        <h2>ZuidWest Cache Manager Settings</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields('zwcachePlugin');
            do_settings_sections('zwcachePlugin');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function zwcache_get_option($option_name, $default = false)
{
    $options = get_option('zwcache_settings');
    return $options[$option_name] ?? $default;
}

/**
 * Custom log function.
 *
 * @param string $message The message to log.
 */
function zwcache_debug_log($message)
{
    if (zwcache_get_option('zwcache_debug_mode', true)) {
        error_log('ZuidWest Cache Manager: ' . $message);
    }
}

define('ZWCACHE_LOW_PRIORITY_STORE', 'zwcache_purge_urls');
define('ZWCACHE_CRON_HOOK', 'zwcache_manager_cron_hook');

register_activation_hook(__FILE__, 'zwcache_activate');
register_deactivation_hook(__FILE__, 'zwcache_deactivate');

/**
 * Adds custom cron schedule if it doesn't already exist.
 *
 * @param array $schedules The existing schedules.
 * @return array The modified schedules.
 */
function zwcache_add_cron_interval($schedules)
{
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => esc_html__('Every Minute'),
        ];
    }
    return $schedules;
}

/**
 * Activates the plugin and schedules a WP-Cron event if not already scheduled.
 */
function zwcache_activate()
{
    if (!wp_next_scheduled(ZWCACHE_CRON_HOOK)) {
        wp_schedule_event(time(), 'every_minute', ZWCACHE_CRON_HOOK);
    }
    add_filter('cron_schedules', 'zwcache_add_cron_interval');
    zwcache_debug_log('Plugin activated and cron job scheduled.');
}

/**
 * Deactivates the plugin, clears the scheduled WP-Cron event, and deletes the option storing low-priority URLs.
 */
function zwcache_deactivate()
{
    wp_clear_scheduled_hook(ZWCACHE_CRON_HOOK);
    delete_option(ZWCACHE_LOW_PRIORITY_STORE);
    zwcache_debug_log('Plugin deactivated and cron job cleared.');
}

add_action('transition_post_status', 'zwcache_handle_post_status_change', 10, 3);

/**
 * Handles the transition of a post's status and purges or queues URLs for purging as necessary.
 *
 * @param string   $new_status New status of the post.
 * @param string   $old_status Old status of the post.
 * @param WP_Post  $post       Post object.
 */
function zwcache_handle_post_status_change($new_status, $old_status, $post)
{
    zwcache_debug_log('Post status changed from ' . $old_status . ' to ' . $new_status . ' for post ID ' . $post->ID . '.');
    if ($new_status === 'publish' || $old_status === 'publish') {
        zwcache_purge_urls([get_permalink($post->ID), get_home_url()]);
        zwcache_queue_low_priority_urls(zwcache_get_associated_taxonomy_urls($post->ID));
    }
}

/**
 * Queues URLs for low-priority batch processing.
 *
 * @param array $urls URLs to be queued.
 */
function zwcache_queue_low_priority_urls($urls)
{
    $queued_urls = get_option(ZWCACHE_LOW_PRIORITY_STORE, []);
    $new_urls = array_filter($urls, function ($url) use ($queued_urls) {
        return !in_array($url, $queued_urls);
    });

    if (!empty($new_urls)) {
        update_option(ZWCACHE_LOW_PRIORITY_STORE, array_merge($queued_urls, $new_urls));
        zwcache_debug_log('Queued new URLs for low-priority purging.');
    }
}

add_action(ZWCACHE_CRON_HOOK, 'zwcache_process_queued_low_priority_urls');

/**
 * Processes queued URLs in batches for low-priority purging.
 */
function zwcache_process_queued_low_priority_urls()
{
    zwcache_debug_log('Processing queued low-priority URLs.');
    $queued_urls = get_option(ZWCACHE_LOW_PRIORITY_STORE, []);
    if (empty($queued_urls)) {
        zwcache_debug_log('No URLs in queue to process.');
        return;
    }
    $batch_size = zwcache_get_option('zwcache_batch_size', 30);
    $total_urls = count($queued_urls);
    for ($i = 0; $i < $total_urls; $i += $batch_size) {
        $batch = array_slice($queued_urls, $i, $batch_size);
        if (zwcache_purge_urls($batch)) {
            $queued_urls = array_diff($queued_urls, $batch);
            zwcache_debug_log('Successfully purged a batch of URLs.');
        }
    }
    update_option(ZWCACHE_LOW_PRIORITY_STORE, $queued_urls);
}

/**
 * Retrieves URLs for all taxonomies associated with a given post.
 *
 * @param int $post_id ID of the post.
 * @return array URLs associated with the post's taxonomies.
 */
function zwcache_get_associated_taxonomy_urls($post_id)
{
    zwcache_debug_log('Retrieving associated taxonomy URLs for post ID ' . $post_id . '.');
    $urls = [];
    $taxonomies = get_post_taxonomies($post_id);
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
    zwcache_debug_log(sprintf('Found %d associated taxonomy URLs.', count($urls)));
    return $urls;
}

/**
 * Purges the specified URLs through the Cloudflare API.
 *
 * @param array $urls URLs to be purged.
 * @return bool True if purge was successful, false otherwise.
 */
function zwcache_purge_urls($urls)
{
    zwcache_debug_log('Attempting to purge URLs: ' . implode(', ', $urls));
    $zone_id = zwcache_get_option('zwcache_zone_id');
    $api_key = zwcache_get_option('zwcache_api_key');

    // Check if either the zone ID or API key is empty
    if (empty($zone_id) || empty($api_key)) {
        zwcache_debug_log('Zone ID or API Key is not set. Aborting cache purge.');
        return false; // Exit the function if either is not set
    }

    $apiEndpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';
    $response = wp_remote_post($apiEndpoint, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['files' => $urls]),
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        zwcache_debug_log('Cache purge failed: ' . wp_remote_retrieve_body($response));
        return false;
    } else {
        zwcache_debug_log('Successfully purged URLs.');
        return true;
    }
}
