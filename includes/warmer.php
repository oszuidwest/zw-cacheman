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
	 * Option used as an atomic lock for warm-queue mutations.
	 */
	private const WARM_QUEUE_LOCK = 'zw_cacheman_warm_queue_lock';

	/**
	 * Lock lifetime and retry interval. Queue mutations are local option
	 * updates, so the lock should normally be held for only milliseconds.
	 */
	private const WARM_QUEUE_LOCK_TTL_SECONDS        = 30;
	private const WARM_QUEUE_LOCK_RETRY_MICROSECONDS = 50_000;
	private const WARM_QUEUE_LOCK_ATTEMPTS           = 40;

	/**
	 * Header used to authenticate cache-warming requests at Cloudflare.
	 */
	private const WARM_TOKEN_HEADER = 'X-ZW-Cache-Warm-Token';

	/**
	 * Constructor
	 *
	 * @param CachemanLogger $logger  The logger instance.
	 * @param bool           $enabled Whether warming is enabled.
	 */
	public function __construct(
		private CachemanLogger $logger,
		private bool $enabled
	) {
	}

	/**
	 * Whether a valid WAF token is configured in wp-config.php.
	 */
	public static function has_valid_waf_token(): bool {
		return null !== self::configured_waf_token();
	}

	/**
	 * Queue the warmable page URLs from a set of purge items. The queue is
	 * drained in batches by the every-minute cron.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Purged items.
	 */
	public function enqueue( array $items ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$urls = $this->page_urls_from_items( $items );
		if ( empty( $urls ) ) {
			return;
		}

		$this->update_queue(
			function ( array $queue ) use ( $urls ): array {
				$queue = array_values( array_unique( array_merge( $queue, $urls ) ) );

				if ( count( $queue ) > self::WARM_QUEUE_MAX ) {
					$queue = array_slice( $queue, -self::WARM_QUEUE_MAX );
					if ( false === get_transient( 'zw_cacheman_warm_queue_overflow' ) ) {
						$this->logger->error( 'Warmer', 'Warm queue exceeded ' . self::WARM_QUEUE_MAX . '; dropped oldest URLs' );
						set_transient( 'zw_cacheman_warm_queue_overflow', true, 5 * MINUTE_IN_SECONDS );
					}
				}

				return $queue;
			}
		);
	}

	/**
	 * Warm a bounded batch from the queue. Called by the manager after each
	 * purge pass. Keeps each WP-Cron pass short instead of processing a whole
	 * burst at once. Items are removed only after a successful warm, and the
	 * queue is re-read before writing, so a crash mid-batch does not lose
	 * failed URLs and the shared mutation lock preserves concurrently queued
	 * URLs.
	 */
	public function process_queue(): void {
		if ( ! $this->enabled ) {
			// Warming was turned off; drop any URLs still parked in the queue.
			if ( [] !== $this->read_queue() ) {
				$this->clear_queue();
			}
			return;
		}

		$queue = $this->read_queue();
		if ( empty( $queue ) ) {
			return;
		}

		$warmed = [];
		foreach ( array_slice( $queue, 0, self::WARM_BATCH_SIZE ) as $url ) {
			if ( $this->warm( $url ) ) {
				$warmed[] = $url;
			}
		}

		// Re-read and drop only the URLs we warmed; failed fetches stay
		// queued for the next run.
		$this->update_queue(
			static fn ( array $current_queue ): array => array_values( array_diff( $current_queue, $warmed ) )
		);
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
		$headers = [];
		$token   = self::configured_waf_token();
		if ( null !== $token ) {
			$headers[ self::WARM_TOKEN_HEADER ] = $token;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::WARM_TIMEOUT_SECONDS,
				'redirection' => 0,
				'user-agent'  => 'ZWCacheMan-Warmer',
				'headers'     => $headers,
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
	 * Read the warm queue option, normalizing a corrupt value to an empty list.
	 *
	 * @return array<string> Queued URLs.
	 */
	private function read_queue(): array {
		$queue = get_option( ZW_CACHEMAN_WARM_QUEUE, [] );
		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Delete the warm queue under the shared option lock.
	 */
	private function clear_queue(): void {
		$lock = $this->acquire_queue_lock();
		if ( null === $lock ) {
			return;
		}

		try {
			delete_option( ZW_CACHEMAN_WARM_QUEUE );
		} finally {
			$this->release_queue_lock( $lock );
		}
	}

	/**
	 * Atomically mutate the warm queue under the shared option lock.
	 *
	 * @param callable(array<string>): array<string> $update Queue mutation.
	 * @return bool Whether the queue was updated.
	 */
	private function update_queue( callable $update ): bool {
		$lock = $this->acquire_queue_lock();
		if ( null === $lock ) {
			return false;
		}

		try {
			update_option( ZW_CACHEMAN_WARM_QUEUE, $update( $this->read_queue() ), false );
			return true;
		} finally {
			$this->release_queue_lock( $lock );
		}
	}

	/**
	 * Acquire the shared warm-queue lock.
	 *
	 * The add_option() function provides the atomic uncontended path. An
	 * expired lock is replaced with a compare-and-swap update so only one
	 * waiter can steal it.
	 *
	 * @return string|null Lock value owned by this request, or null on timeout.
	 */
	private function acquire_queue_lock(): ?string {
		global $wpdb;

		$lock = ( time() + self::WARM_QUEUE_LOCK_TTL_SECONDS ) . '|' . wp_generate_uuid4();

		for ( $attempt = 0; $attempt < self::WARM_QUEUE_LOCK_ATTEMPTS; $attempt++ ) {
			if ( add_option( self::WARM_QUEUE_LOCK, $lock, '', false ) ) {
				return $lock;
			}

			$current_lock = get_option( self::WARM_QUEUE_LOCK, '' );
			if ( is_string( $current_lock ) && (int) $current_lock < time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A conditional update is required for atomic stale-lock recovery.
				$updated = $wpdb->update(
					$wpdb->options,
					[ 'option_value' => $lock ],
					[
						'option_name'  => self::WARM_QUEUE_LOCK,
						'option_value' => $current_lock,
					],
					[ '%s' ],
					[ '%s', '%s' ]
				);
				wp_cache_delete( self::WARM_QUEUE_LOCK, 'options' );

				if ( 1 === $updated ) {
					return $lock;
				}
			}

			usleep( self::WARM_QUEUE_LOCK_RETRY_MICROSECONDS );
		}

		$this->logger->error( 'Warmer', 'Could not acquire the warm queue lock; queue update skipped' );
		return null;
	}

	/**
	 * Release the lock only when it is still owned by this request.
	 *
	 * @param string $lock Lock value returned by acquire_queue_lock().
	 */
	private function release_queue_lock( string $lock ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The owner check prevents an expired lock from releasing its replacement.
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => self::WARM_QUEUE_LOCK,
				'option_value' => $lock,
			],
			[ '%s', '%s' ]
		);
		wp_cache_delete( self::WARM_QUEUE_LOCK, 'options' );
	}

	/**
	 * Read and validate the cache-warming token from wp-config.php.
	 *
	 * Tokens are deliberately not stored in the WordPress database. A
	 * 32-byte random value encoded as 64 hexadecimal characters is required.
	 *
	 * @return string|null Valid token, or null when missing or invalid.
	 */
	private static function configured_waf_token(): ?string {
		if ( ! defined( 'ZW_CACHEMAN_WARM_TOKEN' ) ) {
			return null;
		}

		$token = constant( 'ZW_CACHEMAN_WARM_TOKEN' );
		if ( ! is_string( $token ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/i', $token ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Reduce purge items to warmable front-end page URLs.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Purge items.
	 * @return array<string> Page URLs.
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

			$urls[] = $url;
		}

		return $urls;
	}
}
