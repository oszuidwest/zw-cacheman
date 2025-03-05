# ZuidWest Cache Manager

The **ZuidWest Cache Manager** is a WordPress plugin designed to efficiently manage Cloudflare cache purges. It immediately purges high-priority URLs when posts are published or updated and queues additional taxonomy and author URLs for batch processing via WP-Cron.

## How It Works

1. **High-Priority Purging:**  
   - When a post’s status changes (either when it is published or updated), the plugin purges key URLs immediately:
     - The post’s permalink.
     - The site’s homepage.
     - The site's feed.
     - The post type archive page.
   - Additionally, for each of these URLs, a corresponding REST API endpoint is derived and purged.

2. **Low-Priority Purging:**  
   - The plugin collects URLs for taxonomies (and the author archive, if applicable) associated with the post.
   - These URLs are added to a queue and processed in batches (controlled by the batch size setting) through a WP-Cron job running every minute.

3. **WP-Cron Job:**  
   - The cron job picks a batch of queued URLs and purges them using the Cloudflare API.
   - If the cron job is not scheduled, the plugin attempts to automatically schedule it, ensuring continuous operation.