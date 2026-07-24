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
	 * Spacing, in seconds, between warm events so a burst does not run
	 * back-to-back in a single WP-Cron pass.
	 */
	private const WARM_STAGGER_SECONDS = 15;

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
		// publishes) de-duplicate within WP-Cron's window. Events are staggered
		// so a large batch does not run back-to-back in a single cron pass and
		// tie up the worker.
		$offset = 0;
		foreach ( $this->page_urls_from_items( $items ) as $url ) {
			if ( wp_next_scheduled( ZW_CACHEMAN_WARM_HOOK, [ $url ] ) ) {
				continue;
			}
			wp_schedule_single_event( time() + $offset, ZW_CACHEMAN_WARM_HOOK, [ $url ] );
			$this->logger->debug( 'Warmer', 'Scheduled warming of ' . $url );
			$offset += self::WARM_STAGGER_SECONDS;
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
