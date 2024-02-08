<?php

// Registers a new item in the WordPress admin menu for the ZuidWest Cache Manager settings page.
function zwcache_add_admin_menu()
{
    add_options_page('ZuidWest Cache Manager Settings', 'ZuidWest Cache', 'manage_options', 'zwcache_manager', 'zwcache_options_page');
}

// Initializes settings for the ZuidWest Cache Manager plugin.
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

/**
 * Renders a settings field.
 *
 * @param array $args Arguments containing the settings field configuration.
 */
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

// Callback function for the settings section.
function zwcache_settings_section_callback()
{
    echo 'Enter your settings below:';
}

// Renders the ZuidWest Cache Manager settings page.
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
