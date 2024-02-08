<?php

/**
 * Retrieves a specific option value from the ZuidWest cache plugin settings.
 *
 * @param string $option_name The name of the option to retrieve.
 * @param mixed $default Optional. The default value to return if the option does not exist. Default is false.
 * @return mixed The value of the specified option, or the default value if the option is not found.
 */
function zwcache_get_option($option_name, $default = false)
{
    $options = get_option('zwcache_settings');
    return $options[$option_name] ?? $default;
}

/**
 * Logs a debug message to the PHP error log if debug mode is enabled.
 *
 * @param string $message The debug message to log.
 */
function zwcache_debug_log($message)
{
    if (zwcache_get_option('zwcache_debug_mode', true)) {
        error_log('ZuidWest Cache Manager: ' . $message);
    }
}
