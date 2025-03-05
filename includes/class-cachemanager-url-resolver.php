<?php
if (! defined('ABSPATH')) {
    exit;
}

namespace ZW_CACHEMAN_Core;

use WP_Post;

/**
 * Class CacheManagerUrlResolver
 *
 * Handles detection of web URLs and resolution of matching REST endpoints.
 */
class CacheManagerUrlResolver
{
    /**
     * Singleton instance.
     *
     * @var CacheManagerUrlResolver
     */
    private static $instance;

    /**
     * Returns the singleton instance.
     *
     * @return CacheManagerUrlResolver
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retrieves primary web URLs for a post.
     *
     * @param WP_Post $post The post object.
     * @return array List of primary web URLs.
     */
    public function get_web_urls($post)
    {
        $urls = [
            trailingslashit(get_permalink($post->ID)),
            trailingslashit(get_home_url()),
            trailingslashit(get_feed_link()),
            trailingslashit(get_post_type_archive_link($post->post_type)),
        ];
        return array_unique(array_filter($urls));
    }

    /**
     * Returns the matching REST endpoint for a given URL.
     *
     * @param string $url     The web URL.
     * @param array  $context Context info: type (post, taxonomy, author, home) and related data.
     * @return string The matching REST endpoint or empty string.
     */
    public function get_matching_rest_endpoint($url, $context)
    {
        $url  = trailingslashit($url);
        $type = isset($context['type']) ? $context['type'] : '';

        switch ($type) {
            case 'post':
                if (empty($context['post']) || ! ( $context['post'] instanceof WP_Post )) {
                    return '';
                }
                $post = $context['post'];
                $post_type = $post->post_type;
                $post_obj = get_post_type_object($post_type);
                $rest_base = ! empty($post_obj->rest_base) ? $post_obj->rest_base : $post_type;
                $permalink = trailingslashit(get_permalink($post->ID));
                $home = trailingslashit(get_home_url());
                $archive = trailingslashit(get_post_type_archive_link($post_type));
                if ($url === $permalink) {
                    return trailingslashit(rest_url('wp/v2/' . $rest_base . '/' . $post->ID));
                } elseif ($url === $home) {
                    return trailingslashit(rest_url());
                } elseif ($url === $archive) {
                    return trailingslashit(rest_url('wp/v2/' . $rest_base));
                }
                break;
            case 'taxonomy':
                if (empty($context['term'])) {
                    return '';
                }
                $term = $context['term'];
                $taxonomy = $term->taxonomy;
                $tax_obj = get_taxonomy($taxonomy);
                if (! empty($tax_obj->show_in_rest)) {
                    $rest_base = ! empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;
                    return trailingslashit(rest_url('wp/v2/' . $rest_base . '/' . $term->term_id));
                }
                break;
            case 'author':
                if (empty($context['user_id'])) {
                    return '';
                }
                return trailingslashit(rest_url('wp/v2/users/' . intval($context['user_id'])));
            case 'home':
                return trailingslashit(rest_url());
        }
        return '';
    }

    /**
     * Retrieves associated taxonomy and author URLs for a given post.
     *
     * @param int $post_id The post ID.
     * @return array List of associated URLs (web URLs and REST endpoints).
     */
    public function get_associated_urls($post_id)
    {
        $urls = [];
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type);
        if (! empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy);
                if ($terms && ! is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $term_link_raw = get_term_link($term, $taxonomy);
                        if (! is_wp_error($term_link_raw)) {
                            $term_link = trailingslashit($term_link_raw);
                            $urls[] = $term_link;
                            $rest_endpoint = $this->get_matching_rest_endpoint($term_link, [ 'type' => 'taxonomy', 'term' => $term ]);
                            if (! empty($rest_endpoint)) {
                                $urls[] = $rest_endpoint;
                            }
                        }
                    }
                }
            }
        }
        if (post_type_supports($post_type, 'author')) {
            $post = get_post($post_id);
            $author_url = trailingslashit(get_author_posts_url((int) $post->post_author));
            if ($author_url) {
                $urls[] = $author_url;
            }
            $rest_endpoint = $this->get_matching_rest_endpoint($author_url, [ 'type' => 'author', 'user_id' => $post->post_author ]);
            if (! empty($rest_endpoint)) {
                $urls[] = $rest_endpoint;
            }
        }
        return array_unique($urls);
    }
}
