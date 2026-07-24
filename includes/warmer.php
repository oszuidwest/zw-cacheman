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
	 * Header used to authenticate cache-warming requests at Cloudflare.
	 */
	private const WARM_TOKEN_HEADER = 'X-ZW-Cache-Warm-Token';

	/**
	 * Validated WAF token, or null when it is missing or invalid.
	 *
	 * @var string|null
	 */
	private ?string $waf_token;

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
		$this->waf_token = self::configured_waf_token();

		// Purge first (priority 10), then drain the warm queue.
		add_action( ZW_CACHEMAN_CRON_HOOK, $this->process_queue( ... ), 20 );
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
	}

	/**
	 * Warm a bounded batch from the queue (cron callback). Keeps each WP-Cron
	 * pass short instead of processing a whole burst at once. Items are removed
	 * only after a successful warm, and the queue is re-read before writing, so
	 * a crash mid-batch and URLs queued concurrently are not lost.
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
		$queue = array_values( array_diff( (array) get_option( ZW_CACHEMAN_WARM_QUEUE, [] ), $warmed ) );
		update_option( ZW_CACHEMAN_WARM_QUEUE, $queue, false );
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
		$headers = [ 'X-ZW-Cache-Warm' => '1' ];
		if ( null !== $this->waf_token ) {
			$headers[ self::WARM_TOKEN_HEADER ] = $this->waf_token;
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
