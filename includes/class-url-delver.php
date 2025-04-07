<?php
/**
 * URL detection and retrieval functionality
 */

namespace ZW_CACHEMAN_Core;

/**
 * Handles detection and extraction of URLs for cache purging
 */
class CachemanUrlDelver
{
    /**
     * Debug logging
     *
     * @param string $message The message to log.
     * @return void
     */
    public function debug_log($message)
    {
        $settings = get_option(ZW_CACHEMAN_SETTINGS, []);
        if (!empty($settings['debug_mode'])) {
            error_log('[ZW Cacheman URL Delver] ' . $message);
        }
    }

    /**
     * Get high priority purge items for a post
     *
     * @param \WP_Post $post Post object.
     * @return array List of purge items.
     */
    public function get_high_priority_purge_items($post)
    {
        $purge_items = [];

        // Post permalink (individual URL)
        $permalink = get_permalink($post->ID);
        if ($permalink) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($permalink)
            ];
        }

        // Home URL (as file - high priority)
        $home_url = get_home_url();
        if ($home_url) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($home_url)
            ];
        }

        // Post type archive (as file - high priority)
        $archive_link = get_post_type_archive_link($post->post_type);
        if ($archive_link) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($archive_link)
            ];
        }

        // REST API endpoints (individual URLs)
        $post_type = $post->post_type;

        // For all post types, ensure we use the correct REST endpoint
        // First try direct endpoint using post type as the path
        $rest_post_url = rest_url('wp/v2/' . $post_type . '/' . $post->ID);
        $rest_home_url = rest_url();
        $rest_archive_url = rest_url('wp/v2/' . $post_type);

        if ($rest_post_url) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($rest_post_url)
            ];
        }
        if ($rest_home_url) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($rest_home_url)
            ];
        }
        if ($rest_archive_url) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($rest_archive_url)
            ];
        }

        $this->debug_log('Generated ' . count($purge_items) . ' high priority purge items');

        return $purge_items;
    }

    /**
     * Get low priority purge items for a post (taxonomy, author archives, etc.)
     *
     * @param \WP_Post $post Post object.
     * @return array List of purge items.
     */
    public function get_low_priority_purge_items($post)
    {
        $purge_items = [];

        // Post type archive (as prefix - low priority)
        $archive_link = get_post_type_archive_link($post->post_type);
        if ($archive_link) {
            $parsed = parse_url($archive_link);
            if (!empty($parsed['host']) && !empty($parsed['path'])) {
                // Format: "www.example.com/path" (no protocol)
                $purge_items[] = [
                    'type' => 'prefix',
                    'url' => $parsed['host'] . rtrim($parsed['path'], '/')
                ];
            }
        }

        // Feed link (as low priority item)
        $feed_link = get_feed_link();
        if ($feed_link) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($feed_link)
            ];
        }

        // Post-specific feed
        $post_feed_link = get_post_comments_feed_link($post->ID);
        if ($post_feed_link) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($post_feed_link)
            ];
        }

        // Get taxonomy archive URLs
        $post_type = get_post_type($post->ID);
        $taxonomies = get_object_taxonomies($post_type);

        if (!empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $term_link = get_term_link($term, $taxonomy);
                        if (!is_wp_error($term_link) && !empty($term_link)) {
                            // Add term archive as prefix (no protocol)
                            $parsed = parse_url($term_link);
                            if (!empty($parsed['host']) && !empty($parsed['path'])) {
                                $purge_items[] = [
                                    'type' => 'prefix',
                                    'url' => $parsed['host'] . rtrim($parsed['path'], '/')
                                ];
                            }

                            // Add term feed as file
                            $term_feed_link = get_term_feed_link($term->term_id, $taxonomy);
                            if ($term_feed_link) {
                                $purge_items[] = [
                                    'type' => 'file',
                                    'url' => trailingslashit($term_feed_link)
                                ];
                            }

                            // Add REST API endpoint for this term
                            $tax_obj = get_taxonomy($taxonomy);
                            if (!empty($tax_obj) && !empty($tax_obj->show_in_rest)) {
                                $rest_base = !empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;

                                // Individual term endpoint
                                $rest_url = rest_url('wp/v2/' . $rest_base . '/' . $term->term_id);
                                if (!empty($rest_url)) {
                                    $purge_items[] = [
                                        'type' => 'file',
                                        'url' => trailingslashit($rest_url)
                                    ];
                                }

                                // Taxonomy collection endpoint
                                $rest_collection_url = rest_url('wp/v2/' . $rest_base);
                                if (!empty($rest_collection_url)) {
                                    $purge_items[] = [
                                        'type' => 'file',
                                        'url' => trailingslashit($rest_collection_url)
                                    ];
                                }

                                // Add REST API endpoints for filtered post lists by this taxonomy term
                                // First for standard posts
                                $posts_api_base = rest_url('wp/v2/posts');
                                $parsed = parse_url($posts_api_base);
                                if (!empty($parsed['host']) && !empty($parsed['path'])) {
                                    $purge_items[] = [
                                        'type' => 'prefix',
                                        'url' => $parsed['host'] . rtrim($parsed['path'], '/') . '?' . $rest_base . '=' . $term->term_id
                                    ];
                                }

                                // Then for this specific post type if it's not 'post'
                                if ($post_type !== 'post') {
                                    $type_api_base = rest_url('wp/v2/' . $post_type);
                                    $type_parsed = parse_url($type_api_base);
                                    if (!empty($type_parsed['host']) && !empty($type_parsed['path'])) {
                                        $purge_items[] = [
                                            'type' => 'prefix',
                                            'url' => $type_parsed['host'] . rtrim($type_parsed['path'], '/') . '?' . $rest_base . '=' . $term->term_id
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Get author archive URL if post type supports author
        if (post_type_supports($post_type, 'author')) {
            $author_url = get_author_posts_url((int)$post->post_author);
            if (!empty($author_url)) {
                // Add author archive as prefix (no protocol)
                $parsed = parse_url($author_url);
                if (!empty($parsed['host']) && !empty($parsed['path'])) {
                    $purge_items[] = [
                        'type' => 'prefix',
                        'url' => $parsed['host'] . rtrim($parsed['path'], '/')
                    ];
                }

                // Add author feed
                $author_feed = get_author_feed_link((int)$post->post_author);
                if ($author_feed) {
                    $purge_items[] = [
                        'type' => 'file',
                        'url' => trailingslashit($author_feed)
                    ];
                }

                // Add REST API endpoint as individual URL
                $author_rest_url = rest_url('wp/v2/users/' . $post->post_author);
                if (!empty($author_rest_url)) {
                    $purge_items[] = [
                        'type' => 'file',
                        'url' => trailingslashit($author_rest_url)
                    ];
                }
            }
        }

        // Global REST API taxonomies endpoint
        $rest_taxonomies_url = rest_url('wp/v2/taxonomies');
        if (!empty($rest_taxonomies_url)) {
            $purge_items[] = [
                'type' => 'file',
                'url' => trailingslashit($rest_taxonomies_url)
            ];
        }

        $this->debug_log('Generated ' . count($purge_items) . ' low priority purge items');

        return $purge_items;
    }
}
