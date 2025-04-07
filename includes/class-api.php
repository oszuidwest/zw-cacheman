<?php
/**
 * Cloudflare API integration class
 */

namespace ZW_CACHEMAN_Core;

/**
 * API handler for Cloudflare cache purging
 */
class CachemanAPI
{
    /**
     * Log debug messages
     *
     * @param string $message The message to log.
     * @return void
     */
    public function debug_log($message)
    {
        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        if (!empty($settings['debug_mode'])) {
            error_log('[ZW Cacheman API] ' . $message);
        }
    }

    /**
     * Purge URLs via Cloudflare API
     *
     * @param array $urls URLs to purge.
     * @return bool Success or failure.
     */
    public function purge_urls($urls)
    {
        if (empty($urls)) {
            return true;
        }

        // Filter out any empty or invalid URLs
        $clean_urls = array_values(array_filter($urls, function ($url) {
            return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
        }));

        if (empty($clean_urls)) {
            $this->debug_log('No valid URLs to purge after filtering');
            return true;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        $zone_id = !empty($settings['zone_id']) ? $settings['zone_id'] : '';
        $api_key = !empty($settings['api_key']) ? $settings['api_key'] : '';

        if (empty($zone_id) || empty($api_key)) {
            $this->debug_log('Cloudflare credentials missing. Cannot purge URLs.');
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';

        $request_body = wp_json_encode([
            'files' => $clean_urls
        ]);

        $this->debug_log('Sending request to Cloudflare with ' . count($clean_urls) . ' URLs');
        $this->debug_log('Request body: ' . $request_body);

        $response = wp_remote_post($api_endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => $request_body,
        ]);

        if (is_wp_error($response)) {
            $this->debug_log('API request failed: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $this->debug_log('Successfully purged ' . count($clean_urls) . ' URLs');
            return true;
        } else {
            $error = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error (HTTP ' . $response_code . ')';
            $this->debug_log('API request failed: ' . $error);
            $this->debug_log('Response body: ' . $body);
            return false;
        }
    }

    /**
     * Purge URL prefixes via Cloudflare API
     *
     * @param array $prefixes URL prefixes to purge.
     * @return bool Success or failure.
     */
    public function purge_url_prefixes($prefixes)
    {
        if (empty($prefixes)) {
            return true;
        }

        // Filter out any empty prefixes
        $clean_prefixes = array_values(array_filter($prefixes, function ($prefix) {
            return !empty($prefix);
        }));

        if (empty($clean_prefixes)) {
            $this->debug_log('No valid prefixes to purge after filtering');
            return true;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        $zone_id = !empty($settings['zone_id']) ? $settings['zone_id'] : '';
        $api_key = !empty($settings['api_key']) ? $settings['api_key'] : '';

        if (empty($zone_id) || empty($api_key)) {
            $this->debug_log('Cloudflare credentials missing. Cannot purge prefixes.');
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';

        $request_body = wp_json_encode([
            'prefixes' => $clean_prefixes
        ]);

        $this->debug_log('Sending request to Cloudflare with ' . count($clean_prefixes) . ' prefixes');
        $this->debug_log('Request body: ' . $request_body);

        $response = wp_remote_post($api_endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => $request_body,
        ]);

        if (is_wp_error($response)) {
            $this->debug_log('API request failed: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $this->debug_log('Successfully purged ' . count($clean_prefixes) . ' prefixes');
            return true;
        } else {
            $error = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error (HTTP ' . $response_code . ')';
            $this->debug_log('API request failed: ' . $error);
            $this->debug_log('Response body: ' . $body);
            return false;
        }
    }

    /**
     * Process purge items (handles both file and prefix purging)
     *
     * @param array $purge_items Items to purge
     * @return bool Success status
     */
    public function process_purge_items($purge_items)
    {
        if (empty($purge_items)) {
            return true;
        }

        $this->debug_log('Processing ' . count($purge_items) . ' purge items');

        // Separate items by type
        $files = [];
        $prefixes = [];

        foreach ($purge_items as $item) {
            if ($item['type'] === 'file') {
                $files[] = $item['url'];
            } elseif ($item['type'] === 'prefix') {
                $prefixes[] = $item['url'];
            }
        }

        // Purge individual files
        $files_success = true;
        if (!empty($files)) {
            $files_success = $this->purge_urls($files);
            $this->debug_log('Purged ' . count($files) . ' individual URLs: ' . ($files_success ? 'success' : 'failed'));
        }

        // Purge prefixes in batches of 30 (Cloudflare limit)
        $prefixes_success = true;
        if (!empty($prefixes)) {
            $batch_size = 30;
            $prefix_chunks = array_chunk($prefixes, $batch_size);

            foreach ($prefix_chunks as $chunk) {
                $success = $this->purge_url_prefixes($chunk);
                if (!$success) {
                    $prefixes_success = false;
                    $this->debug_log('Failed to purge prefix batch');
                    break;
                }
            }

            $this->debug_log('Purged ' . count($prefixes) . ' URL prefixes: ' . ($prefixes_success ? 'success' : 'failed'));
        }

        return $files_success && $prefixes_success;
    }

    /**
     * Test Cloudflare API connection
     *
     * @param string $zone_id The Cloudflare Zone ID.
     * @param string $api_key The Cloudflare API Key.
     * @return array Result with success status and message.
     */
    public function test_connection($zone_id, $api_key)
    {
        if (empty($zone_id) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('Zone ID and API Key are required', 'zw-cacheman')
            ];
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id;

        $response = wp_remote_get($api_endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
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

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $zone_name = isset($body_json['result']['name']) ? $body_json['result']['name'] : 'unknown';
            $plan_name = isset($body_json['result']['plan']['name']) ? $body_json['result']['plan']['name'] : 'unknown';

            return [
                'success' => true,
                /* translators: %1$s: Cloudflare zone name, %2$s: Cloudflare plan name */
                'message' => sprintf(__('Connected to zone: %1$s (Plan: %2$s)', 'zw-cacheman'), $zone_name, $plan_name),
                'data' => [
                    'zone_name' => $zone_name,
                    'plan_name' => $plan_name
                ]
            ];
        } else {
            $error = isset($body_json['errors'][0]['message'])
                ? $body_json['errors'][0]['message']
                /* translators: %d: HTTP response code */
                : sprintf(__('Unknown error (HTTP code: %d)', 'zw-cacheman'), $response_code);
            return [
                'success' => false,
                'message' => $error
            ];
        }
    }
}
