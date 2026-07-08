<?php
/**
 * Sitemap provider interface.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides sitemap purge items for a given content change.
 *
 * Different SEO plugins expose sitemaps under different URL patterns (Yoast,
 * Rank Math, All In One SEO, WP core, ...). Implementations of this interface
 * translate a content-change event (post type / taxonomy) into the concrete
 * list of purge items — a mix of exact-URL File purges and URL-prefix purges,
 * whichever fits each sitemap group best.
 *
 * URLs returned MUST be absolute (scheme + host + path). The url-helper handles
 * the File-vs-Prefix formatting difference (File keeps the scheme; Prefix
 * strips it down to `host/path`).
 */
interface CachemanSitemapProvider {

	/**
	 * Whether the SEO plugin backing this provider is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Sitemap purge items affected by a change to a post of the given type.
	 *
	 * @param string $post_type Post type slug (e.g. "post", "page", "podcast_episode").
	 * @return array<array{url: string, type: PurgeType}>
	 */
	public function get_purge_items_for_post_type( string $post_type ): array;

	/**
	 * Sitemap purge items affected by a change to a term of the given taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug (e.g. "category", "post_tag").
	 * @return array<array{url: string, type: PurgeType}>
	 */
	public function get_purge_items_for_taxonomy( string $taxonomy ): array;
}
