# ZuidWest Cache Manager

The ZuidWest Cache Manager is a WordPress plugin optimized for managing high-traffic sites on non-enterprise Cloudflare accounts. It purges cache for articles and homepage URLs instantly when posts are published or edited, and schedules related taxonomy URLs for batch processing with low priority through WP-Cron. This setup lets you activate Cloudflare's 'cache everything' feature, significantly reducing traffic to your origin server.

## Features

- **Immediate Cache Purging**: Automatically purges cache for high-priority URLs (e.g., the post URL and homepage) immediately upon post publishing or editing.
- **Low-Priority Batch Processing**: Queues associated taxonomy URLs and processes them in batches for efficient cache management, reducing server load.
- **Customizable Settings**: Offers a settings page in the WordPress admin area for configuring API keys, batch sizes, and debug mode.
- **Debug Mode**: Includes an optional debug mode for logging operations and troubleshooting.

## Configuration

Navigate to the plugin settings page located under Settings > ZuidWest Cache in the WordPress admin dashboard. Enter the following details:

- **Zone ID**: Your Cloudflare Zone ID.
- **API Key**: Your Cloudflare API Key.
- **Batch Size**: The number of URLs to process per batch during low-priority batch processing.
- **Debug Mode**: Enable or disable debug mode for logging.

After entering correct credentials the plug-in listens for post publishing and editing events to purge or queue URLs accordingly.

## Debugging

Enable debug mode in the plugin settings to log detailed information about cache purging operations, which can be helpful for troubleshooting.

## Contributing

Contributions are welcome. Please feel free to submit pull requests or report issues on our GitHub repository.
