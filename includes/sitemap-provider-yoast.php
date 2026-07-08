<?php
/**
 * Yoast SEO sitemap provider.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO sitemap provider.
 *
 * Yoast paginates per-type sitemaps under `/{slug}-sitemap[N].xml`, ordered by
 * `post_modified ASC` (oldest first, newest posts land on the last page). Any
 * insert/edit/delete cascades items across pages, so all pages need
 * invalidation together — one Prefix purge per group covers them without
 * enumerating individual page URLs. Standalone sitemaps (`sitemap_index.xml`,
 * `news-sitemap.xml`) are File purges.
 */
final readonly class CachemanYoastSitemapProvider implements CachemanSitemapProvider {

	public function __construct(
		private CachemanLogger $logger
	) {
	}

	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	public function get_purge_items_for_post_type( string $post_type ): array {
		$items = [
			$this->file_item( '/sitemap_index.xml' ),
			$this->prefix_item( "/{$post_type}-sitemap" ),
		];

		if ( $this->is_news_active() && $this->is_included_in_news_sitemap( $post_type ) ) {
			$items[] = $this->file_item( '/news-sitemap.xml' );
		}

		// Any post save may add or remove embedded video → invalidate broadly.
		if ( $this->is_video_active() ) {
			$items[] = $this->prefix_item( '/video-sitemap' );
		}

		$this->logger->debug( 'YoastSitemap', 'Post-type ' . $post_type . ' → ' . count( $items ) . ' sitemap purge items' );

		return $items;
	}

	public function get_purge_items_for_taxonomy( string $taxonomy ): array {
		$items = [
			$this->file_item( '/sitemap_index.xml' ),
			$this->prefix_item( "/{$taxonomy}-sitemap" ),
		];

		$this->logger->debug( 'YoastSitemap', 'Taxonomy ' . $taxonomy . ' → ' . count( $items ) . ' sitemap purge items' );

		return $items;
	}

	/**
	 * @param string $path Path with leading slash (e.g. "/sitemap_index.xml").
	 * @return array{url: string, type: PurgeType}
	 */
	private function file_item( string $path ): array {
		return [
			'url'  => home_url( $path ),
			'type' => PurgeType::File,
		];
	}

	/**
	 * Prefix `/foo-sitemap` (without `.xml`) matches `/foo-sitemap.xml`,
	 * `/foo-sitemap2.xml`, ... in a single Cloudflare purge item.
	 *
	 * @param string $path Path with leading slash, without extension.
	 * @return array{url: string, type: PurgeType}
	 */
	private function prefix_item( string $path ): array {
		return [
			'url'  => home_url( $path ),
			'type' => PurgeType::Prefix,
		];
	}

	private function is_news_active(): bool {
		return defined( 'WPSEO_NEWS_FILE' );
	}

	private function is_video_active(): bool {
		return defined( 'WPSEO_VIDEO_FILE' );
	}

	private function is_included_in_news_sitemap( string $post_type ): bool {
		if ( ! class_exists( '\WPSEO_News' ) ) {
			return false;
		}

		$included = \WPSEO_News::get_included_post_types();
		return in_array( $post_type, $included, true );
	}
}
