<?php

/**
 * Cloudflare API integration class
 */

namespace ZW_CACHEMAN_Core;

/**
 * API handler for Cloudflare cache purging
 */
readonly class CachemanAPI
{
    /**
     * Constructor
     *
     * @param CachemanUrlHelper $url_helper The URL helper instance.
     * @param CachemanLogger    $logger     The logger instance.
     */
    public function __construct(
        private CachemanUrlHelper $url_helper,
        private CachemanLogger $logger
    ) {
    }

    /**
     * Purge URLs via Cloudflare API
     *
     * @param array<string> $urls URLs to purge.
     * @return bool Success or failure.
     */
    public function purge_urls(array $urls): bool
    {
        if (empty($urls)) {
            return true;
        }

        // Use URL helper to clean and validate URLs
        $clean_urls = $this->url_helper->clean_urls($urls);

        if (empty($clean_urls)) {
            $this->logger->debug('API', 'No valid URLs to purge after cleaning and filtering');
            return true;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, array());
        $zone_id = !empty($settings['zone_id']) ? $settings['zone_id'] : '';
        $api_key = !empty($settings['api_key']) ? $settings['api_key'] : '';

        if (empty($zone_id) || empty($api_key)) {
            $this->logger->error('API', 'Cloudflare credentials missing. Cannot purge URLs.');
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';

        $request_body = wp_json_encode(array(
            'files' => $clean_urls
        ));

        $this->logger->debug('API', 'Sending request to Cloudflare with ' . count($clean_urls) . ' URLs');
        $this->logger->debug('API', 'Request body: ' . $request_body);

        $response = wp_remote_post($api_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => $request_body,
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('API', 'API request failed: ' . $error_message);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $this->logger->debug('API', 'Successfully purged ' . count($clean_urls) . ' URLs');
            return true;
        } else {
            $error = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error';
            $error_code = isset($body_json['errors'][0]['code']) ? $body_json['errors'][0]['code'] : 'Unknown code';

            // Log the error with both HTTP and API codes
            $this->logger->error('API', 'Failed to purge URLs. HTTP Code: ' . $response_code . ', API Error Code: ' . $error_code . ', Message: ' . $error);
            $this->logger->error('API', 'Response body: ' . $body);

            // Log the failed URLs
            $this->logger->error('API', 'Failed to purge the following URLs: ' . implode(', ', array_slice($clean_urls, 0, 5)) .
                (count($clean_urls) > 5 ? ' and ' . (count($clean_urls) - 5) . ' more.' : ''));

            return false;
        }
    }

    /**
     * Purge URL prefixes via Cloudflare API
     *
     * @param array<string> $prefixes URL prefixes to purge.
     * @return bool Success or failure.
     */
    public function purge_url_prefixes(array $prefixes): bool
    {
        if (empty($prefixes)) {
            return true;
        }

        // Use URL helper to clean and validate prefixes
        $clean_prefixes = $this->url_helper->clean_prefixes($prefixes);

        if (empty($clean_prefixes)) {
            $this->logger->debug('API', 'No valid prefixes to purge after filtering');
            return true;
        }

        $settings = get_option(ZW_CACHEMAN_SETTINGS, array());
        $zone_id = !empty($settings['zone_id']) ? $settings['zone_id'] : '';
        $api_key = !empty($settings['api_key']) ? $settings['api_key'] : '';

        if (empty($zone_id) || empty($api_key)) {
            $this->logger->error('API', 'Cloudflare credentials missing. Cannot purge prefixes.');
            return false;
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';

        $request_body = wp_json_encode(array(
            'prefixes' => $clean_prefixes
        ));

        $this->logger->debug('API', 'Sending request to Cloudflare with ' . count($clean_prefixes) . ' prefixes');
        $this->logger->debug('API', 'Request body: ' . $request_body);

        $response = wp_remote_post($api_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => $request_body,
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('API', 'API request failed: ' . $error_message);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $this->logger->debug('API', 'Successfully purged ' . count($clean_prefixes) . ' prefixes');
            return true;
        } else {
            $error = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error';
            $error_code = isset($body_json['errors'][0]['code']) ? $body_json['errors'][0]['code'] : 'Unknown code';

            // Log the error with both HTTP and API codes
            $this->logger->error('API', 'Failed to purge prefixes. HTTP Code: ' . $response_code . ', API Error Code: ' . $error_code . ', Message: ' . $error);
            $this->logger->error('API', 'Response body: ' . $body);

            // Log the failed prefixes
            $this->logger->error('API', 'Failed to purge the following prefixes: ' . implode(', ', array_slice($clean_prefixes, 0, 5)) .
                (count($clean_prefixes) > 5 ? ' and ' . (count($clean_prefixes) - 5) . ' more.' : ''));

            return false;
        }
    }

    /**
     * Process purge items (handles both file and prefix purging)
     *
     * @param array<array{type: PurgeType, url: string}> $purge_items Items to purge
     * @return bool Success status
     */
    public function process_purge_items(array $purge_items): bool
    {
        if (empty($purge_items)) {
            return true;
        }

        $this->logger->debug('API', 'Processing ' . count($purge_items) . ' purge items');

        // Separate items by type
        $files = array();
        $prefixes = array();

        foreach ($purge_items as $item) {
            if ($item['type'] === PurgeType::File) {
                $files[] = $item['url'];
            } elseif ($item['type'] === PurgeType::Prefix) {
                $prefixes[] = $item['url'];
            }
        }

        // Purge individual files
        $files_success = true;
        if (!empty($files)) {
            $files_success = $this->purge_urls($files);
            if ($files_success) {
                $this->logger->debug('API', 'Successfully purged ' . count($files) . ' individual URLs');
            } else {
                $this->logger->error('API', 'Failed to purge ' . count($files) . ' individual URLs');
            }
        }

        // Purge prefixes in batches of 30 (Cloudflare limit)
        $prefixes_success = true;
        if (!empty($prefixes)) {
            $batch_size = 30;
            $prefix_chunks = array_chunk($prefixes, $batch_size);
            $failed_chunks = 0;

            foreach ($prefix_chunks as $index => $chunk) {
                $success = $this->purge_url_prefixes($chunk);
                if (!$success) {
                    $prefixes_success = false;
                    $failed_chunks++;
                    $this->logger->error('API', 'Failed to purge prefix batch #' . ($index + 1) . ' of ' . count($prefix_chunks));
                }
            }

            if ($prefixes_success) {
                $this->logger->debug('API', 'Successfully purged all ' . count($prefixes) . ' URL prefixes');
            } else {
                $this->logger->error('API', 'Failed to purge ' . $failed_chunks . ' out of ' . count($prefix_chunks) . ' prefix batches (' .
                    count($prefixes) . ' total prefixes)');
            }
        }

        return $files_success && $prefixes_success;
    }

    /**
     * Test Cloudflare API connection
     *
     * @param string $zone_id The Cloudflare Zone ID.
     * @param string $api_key The Cloudflare API Key.
     * @return array{success: bool, message: string, data?: array{zone_name: string, plan_name: string}} Result with success status and message.
     */
    public function test_connection(string $zone_id, #[\SensitiveParameter] string $api_key): array
    {
        if (empty($zone_id) || empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('Zone ID and API Key are required', 'zw-cacheman')
            );
        }

        $api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id;

        $this->logger->debug('API', 'Testing connection to Cloudflare API for zone ID: ' . $zone_id);

        $response = wp_remote_get($api_endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            )
        ));

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->logger->error('API', 'Connection test failed with WP error: ' . $error_msg);
            return array(
                'success' => false,
                'message' => $error_msg
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $body_json = json_decode($body, true);

        if (200 === $response_code && isset($body_json['success']) && true === $body_json['success']) {
            $zone_name = isset($body_json['result']['name']) ? $body_json['result']['name'] : 'unknown';
            $plan_name = isset($body_json['result']['plan']['name']) ? $body_json['result']['plan']['name'] : 'unknown';

            $this->logger->debug('API', 'Connection test successful. Zone: ' . $zone_name . ', Plan: ' . $plan_name);

            return array(
                'success' => true,
                'message' => sprintf(
                    /* translators: %1$s: Cloudflare zone name, %2$s: Cloudflare plan name */
                    __('Connected to zone: %1$s (Plan: %2$s)', 'zw-cacheman'),
                    $zone_name,
                    $plan_name
                ),
                'data' => array(
                    'zone_name' => $zone_name,
                    'plan_name' => $plan_name
                )
            );
        } else {
            $error = isset($body_json['errors'][0]['message']) ? $body_json['errors'][0]['message'] : 'Unknown error';
            $error_code = isset($body_json['errors'][0]['code']) ? $body_json['errors'][0]['code'] : 'Unknown code';

            $message = sprintf(
                /* translators: %1$d: HTTP response code, %2$s: API error code, %3$s: Error message */
                __('HTTP code: %1$d, API code: %2$s, Message: %3$s', 'zw-cacheman'),
                $response_code,
                $error_code,
                $error
            );

            $this->logger->error('API', 'Connection test failed: ' . $message);

            return array(
                'success' => false,
                'message' => $message
            );
        }
    }
}
