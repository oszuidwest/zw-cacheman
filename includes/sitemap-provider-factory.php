<?php
/**
 * Sitemap provider factory.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the first active sitemap provider by walking a priority-ordered
 * list of candidates. Returns null when no supported SEO plugin is active.
 */
final class CachemanSitemapProviderFactory {

	/**
	 * Candidate provider classes, in priority order. Add new implementations
	 * here to enable auto-detection.
	 */
	private const CANDIDATES = [
		CachemanYoastSitemapProvider::class,
	];

	public static function detect( CachemanLogger $logger ): ?CachemanSitemapProvider {
		foreach ( self::CANDIDATES as $class ) {
			$provider = new $class( $logger );
			if ( $provider->is_active() ) {
				return $provider;
			}
		}
		return null;
	}
}
