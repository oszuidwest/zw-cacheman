<?php
/**
 * Cache warming functionality for ZuidWest Cache Manager.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warms page URLs after they are purged.
 */
readonly class CachemanWarmer {

	/**
	 * Request timeout, in seconds, for a single warm fetch.
	 */
	private const WARM_TIMEOUT_SECONDS = 10;

	/**
	 * Maximum number of URLs warmed per cron run. Bounds how long a single
	 * WP-Cron pass can block (batch x timeout stays under the cron lock).
	 */
	private const WARM_BATCH_SIZE = 5;

	/**
	 * Constructor
	 *
	 * @param CachemanLogger $logger  The logger instance.
	 * @param bool           $enabled Whether warming is enabled.
	 */
	public function __construct(
		private CachemanLogger $logger,
		private bool $enabled = false
	) {
		add_action( ZW_CACHEMAN_WARM_HOOK, $this->process_queue( ... ) );
	}

	/**
	 * Queue the warmable page URLs from a set of purge items.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Purged items.
	 */
	public function schedule( array $items ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$urls = $this->page_urls_from_items( $items );
		if ( empty( $urls ) ) {
			return;
		}

		$queue = get_option( ZW_CACHEMAN_WARM_QUEUE, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}
		$queue = array_values( array_unique( array_merge( $queue, $urls ) ) );
		update_option( ZW_CACHEMAN_WARM_QUEUE, $queue, false );

		$this->schedule_run();
	}

	/**
	 * Warm a bounded batch from the queue, rescheduling while items remain
	 * (cron callback). Keeps each WP-Cron pass short instead of processing a
	 * whole burst at once.
	 */
	public function process_queue(): void {
		if ( ! $this->enabled ) {
			return;
		}

		$queue = get_option( ZW_CACHEMAN_WARM_QUEUE, [] );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$batch     = array_slice( $queue, 0, self::WARM_BATCH_SIZE );
		$remaining = array_slice( $queue, self::WARM_BATCH_SIZE );
		update_option( ZW_CACHEMAN_WARM_QUEUE, $remaining, false );

		foreach ( $batch as $url ) {
			$this->warm( $url );
		}

		if ( ! empty( $remaining ) ) {
			$this->schedule_run();
		}
	}

	/**
	 * Ensure a single drain event is scheduled.
	 */
	private function schedule_run(): void {
		if ( wp_next_scheduled( ZW_CACHEMAN_WARM_HOOK ) ) {
			return;
		}

		if ( wp_schedule_single_event( time(), ZW_CACHEMAN_WARM_HOOK ) ) {
			$this->logger->debug( 'Warmer', 'Scheduled warm run' );
		} else {
			$this->logger->error( 'Warmer', 'Failed to schedule warm run' );
		}
	}

	/**
	 * Warm a single URL by fetching it.
	 *
	 * @param string $url URL to warm.
	 */
	private function warm( string $url ): void {
		if ( '' === $url ) {
			return;
		}

		$response = wp_remote_get(
			$url,
			[
				'blocking'    => true,
				'timeout'     => self::WARM_TIMEOUT_SECONDS,
				'redirection' => 0,
				'user-agent'  => 'ZWCacheMan-Warmer',
				'headers'     => [ 'X-ZW-Cache-Warm' => '1' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Warmer', 'Failed to warm ' . $url . ': ' . $response->get_error_message() );
		} else {
			$this->logger->debug( 'Warmer', 'Warmed ' . $url . ' (HTTP ' . wp_remote_retrieve_response_code( $response ) . ')' );
		}
	}

	/**
	 * Reduce purge items to warmable front-end page URLs.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Purge items.
	 * @return array<string> Unique page URLs.
	 */
	private function page_urls_from_items( array $items ): array {
		// Match the REST base by path (from rest_url(), so it includes any
		// subdirectory install path) and compare host-agnostically, so REST
		// URLs on extra domains are excluded too. Guard the plain-permalink
		// case where the REST path is just "/".
		$rest_path = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
		$urls      = [];

		foreach ( $items as $item ) {
			if ( PurgeType::File !== $item['type'] ) {
				continue;
			}

			$url  = $item['url'];
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			if ( '/' !== $rest_path && str_starts_with( $path, $rest_path ) ) {
				continue;
			}

			$urls[ $url ] = $url;
		}

		return array_values( $urls );
	}
}
