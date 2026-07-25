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
 *
 * The queue is a non-autoloaded option mutated with plain read-modify-write,
 * like the purge queue. Warming is best-effort: concurrent writers can lose
 * an enqueue, and overlapping cron runs can fetch a URL more than once. The
 * worst case either way is a page warmed by a visitor instead of the server,
 * or redundant GETs — never a missed purge.
 */
readonly class CachemanWarmer {

	/**
	 * Request timeout, in seconds, for a single warm fetch.
	 */
	private const WARM_TIMEOUT_SECONDS = 10;

	/**
	 * Maximum number of URLs warmed per cron run. Bounds the warmer's share
	 * of a WP-Cron pass to batch x timeout (nominally 50 seconds); the purge
	 * phase that runs first has its own, unbounded duration.
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
	 * Constructor
	 *
	 * @param CachemanLogger $logger The logger instance.
	 */
	public function __construct( private CachemanLogger $logger ) {
	}

	/**
	 * Whether a valid WAF token is configured in wp-config.php.
	 */
	public static function has_valid_waf_token(): bool {
		return null !== self::configured_waf_token();
	}

	/**
	 * Whether cache warming is enabled in the plugin settings.
	 */
	private function is_enabled(): bool {
		$settings = get_option( ZW_CACHEMAN_SETTINGS, [] );

		return ! empty( $settings['enable_warming'] );
	}

	/**
	 * Queue the warmable page URLs from a set of purge items. The queue is
	 * drained in batches by the every-minute cron.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Purged items.
	 */
	public function enqueue( array $items ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$urls = $this->page_urls_from_items( $items );
		if ( empty( $urls ) ) {
			return;
		}

		$queue = array_values( array_unique( array_merge( $this->read_queue(), $urls ) ) );

		if ( count( $queue ) > self::WARM_QUEUE_MAX ) {
			$queue = array_slice( $queue, -self::WARM_QUEUE_MAX );
			// Throttle the overflow log: purge events are unbounded (think
			// bulk imports), and error() always writes to the PHP error log.
			if ( false === get_transient( ZW_CACHEMAN_WARM_QUEUE_OVERFLOW ) ) {
				$this->logger->error( 'Warmer', 'Warm queue exceeded ' . self::WARM_QUEUE_MAX . '; dropped oldest URLs' );
				set_transient( ZW_CACHEMAN_WARM_QUEUE_OVERFLOW, true, 5 * MINUTE_IN_SECONDS );
			}
		}

		update_option( ZW_CACHEMAN_WARM_QUEUE, $queue, false );
	}

	/**
	 * Warm a bounded batch from the queue. Called by the manager after each
	 * purge pass. Keeps each WP-Cron pass short instead of processing a whole
	 * burst at once. URLs are removed only after their fetch completes, and
	 * the queue is re-read before writing, which narrows — but does not
	 * close — the window in which a concurrently enqueued URL is lost; see
	 * the class docblock for the best-effort semantics.
	 */
	public function process_queue(): void {
		if ( ! $this->is_enabled() ) {
			// Warming was turned off; drop any URLs still parked in the queue.
			delete_option( ZW_CACHEMAN_WARM_QUEUE );
			return;
		}

		$batch = array_slice( $this->read_queue(), 0, self::WARM_BATCH_SIZE );
		if ( empty( $batch ) ) {
			return;
		}

		$failed = [];
		foreach ( $batch as $url ) {
			if ( ! $this->warm( $url ) ) {
				$failed[] = $url;
			}
		}

		// Drop the fetched batch, keep URLs enqueued while it was in flight,
		// and rotate transport failures to the tail for a later retry.
		$queue = array_merge( array_diff( $this->read_queue(), $batch ), $failed );
		update_option( ZW_CACHEMAN_WARM_QUEUE, array_values( $queue ), false );
	}

	/**
	 * Warm a single URL by fetching it through the WordPress HTTP API, so
	 * proxy configuration, external-request blocking, and the HTTP API
	 * filters keep applying to warm requests.
	 *
	 * Returns false only on a transport error (WP_Error), so the URL stays
	 * queued for a retry. Any HTTP response — including 4xx/5xx — is treated
	 * as terminal (the URL is dequeued) to avoid retrying a permanently
	 * missing page forever.
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
	 * Read the warm queue, dropping anything that is not a non-empty string.
	 *
	 * @return array<string> Queued URLs.
	 */
	private function read_queue(): array {
		$queue = get_option( ZW_CACHEMAN_WARM_QUEUE, [] );
		if ( ! is_array( $queue ) ) {
			return [];
		}

		return array_values(
			array_filter( $queue, static fn ( $url ): bool => is_string( $url ) && '' !== $url )
		);
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
