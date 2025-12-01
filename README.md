# ZuidWest Cache Manager

A WordPress plugin for efficient Cloudflare cache management. Immediately purges high-priority URLs and queues others for batch processing via WP-Cron when content changes.

## Features

- **Smart URL Detection**: Automatically purges web URLs and REST API endpoints
- **Priority-Based Processing**: Immediate critical purges, queued less-critical URLs
- **Batch Processing**: Configurable batches to avoid API rate limits
- **Admin Interface**: Connection testing, queue management, debugging
- **WP-Cron Integration**: Reliable scheduled processing
- **URL Prefix Purging**: Uses Cloudflare's prefix purging for archives, automatically clearing paginated pages (v1.1+)
- **Taxonomy Term Handling**: Purges cache when taxonomy terms are created, edited, or deleted (v1.3+)

## How It Works

### Immediate Purging (High Priority)
When content changes, immediately purges post permalink, homepage, post type archive, and related API endpoints. This happens when posts are published, edited, or deleted.

### Queued Purging (Low Priority)
Collects and batch-processes site feed, taxonomy archives, author archives, API endpoints, and URL prefixes.

### Taxonomy Term Handling
When taxonomy terms (categories, tags, custom taxonomies) are created, updated, or deleted, automatically purges their archive pages, parent terms, and related endpoints.

## Example: How It Handles Content Changes

### Post Updates
When a WordPress post with multiple taxonomies is published or updated, the plugin intelligently manages Cloudflare cache purging in a multi-tiered approach. Here's a specific example scenario:

Let's say you have a sports news website (sportsgazette.com) and you publish a new article titled "Local Sports Team Wins Championship" with:

- Post type: `post`
- Categories: "Sports", "Local News"
- Tags: "Championship", "Basketball", "City Events"
- Custom taxonomy: "Regions" with term "Downtown"

#### Immediate High-Priority Purging

When you hit "Publish", the plugin immediately purges these URLs:

1. **The article permalink**:
   - `https://sportsgazette.com/local-sports-team-wins-championship/`

2. **Homepage**:
   - `https://sportsgazette.com/`

3. **Post type archive**:
   - `https://sportsgazette.com/blog/` or `https://sportsgazette.com/news/` (depends on your setup)

4. **REST API endpoints** (direct access):
   - `https://sportsgazette.com/wp-json/wp/v2/posts/1234/` (article API endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/posts/` (posts collection)
   - `https://sportsgazette.com/wp-json/` (API root)

These high-priority purges happen immediately to ensure the most critical URLs are fresh.

#### Queued Low-Priority Purging

Simultaneously, the plugin queues these related URLs for batch processing:

1. **Category archives**:
   - `https://sportsgazette.com/category/sports/` (as URL prefix)
   - `https://sportsgazette.com/category/local-news/` (as URL prefix)
   - `https://sportsgazette.com/category/sports/feed/` (as exact URL)
   - `https://sportsgazette.com/category/local-news/feed/` (as exact URL)

2. **Tag archives**:
   - `https://sportsgazette.com/tag/championship/` (as URL prefix)
   - `https://sportsgazette.com/tag/basketball/` (as URL prefix)
   - `https://sportsgazette.com/tag/city-events/` (as URL prefix)
   - Related tag feeds (as exact URLs)

3. **Custom taxonomy archives**:
   - `https://sportsgazette.com/region/downtown/` (as URL prefix)
   - `https://sportsgazette.com/region/downtown/feed/` (as exact URL)

4. **Author archive**:
   - `https://sportsgazette.com/author/writer-name/` (as URL prefix)
   - `https://sportsgazette.com/author/writer-name/feed/` (as exact URL)

5. **REST API related endpoints**:
   - `https://sportsgazette.com/wp-json/wp/v2/categories/5/` (Sports category endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/categories/7/` (Local News category endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/tags/12/` (Championship tag endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/tags/15/` (Basketball tag endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/tags/22/` (City Events tag endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/regions/3/` (Downtown region endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/users/42/` (Author endpoint)
   - All taxonomy collection endpoints

### Post Deletion Handling

When a post is deleted or moved to trash, the plugin handles cache purging intelligently:

   - Identifies if a post has a trashed status
   - Attempts to reconstruct the original permalink by removing "__trashed" suffix
   - Purges the post's permalink from cache
   - Purges related feeds and archives
   - Cleans up any API endpoints related to the deleted post

This ensures that when content is removed (whether temporarily or permanently), the cache is properly updated to reflect these changes.

### Taxonomy Term Updates

When a taxonomy term is created, edited, or deleted, the plugin performs similar intelligent cache purging. For example, if you update a category called "Sports":

#### Immediate High-Priority Purging

1. **The term archive page**:
   - `https://sportsgazette.com/category/sports/` (as exact URL)

2. **Homepage**:
   - `https://sportsgazette.com/`

3. **REST API endpoints**:
   - `https://sportsgazette.com/wp-json/wp/v2/categories/5/` (Sports category endpoint)
   - `https://sportsgazette.com/wp-json/wp/v2/categories/` (Categories collection)
   - `https://sportsgazette.com/wp-json/wp/v2/taxonomies/` (Taxonomies endpoint)

#### Queued Low-Priority Purging

1. **Term archive page**:
   - `https://sportsgazette.com/category/sports/` (as URL prefix, to catch pagination)

2. **Term feeds**:
   - `https://sportsgazette.com/category/sports/feed/` (as exact URL)

3. **Parent term archives** (if applicable):
   - `https://sportsgazette.com/category/parent-category/` (as both file and prefix)
   - `https://sportsgazette.com/category/parent-category/feed/` (as exact URL)

4. **Site-wide feeds**:
   - `https://sportsgazette.com/feed/` (as exact URL)

### How the Queue Processing Works

1. The plugin adds all URLs to a queue, removing any duplicates
2. Every minute, WP-Cron triggers the queue processing
3. The plugin takes a batch (default 30 URLs) from the front of the queue
4. It sends these URLs to Cloudflare's API for purging
5. If successful, these URLs are removed from the queue
6. The process continues until the queue is empty

### The Benefit of URL Prefix Purging

For archives, the plugin uses Cloudflare's URL prefix purging. This is especially valuable for handling paginated pages. For example, when purging `sportsgazette.com/category/sports/`, it also automatically clears:

- `sportsgazette.com/category/sports/page/2/`
- `sportsgazette.com/category/sports/page/3/`
- `sportsgazette.com/category/sports/page/4/`
- And all other pagination pages

Without URL prefix purging, you would need to individually purge each pagination URL, which is inefficient and might miss some pages. The prefix approach ensures that all paginated archive pages are properly refreshed when content changes, improving both cache efficiency and ensuring visitors always see up-to-date content.

## Installation & Configuration

### Download the latest release
1. Download the latest release ZIP file from the [Releases page](https://github.com/oszuidwest/zw-cacheman/releases)
2. In your WordPress admin, go to Plugins → Add New → Upload Plugin
3. Choose the downloaded ZIP file and click "Install Now"
4. Activate the plugin after installation completes

### Configuration
Configure the plugin under Settings → ZuidWest Cache:
- **Zone ID**: Cloudflare Zone ID
- **API Key**: Cloudflare API key with cache purging permissions
- **Batch Size**: URLs per batch (default: 30)
- **Debug Mode**: Enable logging

## Known Limitations

### REST API URLs with Query Strings
The plugin only purges base REST API URLs without query parameters. URLs like `/wp-json/wp/v2/posts?per_page=15&_fields=title,content` won't be automatically purged.

**Solution**: Configure Cloudflare Cache Rules to ignore query strings for REST API endpoints.

### Combined Taxonomy Feeds
The plugin purges individual taxonomy feeds (e.g., `/regio/roosendaal/feed/`) but not combined feeds with comma-separated terms (e.g., `/regio/roosendaal,bergen-op-zoom/feed/`).

**Solution**: Configure Cloudflare Cache Rules to handle these URL patterns appropriately.

## Troubleshooting

- **Connection Issues**: Test connection on settings page, verify API credentials
- **Queue Problems**: Check queue processing, ensure WP-Cron is functional
- **Debug Logs**: Enable Debug Mode, check PHP error log for "[ZW Cacheman]" entries
- **Common Issues**: Firewall blocking API, WP-Cron configuration, additional caching layers

## Requirements

- WordPress 6.7+
- PHP 8.2+
- Active Cloudflare account with API access
- Properly configured WP-Cron

## Support & License

For support, open an issue in the plugin repository.
Released under GPLv3 license.