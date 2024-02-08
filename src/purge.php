<?php

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
            zwcache_debug_log('Removed processed URLs from batch');
        }
    }
    update_option(ZWCACHE_LOW_PRIORITY_STORE, $queued_urls);
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