<?php
/**
 * Cloudflare API integration class.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API handler for Cloudflare cache purging.
 */
readonly class CachemanAPI {

	/**
	 * Cloudflare's per-request limit for single-file purge requests
	 * (verified: the API rejects 101 files with error 1094 on a Business zone).
	 */
	private const FILE_BATCH_SIZE = 100;

	/**
	 * Conservative batch size for prefix purge requests. The API caps prefix
	 * purges at 100 per request (error 1117); 30 stays well under the limit
	 * across plan types. Also the upper bound for the batch_size setting, so
	 * one queue pass sends at most one files and one prefixes request.
	 */
	public const PREFIX_BATCH_SIZE = 30;

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
	 * Purge URLs via Cloudflare API. Returns false if any batch failed.
	 *
	 * @param array<string> $urls URLs to purge.
	 * @return bool Success or failure.
	 */
	public function purge_urls( array $urls ): bool {
		if ( empty( $urls ) ) {
			return true;
		}

		// Use URL helper to clean and validate URLs.
		$clean_urls = $this->url_helper->clean_urls( $urls );

		if ( empty( $clean_urls ) ) {
			$this->logger->debug( 'API', 'No valid URLs to purge after cleaning and filtering' );
			return true;
		}

		return $this->purge_in_batches( $clean_urls, self::FILE_BATCH_SIZE, 'files' );
	}

	/**
	 * Purge URL prefixes via Cloudflare API
	 *
	 * @param array<string> $prefixes URL prefixes to purge.
	 * @return bool Success or failure.
	 */
	public function purge_url_prefixes( array $prefixes ): bool {
		if ( empty( $prefixes ) ) {
			return true;
		}

		// Use URL helper to clean and validate prefixes.
		$clean_prefixes = $this->url_helper->clean_prefixes( $prefixes );

		if ( empty( $clean_prefixes ) ) {
			$this->logger->debug( 'API', 'No valid prefixes to purge after filtering' );
			return true;
		}

		return $this->purge_in_batches( $clean_prefixes, self::PREFIX_BATCH_SIZE, 'prefixes' );
	}

	/**
	 * Purge items in batches. Returns false if any batch failed.
	 *
	 * @param array<string> $items       Items to purge.
	 * @param int           $batch_size  Batch size.
	 * @param string        $payload_key Cloudflare payload key ('files' or 'prefixes'), also used as log noun.
	 * @return bool
	 */
	private function purge_in_batches( array $items, int $batch_size, string $payload_key ): bool {
		$credentials = $this->get_credentials();
		if ( null === $credentials ) {
			$this->logger->error( 'API', 'Cloudflare credentials missing. Cannot purge ' . $payload_key . '.' );
			return false;
		}

		$batches = array_chunk( $items, $batch_size );

		if ( count( $batches ) > 1 ) {
			$this->logger->debug( 'API', 'Splitting ' . count( $items ) . ' ' . $payload_key . ' into ' . count( $batches ) . ' batches of ' . $batch_size );
		}

		$failed_batches = 0;
		foreach ( $batches as $index => $batch ) {
			if ( ! $this->send_purge_request( $payload_key, $batch, $credentials ) ) {
				++$failed_batches;
				$this->logger->error( 'API', 'Failed to purge ' . $payload_key . ' batch #' . ( $index + 1 ) . ' of ' . count( $batches ) );
			}
		}

		return 0 === $failed_batches;
	}

	/**
	 * Read the Cloudflare zone ID and API key from settings.
	 *
	 * @return array{zone_id: string, api_key: string}|null Credentials, or null when unconfigured.
	 */
	private function get_credentials(): ?array {
		$settings = get_option( ZW_CACHEMAN_SETTINGS, array() );

		if ( empty( $settings['zone_id'] ) || empty( $settings['api_key'] ) ) {
			return null;
		}

		return array(
			'zone_id' => (string) $settings['zone_id'],
			'api_key' => (string) $settings['api_key'],
		);
	}

	/**
	 * Send a Cloudflare purge_cache request.
	 *
	 * @param string                                  $payload_key Cloudflare payload key ('files' or 'prefixes'), also used as log noun.
	 * @param array<string>                           $items       Items to purge.
	 * @param array{zone_id: string, api_key: string} $credentials Cloudflare credentials.
	 * @return bool
	 */
	private function send_purge_request( string $payload_key, array $items, array $credentials ): bool {
		$api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $credentials['zone_id'] . '/purge_cache';

		$request_body = wp_json_encode(
			array(
				$payload_key => $items,
			)
		);
		if ( false === $request_body ) {
			$this->logger->error( 'API', 'Failed to JSON-encode Cloudflare purge request for ' . $payload_key . '.' );
			return false;
		}

		$this->logger->debug( 'API', 'Sending request to Cloudflare with ' . count( $items ) . ' ' . $payload_key );
		$this->logger->debug( 'API', 'Request body: ' . $request_body );

		$response = wp_remote_post(
			$api_endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $credentials['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => $request_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->logger->error( 'API', 'API request failed: ' . $error_message );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$body_json     = json_decode( $body, true );

		if ( 200 === $response_code && isset( $body_json['success'] ) && true === $body_json['success'] ) {
			$this->logger->debug( 'API', 'Successfully purged ' . count( $items ) . ' ' . $payload_key );
			return true;
		}

		$error      = isset( $body_json['errors'][0]['message'] ) ? $body_json['errors'][0]['message'] : 'Unknown error';
		$error_code = isset( $body_json['errors'][0]['code'] ) ? $body_json['errors'][0]['code'] : 'Unknown code';

		// Log the error with both HTTP and API codes.
		$this->logger->error(
			'API',
			'Failed to purge ' . $payload_key . '. HTTP Code: ' . $response_code . ', API Error Code: ' . $error_code . ', Message: ' . $error
		);
		$this->logger->error( 'API', 'Response body: ' . $body );

		// Log the failed items.
		$this->logger->error(
			'API',
			'Failed to purge the following ' . $payload_key . ': ' . implode( ', ', array_slice( $items, 0, 5 ) ) .
			( count( $items ) > 5 ? ' and ' . ( count( $items ) - 5 ) . ' more.' : '' )
		);

		return false;
	}

	/**
	 * Process purge items (handles both file and prefix purging)
	 *
	 * @param array<array{type: PurgeType, url: string}> $purge_items Items to purge.
	 * @return bool Success status
	 */
	public function process_purge_items( array $purge_items ): bool {
		$results = $this->process_purge_items_by_type( $purge_items );

		return $results[ PurgeType::File->value ] && $results[ PurgeType::Prefix->value ];
	}

	/**
	 * Process purge items and preserve the result for each purge type.
	 *
	 * @param array<array{type: PurgeType, url: string}> $purge_items Items to purge.
	 * @return array{file: bool, prefix: bool} Success status keyed by purge type.
	 */
	public function process_purge_items_by_type( array $purge_items ): array {
		if ( empty( $purge_items ) ) {
			return [
				PurgeType::File->value   => true,
				PurgeType::Prefix->value => true,
			];
		}

		$this->logger->debug( 'API', 'Processing ' . count( $purge_items ) . ' purge items' );

		// Separate items by type.
		$files    = array();
		$prefixes = array();

		foreach ( $purge_items as $item ) {
			if ( PurgeType::File === $item['type'] ) {
				$files[] = $item['url'];
			} elseif ( PurgeType::Prefix === $item['type'] ) {
				$prefixes[] = $item['url'];
			}
		}

		// Purge individual files.
		$files_success = true;
		if ( ! empty( $files ) ) {
			$files_success = $this->purge_urls( $files );
			if ( $files_success ) {
				$this->logger->debug( 'API', 'Successfully purged ' . count( $files ) . ' individual URLs' );
			} else {
				$this->logger->error( 'API', 'Failed to purge ' . count( $files ) . ' individual URLs' );
			}
		}

		// Purge prefixes.
		$prefixes_success = true;
		if ( ! empty( $prefixes ) ) {
			$prefixes_success = $this->purge_url_prefixes( $prefixes );
			if ( $prefixes_success ) {
				$this->logger->debug( 'API', 'Successfully purged all ' . count( $prefixes ) . ' URL prefixes' );
			} else {
				$this->logger->error( 'API', 'Failed to purge ' . count( $prefixes ) . ' URL prefixes' );
			}
		}

		return [
			PurgeType::File->value   => $files_success,
			PurgeType::Prefix->value => $prefixes_success,
		];
	}

	/**
	 * Test Cloudflare API connection
	 *
	 * @param string $zone_id The Cloudflare Zone ID.
	 * @param string $api_key The Cloudflare API Key.
	 * @return array{success: bool, message: string, data?: array{zone_name: string, plan_name: string}} Result with success status and message.
	 */
	public function test_connection( string $zone_id, #[\SensitiveParameter] string $api_key ): array {
		if ( empty( $zone_id ) || empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Zone ID and API Key are required', 'zw-cacheman' ),
			);
		}

		$api_endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id;

		$this->logger->debug( 'API', 'Testing connection to Cloudflare API for zone ID: ' . $zone_id );

		$response = wp_remote_get(
			$api_endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			$this->logger->error( 'API', 'Connection test failed with WP error: ' . $error_msg );
			return array(
				'success' => false,
				'message' => $error_msg,
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$body_json     = json_decode( $body, true );

		if ( 200 === $response_code && isset( $body_json['success'] ) && true === $body_json['success'] ) {
			$zone_name = isset( $body_json['result']['name'] ) ? $body_json['result']['name'] : 'unknown';
			$plan_name = isset( $body_json['result']['plan']['name'] ) ? $body_json['result']['plan']['name'] : 'unknown';

			$this->logger->debug( 'API', 'Connection test successful. Zone: ' . $zone_name . ', Plan: ' . $plan_name );

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %1$s: Cloudflare zone name, %2$s: Cloudflare plan name */
					__( 'Connected to zone: %1$s (Plan: %2$s)', 'zw-cacheman' ),
					$zone_name,
					$plan_name
				),
				'data'    => array(
					'zone_name' => $zone_name,
					'plan_name' => $plan_name,
				),
			);
		} else {
			$error      = isset( $body_json['errors'][0]['message'] ) ? $body_json['errors'][0]['message'] : 'Unknown error';
			$error_code = isset( $body_json['errors'][0]['code'] ) ? $body_json['errors'][0]['code'] : 'Unknown code';

			$message = sprintf(
				/* translators: %1$d: HTTP response code, %2$s: API error code, %3$s: Error message */
				__( 'HTTP code: %1$d, API code: %2$s, Message: %3$s', 'zw-cacheman' ),
				$response_code,
				$error_code,
				$error
			);

			$this->logger->error( 'API', 'Connection test failed: ' . $message );

			return array(
				'success' => false,
				'message' => $message,
			);
		}
	}
}
