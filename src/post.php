<?php

/**
 * Handles the transition of a post's status and purges or queues URLs for purging as necessary.
 *
 * @param string   $new_status New status of the post.
 * @param string   $old_status Old status of the post.
 * @param WP_Post  $post       Post object.
 */
function zwcache_handle_post_status_change($new_status, $old_status, $post)
{
    zwcache_debug_log('Post status changed from ' . $old_status . ' to ' . $new_status . ' for post ID ' . $post->ID . '.');
    if ($new_status === 'publish' || $old_status === 'publish') {
        zwcache_purge_urls([get_permalink($post->ID), get_home_url()]);
        zwcache_queue_low_priority_urls(zwcache_get_associated_taxonomy_urls($post->ID));
    }
}

add_action('transition_post_status', 'zwcache_handle_post_status_change', 10, 3);

/**
 * Retrieves URLs for all taxonomies associated with a given post.
 *
 * @param int $post_id ID of the post.
 * @return array URLs associated with the post's taxonomies.
 */
function zwcache_get_associated_taxonomy_urls($post_id)
{
    zwcache_debug_log('Retrieving associated taxonomy URLs for post ID ' . $post_id . '.');
    $urls = [];
    $taxonomies = get_post_taxonomies($post_id);
    foreach ($taxonomies as $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_link = get_term_link($term, $taxonomy);
                if (!is_wp_error($term_link)) {
                    $urls[] = $term_link;
                }
            }
        }
    }
    zwcache_debug_log(sprintf('Found %d associated taxonomy URLs.', count($urls)));
    return $urls;
}

/**
 * Retrieves URLs for all taxonomies associated with a given post.
 *
 * @param int $post_id ID of the post.
 * @return array URLs associated with the post's taxonomies.
 */
function zwcache_get_associated_taxonomy_urls($post_id)
{
    zwcache_debug_log('Retrieving associated taxonomy URLs for post ID ' . $post_id . '.');
    $urls = [];
    $taxonomies = get_post_taxonomies($post_id);
    foreach ($taxonomies as $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_link = get_term_link($term, $taxonomy);
                if (!is_wp_error($term_link)) {
                    $urls[] = $term_link;
                }
            }
        }
    }
    zwcache_debug_log(sprintf('Found %d associated taxonomy URLs.', count($urls)));
    return $urls;
}
