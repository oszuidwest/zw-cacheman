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
	 * Maximum number of URLs held in the queue. Backpressure so the option
	 * cannot grow without bound if warming falls behind; the oldest entries
	 * are dropped once the cap is reached.
	 */
	private const WARM_QUEUE_MAX = 500;

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

		if ( count( $queue ) > self::WARM_QUEUE_MAX ) {
			$queue = array_slice( $queue, -self::WARM_QUEUE_MAX );
			$this->logger->error( 'Warmer', 'Warm queue exceeded ' . self::WARM_QUEUE_MAX . '; dropped oldest URLs' );
		}

		update_option( ZW_CACHEMAN_WARM_QUEUE, $queue, false );

		$this->schedule_run();
	}

	/**
	 * Warm a bounded batch from the queue, rescheduling while items remain
	 * (cron callback). Keeps each WP-Cron pass short instead of processing a
	 * whole burst at once. Items are removed only after a successful warm, and
	 * the queue is re-read before writing, so a crash mid-batch and URLs queued
	 * concurrently are not lost.
	 */
	public function process_queue(): void {
		if ( ! $this->enabled ) {
			return;
		}

		$queue = get_option( ZW_CACHEMAN_WARM_QUEUE, [] );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$warmed = [];
		foreach ( array_slice( $queue, 0, self::WARM_BATCH_SIZE ) as $url ) {
			if ( $this->warm( $url ) ) {
				$warmed[] = $url;
			}
		}

		// Re-read and drop only the URLs we warmed; anything enqueued in the
		// meantime and any failed fetches stay queued for the next run.
		$queue = get_option( ZW_CACHEMAN_WARM_QUEUE, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}
		$queue = array_values( array_diff( $queue, $warmed ) );
		update_option( ZW_CACHEMAN_WARM_QUEUE, $queue, false );

		if ( ! empty( $queue ) ) {
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
	 * Returns false only on a transport error (WP_Error), so the URL stays
	 * queued for a retry. Any HTTP response — including 4xx/5xx — is treated as
	 * terminal (the URL is dequeued) to avoid retrying a permanently missing
	 * page forever.
	 *
	 * @param string $url URL to warm.
	 * @return bool Whether the URL can be dequeued.
	 */
	private function warm( string $url ): bool {
		if ( '' === $url ) {
			return true;
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
			return false;
		}

		$this->logger->debug( 'Warmer', 'Warmed ' . $url . ' (HTTP ' . wp_remote_retrieve_response_code( $response ) . ')' );
		return true;
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
