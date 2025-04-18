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
     * URL Helper instance
     *
     * @var CachemanUrlHelper
     */
    private $url_helper;

    /**
     * Logger instance
     *
     * @var CachemanLogger
     */
    private $logger;

    /**
     * Constructor
     *
     * @param CachemanUrlHelper $url_helper The URL helper instance.
     * @param CachemanLogger    $logger     The logger instance.
     */
    public function __construct($url_helper, $logger)
    {
        $this->url_helper = $url_helper;
        $this->logger = $logger;
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
            $item = $this->url_helper->create_purge_item($permalink, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        // Home URL (as file - high priority)
        $home_url = get_home_url();
        if ($home_url) {
            $item = $this->url_helper->create_purge_item($home_url, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        // Post type archive (as file - high priority)
        $archive_link = get_post_type_archive_link($post->post_type);
        if ($archive_link) {
            $item = $this->url_helper->create_purge_item($archive_link, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        // Get the proper REST API endpoints using the post type object (to handle custom rest_base)
        $post_type_obj = get_post_type_object($post->post_type);
        $rest_base = '';

        if ($post_type_obj && $post_type_obj->show_in_rest) {
            // Get the correct rest_base for this post type
            $rest_base = !empty($post_type_obj->rest_base) ? $post_type_obj->rest_base : $post->post_type;

            // Individual post endpoint using correct rest_base
            $rest_post_url = rest_url('wp/v2/' . $rest_base . '/' . $post->ID);
            if ($rest_post_url) {
                $item = $this->url_helper->create_purge_item($rest_post_url, 'file');
                if ($item) {
                    $purge_items[] = $item;
                }
            }

            // Post type collection endpoint using correct rest_base
            $rest_archive_url = rest_url('wp/v2/' . $rest_base);
            if ($rest_archive_url) {
                $item = $this->url_helper->create_purge_item($rest_archive_url, 'file');
                if ($item) {
                    $purge_items[] = $item;
                }
            }
        }

        // REST API root endpoint
        $rest_home_url = rest_url();
        if ($rest_home_url) {
            $item = $this->url_helper->create_purge_item($rest_home_url, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' high priority purge items for post ID ' . $post->ID);
        if ($post_type_obj && $post_type_obj->rest_base !== $post->post_type) {
            $this->logger->debug('URL Delver', 'Using custom rest_base "' . $rest_base . '" for post type "' . $post->post_type . '"');
        }

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

        // Get post type object to check if it has a proper archive
        $post_type_obj = get_post_type_object($post->post_type);

        // Check if this post type has a real archive
        if ($post_type_obj && $post_type_obj->has_archive) {
            // Post type archive (as prefix - low priority)
            $archive_link = get_post_type_archive_link($post->post_type);
            if ($archive_link) {
                $this->logger->debug('URL Delver', 'Adding dedicated archive link as prefix: ' . $archive_link);
                $item = $this->url_helper->create_purge_item($archive_link, 'prefix');
                if ($item) {
                    $purge_items[] = $item;
                }
            }
        } else {
            $this->logger->debug('URL Delver', 'Post type "' . $post->post_type . '" has no dedicated archive, skipping archive purge');
        }

        // Feed link (as low priority item)
        $feed_link = get_feed_link();
        if ($feed_link) {
            $item = $this->url_helper->create_purge_item($feed_link, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        // Post-specific feed
        $post_feed_link = get_post_comments_feed_link($post->ID);
        if ($post_feed_link) {
            $item = $this->url_helper->create_purge_item($post_feed_link, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        // Get taxonomy archive URLs
        $post_type = get_post_type($post->ID);
        $taxonomies = get_object_taxonomies($post_type);

        if (!empty($taxonomies)) {
            $this->logger->debug('URL Delver', 'Processing ' . count($taxonomies) . ' taxonomies for post ID ' . $post->ID);

            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    $this->logger->debug('URL Delver', 'Found ' . count($terms) . ' terms for taxonomy ' . $taxonomy);

                    foreach ($terms as $term) {
                        $term_link = get_term_link($term, $taxonomy);
                        if (!is_wp_error($term_link) && !empty($term_link)) {
                            // Add term archive as prefix
                            $item = $this->url_helper->create_purge_item($term_link, 'prefix');
                            if ($item) {
                                $purge_items[] = $item;
                            }

                            // Add term feed as file
                            $term_feed_link = get_term_feed_link($term->term_id, $taxonomy);
                            if ($term_feed_link) {
                                $item = $this->url_helper->create_purge_item($term_feed_link, 'file');
                                if ($item) {
                                    $purge_items[] = $item;
                                }
                            }

                            // Add REST API endpoint for this term
                            $tax_obj = get_taxonomy($taxonomy);
                            if (!empty($tax_obj) && $tax_obj->show_in_rest) {
                                $rest_base = !empty($tax_obj->rest_base) ? $tax_obj->rest_base : $taxonomy;

                                // Individual term endpoint
                                $rest_url = rest_url('wp/v2/' . $rest_base . '/' . $term->term_id);
                                if (!empty($rest_url)) {
                                    $item = $this->url_helper->create_purge_item($rest_url, 'file');
                                    if ($item) {
                                        $purge_items[] = $item;
                                    }
                                }

                                // Taxonomy collection endpoint
                                $rest_collection_url = rest_url('wp/v2/' . $rest_base);
                                if (!empty($rest_collection_url)) {
                                    $item = $this->url_helper->create_purge_item($rest_collection_url, 'file');
                                    if ($item) {
                                        $purge_items[] = $item;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if (is_wp_error($terms)) {
                        $this->logger->debug('URL Delver', 'Error getting terms for taxonomy ' . $taxonomy . ': ' . $terms->get_error_message());
                    } else {
                        $this->logger->debug('URL Delver', 'No terms found for taxonomy ' . $taxonomy);
                    }
                }
            }
        }

        // Get author archive URL if post type supports author
        if (post_type_supports($post_type, 'author')) {
            $author_url = get_author_posts_url((int)$post->post_author);
            if (!empty($author_url)) {
                // Add author archive as prefix
                $item = $this->url_helper->create_purge_item($author_url, 'prefix');
                if ($item) {
                    $purge_items[] = $item;
                }

                // Add author feed
                $author_feed = get_author_feed_link((int)$post->post_author);
                if ($author_feed) {
                    $item = $this->url_helper->create_purge_item($author_feed, 'file');
                    if ($item) {
                        $purge_items[] = $item;
                    }
                }

                // Add REST API endpoint as individual URL
                $author_rest_url = rest_url('wp/v2/users/' . $post->post_author);
                if (!empty($author_rest_url)) {
                    $item = $this->url_helper->create_purge_item($author_rest_url, 'file');
                    if ($item) {
                        $purge_items[] = $item;
                    }
                }
            }
        }

        // Global REST API taxonomies endpoint
        $rest_taxonomies_url = rest_url('wp/v2/taxonomies');
        if (!empty($rest_taxonomies_url)) {
            $item = $this->url_helper->create_purge_item($rest_taxonomies_url, 'file');
            if ($item) {
                $purge_items[] = $item;
            }
        }

        $this->logger->debug('URL Delver', 'Generated ' . count($purge_items) . ' low priority purge items for post ID ' . $post->ID);

        return $purge_items;
    }
}
