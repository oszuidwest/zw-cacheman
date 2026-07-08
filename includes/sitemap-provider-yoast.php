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
 * Yoast paginates per-type sitemaps under `/{slug}-sitemap[N].xml` and orders
 * post-type entries by `post_modified ASC`. Any insert/edit/delete can cascade
 * items across pages, so all pages need invalidation together.
 */
final readonly class CachemanYoastSitemapProvider implements CachemanSitemapProvider {

	/**
	 * Constructor.
	 *
	 * @param CachemanLogger $logger Logger.
	 */
	public function __construct(
		private CachemanLogger $logger
	) {
	}

	/**
	 * Whether Yoast SEO is loaded.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Sitemap purge items for a post-type change.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<array{url: string, type: PurgeType}>
	 */
	public function get_purge_items_for_post_type( string $post_type ): array {
		$items = [
			$this->file_item( 'sitemap_index.xml' ),
		];
		$items = array_merge( $items, $this->paginated_sitemap_items( "{$post_type}-sitemap" ) );

		if ( post_type_supports( $post_type, 'author' ) ) {
			$items = array_merge( $items, $this->paginated_sitemap_items( 'author-sitemap' ) );
		}

		if ( $this->is_news_active() && $this->is_included_in_news_sitemap( $post_type ) ) {
			$items[] = $this->file_item( $this->news_sitemap_slug() . '.xml' );
		}

		// Any post save may add or remove embedded video.
		if ( $this->is_video_active() ) {
			$items = array_merge( $items, $this->paginated_sitemap_items( $this->video_sitemap_slug() ) );
		}

		$this->logger->debug( 'YoastSitemap', 'Post-type ' . $post_type . ' → ' . count( $items ) . ' sitemap purge items' );

		return $items;
	}

	/**
	 * Sitemap purge items for a taxonomy change.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<array{url: string, type: PurgeType}>
	 */
	public function get_purge_items_for_taxonomy( string $taxonomy ): array {
		$items = [
			$this->file_item( 'sitemap_index.xml' ),
		];
		$items = array_merge( $items, $this->paginated_sitemap_items( "{$taxonomy}-sitemap" ) );

		$this->logger->debug( 'YoastSitemap', 'Taxonomy ' . $taxonomy . ' → ' . count( $items ) . ' sitemap purge items' );

		return $items;
	}

	/**
	 * Build a File purge item for a sitemap file.
	 *
	 * @param string $file Sitemap file name (e.g. "sitemap_index.xml").
	 * @return array{url: string, type: PurgeType}
	 */
	private function file_item( string $file ): array {
		return [
			'url'  => $this->sitemap_url( $file ),
			'type' => PurgeType::File,
		];
	}

	/**
	 * Build File + Prefix purge items for a paginated sitemap group.
	 *
	 * The File item guarantees page 1; the Prefix item covers page 2+ variants
	 * where Cloudflare prefix matching supports the basename pattern.
	 *
	 * @param string $basename Sitemap basename, without extension.
	 * @return array<array{url: string, type: PurgeType}>
	 */
	private function paginated_sitemap_items( string $basename ): array {
		return [
			$this->file_item( $basename . '.xml' ),
			[
				'url'  => $this->sitemap_url( $basename ),
				'type' => PurgeType::Prefix,
			],
		];
	}

	/**
	 * Build the absolute URL for a sitemap path.
	 *
	 * Uses Yoast's own router when available, so sites filtering
	 * `wpseo_sitemaps_base_url` purge the URLs Yoast actually serves.
	 *
	 * @param string $path Sitemap path relative to the site root (e.g. "post-sitemap.xml").
	 * @return string
	 */
	private function sitemap_url( string $path ): string {
		$router = [ 'WPSEO_Sitemaps_Router', 'get_base_url' ];
		if ( is_callable( $router ) ) {
			$url = $router( $path );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/' . $path );
	}

	/**
	 * Whether Yoast News is loaded.
	 *
	 * @return bool
	 */
	private function is_news_active(): bool {
		return defined( 'WPSEO_NEWS_FILE' );
	}

	/**
	 * Whether Yoast Video is loaded.
	 *
	 * @return bool
	 */
	private function is_video_active(): bool {
		return defined( 'WPSEO_VIDEO_FILE' );
	}

	/**
	 * Whether the post type is listed in Yoast News's sitemap config.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private function is_included_in_news_sitemap( string $post_type ): bool {
		$callback = [ 'WPSEO_News', 'get_included_post_types' ];
		if ( ! is_callable( $callback ) ) {
			return false;
		}

		$included = $callback();

		return is_array( $included ) && in_array( $post_type, $included, true );
	}

	/**
	 * News sitemap slug (e.g. "news-sitemap").
	 *
	 * @return string
	 */
	private function news_sitemap_slug(): string {
		return $this->addon_sitemap_slug( $this->addon_sitemap_basename( 'WPSEO_News_Sitemap', 'get_sitemap_name', 'news', [ false ] ) );
	}

	/**
	 * Video sitemap slug (e.g. "video-sitemap").
	 *
	 * @return string
	 */
	private function video_sitemap_slug(): string {
		return $this->addon_sitemap_slug( $this->addon_sitemap_basename( 'WPSEO_Video_Sitemap', 'get_video_sitemap_basename', 'video' ) );
	}

	/**
	 * Turn a Yoast add-on basename into its sitemap slug.
	 *
	 * @param string $basename Add-on basename (e.g. "news" or "yoast-news").
	 * @return string
	 */
	private function addon_sitemap_slug( string $basename ): string {
		return str_ends_with( $basename, '-sitemap' ) ? $basename : $basename . '-sitemap';
	}

	/**
	 * Resolve an add-on sitemap basename through Yoast when possible.
	 *
	 * @param string           $class_name Yoast add-on sitemap class.
	 * @param string           $method     Static method returning the sitemap basename.
	 * @param string           $fallback   Fallback basename.
	 * @param array<int,mixed> $args       Arguments for the Yoast method.
	 * @return string
	 */
	private function addon_sitemap_basename( string $class_name, string $method, string $fallback, array $args = [] ): string {
		$callback = [ $class_name, $method ];
		if ( is_callable( $callback ) ) {
			$basename = $callback( ...$args );
			if ( is_string( $basename ) && '' !== $basename ) {
				return $basename;
			}
		}

		return $fallback;
	}
}
