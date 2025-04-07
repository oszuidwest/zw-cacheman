<?php
/**
 * URL validation and cleaning functionality
 */

namespace ZW_CACHEMAN_Core;

/**
 * Centralized URL validation and formatting
 */
class CachemanUrlHelper
{
    /**
     * Logger instance
     *
     * @var CachemanLogger
     */
    private $logger;

    /**
     * Constructor
     *
     * @param CachemanLogger $logger The logger instance.
     */
    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Clean URL by removing query string and ensuring it's valid
     *
     * @param string $url URL to clean.
     * @param bool $add_trailing_slash Whether to add trailing slash.
     * @return string|false Cleaned URL or false if invalid.
     */
    public function clean_url($url, $add_trailing_slash = true)
    {
        // Check if URL is valid
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->debug('URL Helper', 'Invalid URL: ' . print_r($url, true));
            return false;
        }

        // Parse the URL and rebuild it without query string or fragment
        $parsed = parse_url($url);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            $this->logger->debug('URL Helper', 'Missing scheme or host in URL: ' . $url);
            return false;
        }

        $clean_url = $parsed['scheme'] . '://' . $parsed['host'];

        // Add port if specified
        if (!empty($parsed['port'])) {
            $clean_url .= ':' . $parsed['port'];
        }

        // Add path if specified
        if (!empty($parsed['path'])) {
            $clean_url .= $parsed['path'];
        } else {
            $clean_url .= '/';
        }

        // Add trailing slash if requested
        if ($add_trailing_slash) {
            $clean_url = trailingslashit($clean_url);
        }

        return $clean_url;
    }

    /**
     * Format URL as prefix for Cloudflare API (no protocol)
     *
     * @param string $url Full URL to format as prefix.
     * @return string|false Formatted prefix or false if invalid.
     */
    public function format_url_prefix($url)
    {
        // If the URL contains a query string character, invalid for prefix
        if (strpos($url, '?') !== false) {
            $this->logger->debug('URL Helper', 'URL contains query string, invalid for prefix: ' . $url);
            return false;
        }

        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            $this->logger->debug('URL Helper', 'Missing host in URL: ' . $url);
            return false;
        }

        // Format: "www.example.com/path" (no protocol)
        // If path is empty, use root path
        $path = !empty($parsed['path']) ? $parsed['path'] : '/';

        // Return without trailing slash for prefixes
        return $parsed['host'] . rtrim($path, '/');
    }

    /**
     * Create a purge item structure for a URL
     *
     * @param string $url The URL to create a purge item for.
     * @param string $type The type of purge item ('file' or 'prefix').
     * @return array|false Purge item structure or false if invalid.
     */
    public function create_purge_item($url, $type = 'file')
    {
        if (empty($url)) {
            $this->logger->debug('URL Helper', 'Empty URL provided to create_purge_item');
            return false;
        }

        if ($type === 'file') {
            // For file URLs, add trailing slash for consistency
            $cleaned_url = $this->clean_url($url, true);
            if (!$cleaned_url) {
                return false;
            }

            return array(
                'type' => 'file',
                'url' => $cleaned_url
            );
        } elseif ($type === 'prefix') {
            // For prefix URLs, don't add trailing slash
            $prefix = $this->format_url_prefix($url);
            if (!$prefix) {
                return false;
            }

            return array(
                'type' => 'prefix',
                'url' => $prefix
            );
        }

        $this->logger->debug('URL Helper', 'Invalid purge item type: ' . $type);
        return false;
    }

    /**
     * Clean and filter multiple URLs
     *
     * @param array $urls Array of URLs to clean.
     * @return array Array of cleaned, valid URLs.
     */
    public function clean_urls($urls)
    {
        $clean_urls = array();
        $skipped_urls = array();

        foreach ($urls as $url) {
            $cleaned = $this->clean_url($url, true);
            if ($cleaned) {
                // Check if this is different from the original
                if ($cleaned !== $url) {
                    $this->logger->debug('URL Helper', 'Cleaned URL: ' . $url . ' → ' . $cleaned);
                }
                $clean_urls[] = $cleaned;
            } else {
                $skipped_urls[] = $url;
            }
        }

        if (!empty($skipped_urls)) {
            $this->logger->debug('URL Helper', 'Skipped ' . count($skipped_urls) . ' invalid URLs');
        }

        // Remove duplicates
        return array_values(array_unique($clean_urls));
    }

    /**
     * Clean and filter multiple URL prefixes
     *
     * @param array $prefixes Array of URL prefixes to clean.
     * @return array Array of cleaned, valid prefixes.
     */
    public function clean_prefixes($prefixes)
    {
        $clean_prefixes = array();
        $skipped_prefixes = array();

        foreach ($prefixes as $prefix) {
            if (empty($prefix)) {
                continue;
            }

            // If the prefix contains a query string character, skip it
            if (strpos($prefix, '?') !== false) {
                $skipped_prefixes[] = $prefix;
                $this->logger->debug('URL Helper', 'Skipping prefix with query string: ' . $prefix);
                continue;
            }

            $clean_prefixes[] = $prefix;
        }

        if (!empty($skipped_prefixes)) {
            $this->logger->debug('URL Helper', 'Skipped ' . count($skipped_prefixes) . ' prefixes with query strings');
        }

        // Remove duplicates
        return array_values(array_unique($clean_prefixes));
    }
}
