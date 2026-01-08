<?php
/**
 * URL detection and retrieval functionality.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

/**
 * Handles detection and extraction of URLs for cache purging.
 */
readonly class CachemanUrlDelver
{
    /**
     * Constructor
     *
     * @param CachemanUrlHelper $url_helper The URL helper instance.
     * @param CachemanLogger    $logger     The logger instance.
     */
    public function __construct(
        private CachemanUrlHelper $url_helper,
        private CachemanLogger $logger
    ) {
    }

    /**
     * Get high priority purge items for a post
     *
     * @param \WP_Post $post Post object.
     * @return array<array{type: PurgeType, url: string}> List of purge items.
     */
    public function get_high_priority_purge_items(\WP_Post $post): array
    {
        $urls = [];
        // Log if we're working with a trashed post.
        if ($post->post_status === 'trash') {
            $this->logger->debug('URL Delver', 'Working with tashed post ' . $post->ID . ', will try to resurrect original permalink');

            // For trashed posts, skip current permalink and go straight to recovery.
            $clean_slug = preg_replace('/__trashed$/', '', $post->post_name);

            // Only proceed if we actually have a trashed suffix.
            if ($clean_slug !== $post->post_name) {
                // Simulate non-trashed post for permalink generation.
                $resurrected_post = clone $post;
                $resurrected_post->post_name = $clean_slug;
                $resurrected_post->post_status = 'publish';

                // Get clean permalink.
                $clean_permalink = get_permalink($resurrected_post);
                if ($clean_permalink) {
                    $urls[] = ['url' => $clean_permalink, 'type' => PurgeType::File];
                }
            }
        } else {
            // Only add the current permalink for non-trashed posts.
            $permalink = get_permalink($post->ID);
            if ($permalink) {
                $urls[] = ['url' => $permalink, 'type' => PurgeType::File];
            }
        }

        // Home URL.
        $urls[] = ['url' => get_home_url(), 'type' => PurgeType::File];

        // Post type archive.
        $archive_link = get_post_type_archive_link($post->post_type);
        if ($archive_link) {
            $urls[] = ['url' => $archive_link, 'type' => PurgeType::File];
        }

        // REST API URLs.
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj && $post_type_obj->show_in_rest) {
            $rest_base = !empty($post_type_obj->rest_base) ? $post_type_obj->rest_base : $post->post_type;

            // Individual post endpoint.
            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base . '/' . $post->ID), 'type' => PurgeType::File];

            // Post type collection endpoint.
            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base), 'type' => PurgeType::File];
        }

        // REST API root endpoint.
        $urls[] = ['url' => rest_url(), 'type' => PurgeType::File];

        // Create purge items.
        $purge_items = $this->create_purge_items($urls);

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' high priority purge items for post ID ' . $post->ID);

        return $purge_items;
    }

    /**
     * Get low priority purge items for a post (taxonomy, author archives, etc.)
     *
     * @param \WP_Post $post Post object.
     * @return array<array{type: PurgeType, url: string}> List of purge items.
     */
    public function get_low_priority_purge_items(\WP_Post $post): array
    {
        $urls = [];

        // Post type archive as prefix.
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj && $post_type_obj->has_archive) {
            $archive_link = get_post_type_archive_link($post->post_type);
            if ($archive_link) {
                $urls[] = ['url' => $archive_link, 'type' => PurgeType::Prefix];
            }
        }

        // Feed URLs.
        $urls[] = ['url' => get_feed_link(), 'type' => PurgeType::File];

        $post_feed_link = get_post_comments_feed_link($post->ID);
        if ($post_feed_link) {
            $urls[] = ['url' => $post_feed_link, 'type' => PurgeType::File];
        }

        // Taxonomy URLs.
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $term_link = get_term_link($term, $taxonomy);
                    if (!is_wp_error($term_link)) {
                        $urls[] = ['url' => $term_link, 'type' => PurgeType::Prefix];

                        // Term feed.
                        $term_feed_link = get_term_feed_link($term->term_id, $taxonomy);
                        if ($term_feed_link) {
                            $urls[] = ['url' => $term_feed_link, 'type' => PurgeType::File];
                        }

                        // REST API endpoints for term.
                        $tax_obj = get_taxonomy($taxonomy);
                        if ($tax_obj && $tax_obj->show_in_rest) {
                            $rest_base = !empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;

                            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base . '/' . $term->term_id), 'type' => PurgeType::File];
                            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base), 'type' => PurgeType::File];
                        }
                    }
                }
            }
        }

        // Author URLs.
        if (post_type_supports($post->post_type, 'author')) {
            $author_url = get_author_posts_url((int)$post->post_author);
            if ($author_url) {
                $urls[] = ['url' => $author_url, 'type' => PurgeType::Prefix];

                // Author feed.
                $author_feed = get_author_feed_link((int)$post->post_author);
                if ($author_feed) {
                    $urls[] = ['url' => $author_feed, 'type' => PurgeType::File];
                }

                // Author REST API endpoint.
                $urls[] = ['url' => rest_url('wp/v2/users/' . $post->post_author), 'type' => PurgeType::File];
            }
        }

        // Global REST API taxonomies endpoint.
        $urls[] = ['url' => rest_url('wp/v2/taxonomies'), 'type' => PurgeType::File];

        // Create purge items.
        $purge_items = $this->create_purge_items($urls);

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' low priority purge items for post ID ' . $post->ID);

        return $purge_items;
    }

    /**
     * Get purge items for a deleted post
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @return array<array{type: PurgeType, url: string}> List of purge items.
     */
    public function get_deleted_post_purge_items(int $post_id, \WP_Post $post): array
    {
        // For deleted posts, we'll combine both high and low priority URLs.
        // since we want to purge everything related to this post immediately.
        $high_priority_items = $this->get_high_priority_purge_items($post);
        $low_priority_items = $this->get_low_priority_purge_items($post);

        // Combine both sets of purge items.
        $all_items = array_merge($high_priority_items, $low_priority_items);

        $this->logger->debug('URL Delver', 'Generated ' . count($all_items) . ' purge items for deleted post ID ' . $post_id);

        return $all_items;
    }

    /**
     * Get high priority purge items for a term
     *
     * @param \WP_Term $term     Term object.
     * @param string   $taxonomy Taxonomy slug.
     * @return array<array{type: PurgeType, url: string}> List of purge items.
     */
    public function get_high_priority_term_purge_items(\WP_Term $term, string $taxonomy): array
    {
        $urls = [];

        // Term archive URL.
        $term_link = get_term_link($term);
        if (!is_wp_error($term_link)) {
            $urls[] = ['url' => $term_link, 'type' => PurgeType::File];
        }

        // Home URL.
        $urls[] = ['url' => get_home_url(), 'type' => PurgeType::File];

        // REST API endpoints for this term.
        $tax_obj = get_taxonomy($taxonomy);
        if ($tax_obj && $tax_obj->show_in_rest) {
            $rest_base = !empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;

            // Individual term endpoint.
            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base . '/' . $term->term_id), 'type' => PurgeType::File];

            // Taxonomy collection endpoint.
            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base), 'type' => PurgeType::File];

            // Taxonomies endpoint.
            $urls[] = ['url' => rest_url('wp/v2/taxonomies'), 'type' => PurgeType::File];
        }

        // Create purge items.
        $purge_items = $this->create_purge_items($urls);

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' high priority purge items for term ID ' . $term->term_id);

        return $purge_items;
    }

    /**
     * Get low priority purge items for a term
     *
     * @param \WP_Term $term     Term object.
     * @param string   $taxonomy Taxonomy slug.
     * @return array<array{type: PurgeType, url: string}> List of purge items.
     */
    public function get_low_priority_term_purge_items(\WP_Term $term, string $taxonomy): array
    {
        $urls = [];

        // Term archive URL. as prefix.
        $term_link = get_term_link($term);
        if (!is_wp_error($term_link)) {
            $urls[] = ['url' => $term_link, 'type' => PurgeType::Prefix];
        }

        // Term feed URL.
        $term_feed_link = get_term_feed_link($term->term_id, $taxonomy);
        if ($term_feed_link) {
            $urls[] = ['url' => $term_feed_link, 'type' => PurgeType::File];
        }

        // Parent term if any.
        if ($term->parent) {
            $parent_term = get_term($term->parent, $taxonomy);
            if (!is_wp_error($parent_term)) {
                $parent_link = get_term_link($parent_term);
                if (!is_wp_error($parent_link)) {
                    $urls[] = ['url' => $parent_link, 'type' => PurgeType::Prefix];

                    // Parent term feed.
                    $parent_feed_link = get_term_feed_link($parent_term->term_id, $taxonomy);
                    if ($parent_feed_link) {
                        $urls[] = ['url' => $parent_feed_link, 'type' => PurgeType::File];
                    }
                }
            }
        }

        // Main feed. link.
        $urls[] = ['url' => get_feed_link(), 'type' => PurgeType::File];

        // Create purge items.
        $purge_items = $this->create_purge_items($urls);

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' low priority purge items for term ID ' . $term->term_id);

        return $purge_items;
    }

    /**
     * Get purge items for a deleted term
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @return array<array{type: PurgeType, url: string}> List of purge items.
     */
    public function get_deleted_term_purge_items(int $term_id, string $taxonomy): array
    {
        $urls = [];

        // Home URL.
        $urls[] = ['url' => get_home_url(), 'type' => PurgeType::File];

        // Taxonomy archive if available.
        $tax_obj = get_taxonomy($taxonomy);
        if ($tax_obj && !empty($tax_obj->rewrite) && isset($tax_obj->rewrite['slug'])) {
            $tax_base = !empty($tax_obj->rewrite['slug']) ? $tax_obj->rewrite['slug'] : $taxonomy;
            $tax_archive = trailingslashit(get_home_url()) . $tax_base . '/';

            $urls[] = ['url' => $tax_archive, 'type' => PurgeType::Prefix];
        }

        // REST API endpoints if applicable.
        if ($tax_obj && $tax_obj->show_in_rest) {
            $rest_base = !empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;

            // Term endpoint (even though deleted).
            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base . '/' . $term_id), 'type' => PurgeType::File];

            // Taxonomy collection endpoint.
            $urls[] = ['url' => rest_url('wp/v2/' . $rest_base), 'type' => PurgeType::File];

            // Taxonomies endpoint.
            $urls[] = ['url' => rest_url('wp/v2/taxonomies'), 'type' => PurgeType::File];
        }

        // Main feed.
        $urls[] = ['url' => get_feed_link(), 'type' => PurgeType::File];

        // Create purge items.
        $purge_items = $this->create_purge_items($urls);

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' purge items for deleted term ID ' . $term_id);

        return $purge_items;
    }

    /**
     * Create purge items from URL data
     *
     * @param array<array{url: string, type: PurgeType}> $urls Array of URL data with 'url' and 'type' keys.
     * @return array<array{type: PurgeType, url: string}> List of validated purge items.
     */
    private function create_purge_items(array $urls): array
    {
        $purge_items = [];

        foreach ($urls as $url_data) {
            if (empty($url_data['url'])) {
                continue;
            }

            $item = $this->url_helper->create_purge_item($url_data['url'], $url_data['type']);
            if ($item) {
                $purge_items[] = $item;
            }
        }

        // Remove duplicates based on URL and type.
        $unique_items = [];
        $seen_keys = [];

        foreach ($purge_items as $item) {
            $key = $item['type']->value . '|' . $item['url'];
            if (!isset($seen_keys[$key])) {
                $seen_keys[$key] = true;
                $unique_items[] = $item;
            }
        }

        return $unique_items;
    }
}
