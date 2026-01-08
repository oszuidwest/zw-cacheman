<?php
/**
 * URL validation and cleaning functionality.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

/**
 * Centralized URL validation and formatting.
 */
readonly class CachemanUrlHelper
{
    /**
     * Constructor
     *
     * @param CachemanLogger $logger The logger instance.
     */
    public function __construct(
        private CachemanLogger $logger
    ) {
    }

    /**
     * Clean URL by removing query string and ensuring it's valid
     *
     * @param string $url URL to clean.
     * @param bool   $add_trailing_slash Whether to add trailing slash.
     * @return string|false Cleaned URL or false if invalid.
     */
    public function clean_url(string $url, bool $add_trailing_slash = true): string|false
    {
        // Check if URL is valid.
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->debug('URL Helper', 'Invalid URL: ' . print_r($url, true));
            return false;
        }

        // Parse the URL and rebuild it without query string or fragment.
        $parsed = parse_url($url);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            $this->logger->debug('URL Helper', 'Missing scheme or host in URL: ' . $url);
            return false;
        }

        $clean_url = $parsed['scheme'] . '://' . $parsed['host'];

        // Add port if specified.
        if (!empty($parsed['port'])) {
            $clean_url .= ':' . $parsed['port'];
        }

        // Add path if specified.
        if (!empty($parsed['path'])) {
            $clean_url .= $parsed['path'];
        } else {
            $clean_url .= '/';
        }

        // Add trailing slash if requested.
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
    public function format_url_prefix(string $url): string|false
    {
        // If the URL contains a query string character, invalid for prefix.
        if (strpos($url, '?') !== false) {
            $this->logger->debug('URL Helper', 'URL contains query string, invalid for prefix: ' . $url);
            return false;
        }

        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            $this->logger->debug('URL Helper', 'Missing host in URL: ' . $url);
            return false;
        }

        // Format: "www.example.com/path" (no protocol).
        // If path is empty, use root path.
        $path = !empty($parsed['path']) ? $parsed['path'] : '/';

        // Return without trailing slash for prefixes.
        return $parsed['host'] . rtrim($path, '/');
    }

    /**
     * Create a purge item structure for a URL
     *
     * @param string    $url The URL to create a purge item for.
     * @param PurgeType $type The type of purge item ('file' or 'prefix').
     * @return array{type: PurgeType, url: string}|false Purge item structure or false if invalid.
     */
    public function create_purge_item(string $url, PurgeType $type = PurgeType::File): array|false
    {
        if (empty($url)) {
            $this->logger->debug('URL Helper', 'Empty URL provided to create_purge_item');
            return false;
        }

        if ($type === PurgeType::File) {
            // For file URLs, add trailing slash for consistency.
            $cleaned_url = $this->clean_url($url, true);
            if (!$cleaned_url) {
                return false;
            }

            return array(
                'type' => PurgeType::File,
                'url' => $cleaned_url
            );
        } elseif ($type === PurgeType::Prefix) {
            // For prefix URLs, don't add trailing slash.
            $prefix = $this->format_url_prefix($url);
            if (!$prefix) {
                return false;
            }

            return array(
                'type' => PurgeType::Prefix,
                'url' => $prefix
            );
        }
    }

    /**
     * Clean and filter multiple URLs
     *
     * @param array<string> $urls Array of URLs to clean.
     * @return array<string> Array of cleaned, valid URLs.
     */
    public function clean_urls(array $urls): array
    {
        $clean_urls = array();
        $skipped_urls = array();

        foreach ($urls as $url) {
            $cleaned = $this->clean_url($url, true);
            if ($cleaned) {
                // Check if this is different from the original.
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

        // Remove duplicates.
        return array_values(array_unique($clean_urls));
    }

    /**
     * Clean and filter multiple URL prefixes
     *
     * @param array<string> $prefixes Array of URL prefixes to clean.
     * @return array<string> Array of cleaned, valid prefixes.
     */
    public function clean_prefixes(array $prefixes): array
    {
        $clean_prefixes = array();
        $skipped_prefixes = array();

        foreach ($prefixes as $prefix) {
            if (empty($prefix)) {
                continue;
            }

            // If the prefix contains a query string character, skip it.
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

        // Remove duplicates.
        return array_values(array_unique($clean_prefixes));
    }
}
