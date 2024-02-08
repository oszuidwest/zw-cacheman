# ZuidWest Cache Manager

The ZuidWest Cache Manager is a WordPress plugin developed by Streekomroep ZuidWest designed to manage cache purging efficiently within the limits of non-enterprise Cloudflare accounts. Upon publishing or editing posts, the plugin immediately purges cache for the article and homepage URLs and queues associated taxonomy URLs for low-priority batch processing via WP-Cron.

## Features

- **Immediate Cache Purging**: Automatically purges cache for high-priority URLs (e.g., the post URL and homepage) immediately upon post publishing or editing.
- **Low-Priority Batch Processing**: Queues associated taxonomy URLs and processes them in batches for efficient cache management, reducing server load.
- **Customizable Settings**: Offers a settings page in the WordPress admin area for configuring API keys, batch sizes, and debug mode.
- **Debug Mode**: Includes an optional debug mode for logging operations and troubleshooting.

## Installation

1. Download the plugin zip file.
2. Go to your WordPress admin panel, navigate to Plugins > Add New, and click on the "Upload Plugin" button.
3. Choose the downloaded zip file and click on "Install Now".
4. After the installation completes, activate the plugin through the 'Plugins' menu in WordPress.

## Configuration

Navigate to the plugin settings page located under Settings > ZuidWest Cache in the WordPress admin dashboard. Enter the following details:

- **Zone ID**: Your Cloudflare Zone ID.
- **API Key**: Your Cloudflare API Key.
- **Batch Size**: The number of URLs to process per batch during low-priority batch processing.
- **Debug Mode**: Enable or disable debug mode for logging.

After entering correct credentials the plug-in listens for post publishing and editing events to purge or queue URLs accordingly.

## Hooks and Filters

- `zwcache_add_cron_interval`: Allows adding custom cron schedules.
- `zwcache_handle_post_status_change`: Hooked into post status transitions to manage cache purging.

## Debugging

Enable debug mode in the plugin settings to log detailed information about cache purging operations, which can be helpful for troubleshooting.

## Contributing

Contributions are welcome. Please feel free to submit pull requests or report issues on our GitHub repository.