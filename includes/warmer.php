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
	private const WARM_TIMEOUT_SECONDS = 15;

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
		add_action( ZW_CACHEMAN_WARM_HOOK, $this->warm( ... ), 10, 1 );
	}

	/**
	 * Schedule warming for the page URLs among a set of purge items.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Purged items.
	 */
	public function schedule( array $items ): void {
		if ( ! $this->enabled ) {
			return;
		}

		// One event per URL, so repeats (e.g. the homepage after a burst of
		// publishes) de-duplicate within WP-Cron's 10-minute window.
		foreach ( $this->page_urls_from_items( $items ) as $url ) {
			if ( ! wp_next_scheduled( ZW_CACHEMAN_WARM_HOOK, [ $url ] ) ) {
				wp_schedule_single_event( time(), ZW_CACHEMAN_WARM_HOOK, [ $url ] );
				$this->logger->debug( 'Warmer', 'Scheduled warming of ' . $url );
			}
		}
	}

	/**
	 * Warm a single URL by fetching it (cron callback).
	 *
	 * @param string $url URL to warm.
	 */
	public function warm( string $url ): void {
		if ( ! $this->enabled || '' === $url ) {
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
		// Match the REST base by path, so REST URLs on extra domains (different
		// host, same "/wp-json/" path) are excluded too.
		$rest_path = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$urls      = [];

		foreach ( $items as $item ) {
			if ( PurgeType::File !== $item['type'] ) {
				continue;
			}

			$url  = $item['url'];
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			if ( str_starts_with( $path, $rest_path ) ) {
				continue;
			}

			$urls[ $url ] = $url;
		}

		return array_values( $urls );
	}
}
