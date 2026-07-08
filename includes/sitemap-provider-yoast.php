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
			$this->file_item( '/sitemap_index.xml' ),
		];
		$items = array_merge( $items, $this->paginated_sitemap_items( "{$post_type}-sitemap" ) );

		if ( post_type_supports( $post_type, 'author' ) ) {
			$items = array_merge( $items, $this->paginated_sitemap_items( 'author-sitemap' ) );
		}

		if ( $this->is_news_active() && $this->is_included_in_news_sitemap( $post_type ) ) {
			$items[] = $this->file_item( $this->sitemap_file_path( $this->addon_sitemap_slug( $this->get_news_sitemap_basename() ) ) );
		}

		// Any post save may add or remove embedded video.
		if ( $this->is_video_active() ) {
			$items = array_merge( $items, $this->paginated_sitemap_items( $this->addon_sitemap_slug( $this->get_video_sitemap_basename() ) ) );
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
			$this->file_item( '/sitemap_index.xml' ),
		];
		$items = array_merge( $items, $this->paginated_sitemap_items( "{$taxonomy}-sitemap" ) );

		$this->logger->debug( 'YoastSitemap', 'Taxonomy ' . $taxonomy . ' → ' . count( $items ) . ' sitemap purge items' );

		return $items;
	}

	/**
	 * Build a File purge item for a site-relative path.
	 *
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
			$this->file_item( $this->sitemap_file_path( $basename ) ),
			$this->prefix_item( $this->sitemap_prefix_path( $basename ) ),
		];
	}

	/**
	 * Build a Prefix purge item covering paginated sitemap variants.
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

	/**
	 * Build the sitemap file path for a basename.
	 *
	 * @param string $basename Sitemap basename, with or without ".xml".
	 * @return string
	 */
	private function sitemap_file_path( string $basename ): string {
		return $this->sitemap_prefix_path( $basename ) . '.xml';
	}

	/**
	 * Build the sitemap prefix path for a basename.
	 *
	 * @param string $basename Sitemap basename, with or without ".xml".
	 * @return string
	 */
	private function sitemap_prefix_path( string $basename ): string {
		$basename = ltrim( $basename, '/' );
		$basename = preg_replace( '/\.xml$/i', '', $basename ) ?? $basename;

		return '/' . $basename;
	}

	/**
	 * Turn a Yoast add-on basename into its sitemap slug.
	 *
	 * @param string $basename Add-on basename.
	 * @return string
	 */
	private function addon_sitemap_slug( string $basename ): string {
		$basename = ltrim( $basename, '/' );
		$basename = preg_replace( '/\.xml$/i', '', $basename ) ?? $basename;

		if ( ! str_ends_with( $basename, '-sitemap' ) ) {
			$basename .= '-sitemap';
		}

		return $basename;
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
		if ( ! $this->has_static_method( 'WPSEO_News', 'get_included_post_types' ) ) {
			return false;
		}

		$included = call_user_func( [ 'WPSEO_News', 'get_included_post_types' ] );
		if ( ! is_array( $included ) ) {
			return false;
		}

		return in_array( $post_type, $included, true );
	}

	/**
	 * Get Yoast News sitemap basename.
	 *
	 * @return string
	 */
	private function get_news_sitemap_basename(): string {
		return $this->get_addon_sitemap_basename( '\WPSEO_News_Sitemap', 'get_sitemap_name', $this->get_default_news_sitemap_basename(), [ false ] );
	}

	/**
	 * Get Yoast Video sitemap basename.
	 *
	 * @return string
	 */
	private function get_video_sitemap_basename(): string {
		return $this->get_addon_sitemap_basename( '\WPSEO_Video_Sitemap', 'get_video_sitemap_basename', $this->get_default_video_sitemap_basename() );
	}

	/**
	 * Get the News sitemap basename without calling the add-on class.
	 *
	 * @return string
	 */
	private function get_default_news_sitemap_basename(): string {
		$basename = post_type_exists( 'news' ) ? 'yoast-news' : 'news';

		return $this->get_constant_basename( 'YOAST_NEWS_SITEMAP_BASENAME', $basename );
	}

	/**
	 * Get the Video sitemap basename without calling the add-on class.
	 *
	 * @return string
	 */
	private function get_default_video_sitemap_basename(): string {
		$basename = post_type_exists( 'video' ) ? 'yoast-video' : 'video';

		return $this->get_constant_basename( 'YOAST_VIDEO_SITEMAP_BASENAME', $basename );
	}

	/**
	 * Get a string constant value when it is defined.
	 *
	 * @param string $constant Constant name.
	 * @param string $fallback Fallback basename.
	 * @return string
	 */
	private function get_constant_basename( string $constant, string $fallback ): string {
		if ( defined( $constant ) ) {
			$basename = constant( $constant );
			if ( is_string( $basename ) && '' !== $basename ) {
				return $basename;
			}
		}

		return $fallback;
	}

	/**
	 * Resolve an add-on sitemap basename through Yoast when possible.
	 *
	 * @param string           $class_name Yoast add-on sitemap class.
	 * @param string           $method   Static method returning the sitemap basename.
	 * @param string           $fallback Fallback basename.
	 * @param array<int,mixed> $args     Arguments for the Yoast method.
	 * @return string
	 */
	private function get_addon_sitemap_basename( string $class_name, string $method, string $fallback, array $args = [] ): string {
		if ( $this->has_static_method( $class_name, $method ) ) {
			$basename = call_user_func( [ $class_name, $method ], ...$args );
			if ( is_string( $basename ) && '' !== $basename ) {
				return $basename;
			}
		}

		return $fallback;
	}

	/**
	 * Whether an optional external class exposes a static method.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return bool
	 */
	private function has_static_method( string $class_name, string $method ): bool {
		return is_callable( [ $class_name, $method ] );
	}
}
