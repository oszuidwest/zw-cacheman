<?php
if (! defined('ABSPATH')) {
    exit;
}

namespace ZW_CACHEMAN_Core;

/**
 * Class CacheManager_API
 *
 * Handles communication with the Cloudflare API.
 */
class CacheManager_API
{
    /**
     * Singleton instance.
     *
     * @var CacheManager_API
     */
    private static $instance;

    /**
     * Returns the singleton instance.
     *
     * @return CacheManager_API
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Purges the given URLs via the Cloudflare API.
     *
     * @param array $urls URLs to purge.
     * @return bool True on success, false on failure.
     */
    public function purge_urls($urls)
    {
        if (empty($urls)) {
            return true;
        }

        $zone_id = get_option('zw_cacheman_zone_id');
        $api_key = get_option('zw_cacheman_api_key');
        if (empty($zone_id) || empty($api_key)) {
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';
        $response = wp_remote_post(
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
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Tests the Cloudflare API connection.
     *
     * @param string $zone_id The Cloudflare Zone ID.
     * @param string $api_key The Cloudflare API Key.
     * @return array Array containing 'success' and 'message'.
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
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $zone_name = isset($body_json['result']['name']) ? $body_json['result']['name'] : 'unknown';
            return [
                'success' => true,
                'message' => sprintf('Connected to zone: %s', $zone_name),
            ];
        } else {
            $error_message = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : sprintf('Unknown error (HTTP code: %d)', $response_code);
            return [
                'success' => false,
                'message' => $error_message,
            ];
        }
    }
}
