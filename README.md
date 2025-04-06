# ZuidWest Cache Manager

A WordPress plugin that efficiently manages Cloudflare cache purging for websites. When content is published or updated, it immediately purges high-priority URLs and queues additional URLs for batch processing via WP-Cron.

## Features

- **Smart URL Detection**: Automatically detects and purges both web URLs and their corresponding REST API endpoints
- **Priority-Based Processing**: Immediately purges critical URLs and queues less important ones for later processing
- **Batch Processing**: Processes queued URLs in configurable batches to avoid API rate limits
- **Admin Interface**: Simple settings page with connection testing, queue management, and debug options
- **WP-Cron Integration**: Uses WordPress's built-in scheduling system for reliable processing

## How It Works

### Immediate Purging (High Priority)

When a post's status changes (published or updated), the plugin immediately purges:
- The post's permalink
- The site's homepage
- The site's feed
- The post type archive page
- Corresponding REST API endpoints for each of these URLs

### Queued Purging (Low Priority)

The plugin also collects and queues:
- Category/taxonomy archive URLs related to the post
- Author archive URLs
- REST API endpoints for taxonomies and authors

These URLs are processed in batches via WP-Cron, running every minute.

## Installation

1. Upload the `zw-cacheman` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → ZuidWest Cache to configure your Cloudflare API credentials

## Configuration

1. **Zone ID**: Your Cloudflare Zone ID (found in your Cloudflare dashboard)
2. **API Key**: Your Cloudflare API key with cache purging permissions
3. **Batch Size**: Number of URLs to process in each WP-Cron batch (default: 30)
4. **Debug Mode**: Enable detailed logging for troubleshooting

## Usage

Once configured, the plugin works automatically:

- When you publish or update content, high-priority URLs are purged immediately
- Less critical URLs are added to a queue for processing
- The queue is processed every minute by WP-Cron
- You can view queue status and manually process or clear the queue from the settings page

## Debugging

When Debug Mode is enabled in the plugin settings:

1. **Log Location**: Debug logs are written to the standard PHP error log
   - On most servers, this is located at `/var/log/apache2/error.log` or `/var/log/nginx/error.log`
   - If using a managed WordPress host, check their documentation for log file locations
   - You can also view logs through your hosting control panel if available

2. **Log Format**: All logs are prefixed with `[ZW Cacheman]` or `[ZW Cacheman API]` for easy filtering

3. **What Gets Logged**:
   - URL purging attempts and results
   - API request details and responses
   - Queue processing information
   - Errors or connection issues

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- Active Cloudflare account with API access
- Properly configured WP-Cron (or alternative cron implementation)

## Troubleshooting

If the plugin isn't working as expected:

1. **Check Connection Status**:
   - Use the "Test Connection" button on the settings page
   - Verify your Cloudflare API credentials are correct

2. **Monitor the Queue**:
   - Check that the queue count decreases over time
   - If the queue is growing but not processing, try the "Force Process Queue Now" button

3. **Verify WP-Cron**:
   - Ensure WordPress cron is functioning (use a plugin like WP Crontrol to verify)
   - Check if the "Next scheduled run" time is showing correctly on the settings page

4. **Review Debug Logs**:
   - Enable Debug Mode in settings
   - Check your server's PHP error log for entries starting with "[ZW Cacheman]"
   - Look for API errors or connection issues

5. **Common Issues**:
   - **API Connection Failures**: Check your firewall settings and make sure outbound requests to Cloudflare API are allowed
   - **Queue Not Processing**: Your server might be blocking WP-Cron; consider setting up a system cron job
   - **Inconsistent Cache Clearing**: Some CDN or hosting configurations may add additional caching layers

## Support

For questions, bug reports, or feature requests, open an issue in the plugin repository.

## License

This plugin is released under the GPLv3 license.