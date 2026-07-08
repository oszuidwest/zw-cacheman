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
 * Resolves the sitemap provider for the active SEO plugin, runs the public
 * override filter, and validates the result. Returns null when sitemap
 * purging is unavailable.
 */
final class CachemanSitemapProviderFactory {

	/**
	 * Detect the sitemap provider and apply the public override filter.
	 *
	 * @param CachemanLogger $logger Logger.
	 * @return CachemanSitemapProvider|null
	 */
	public static function detect( CachemanLogger $logger ): ?CachemanSitemapProvider {
		$provider = new CachemanYoastSitemapProvider( $logger );
		if ( ! $provider->is_active() ) {
			$provider = null;
		}

		/**
		 * Filter the sitemap provider used for sitemap purge items.
		 *
		 * Return null to disable sitemap purging.
		 *
		 * @since 1.8.0
		 *
		 * @param CachemanSitemapProvider|null $provider Detected sitemap provider, or null.
		 * @param CachemanLogger               $logger   Logger instance.
		 */
		$provider = apply_filters( 'zw_cacheman_sitemap_provider', $provider, $logger );
		/**
		 * Filtered provider value from a public hook.
		 *
		 * @var mixed $provider
		 */
		if ( null !== $provider && ! $provider instanceof CachemanSitemapProvider ) {
			$logger->error(
				'Bootstrap',
				'Invalid zw_cacheman_sitemap_provider result: expected CachemanSitemapProvider or null, got ' . get_debug_type( $provider )
			);
			$provider = null;
		}

		return $provider;
	}
}
