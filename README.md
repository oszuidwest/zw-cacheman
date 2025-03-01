# ZuidWest Cache Manager for Cloudflare

The **ZuidWest Cache Manager** is a WordPress plugin designed to manage Cloudflare cache purges efficiently. It immediately purges high-priority URLs when posts are published or updated, and queues associated taxonomy URLs for low-priority batch processing via WP-Cron.

## How It Works

1. **High-Priority Purging:**
   - When a post's status changes to or from "publish", the plugin immediately purges key URLs including:
     - The post’s permalink.
     - The site’s homepage.
     - The site's feed.
     - The post type archive page.
     
2. **Low-Priority Purging:**
   - The plugin collects additional URLs associated with the post’s taxonomies (and the author's archive for posts) and queues them.
   - A cron job scheduled to run every minute processes these queued URLs in batches, ensuring that the purge process does not overwhelm your server or Cloudflare API.

3. **WP-Cron Integration:**
   - If the cron job is missing or overdue, the plugin attempts to reschedule it automatically.
   - A manual execution option is available in the settings page to trigger the queued processing immediately.
