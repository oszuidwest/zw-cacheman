<?php
/**
 * Core Cache Manager functionality.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the core cache purging functionality.
 */
readonly class CachemanManager {

	/**
	 * Maximum time spent waiting to mutate the purge queue.
	 */
	private const float QUEUE_LOCK_WAIT_SECONDS = 31.0;

	/**
	 * Time after which an abandoned purge queue lock may be taken over.
	 */
	private const float QUEUE_LOCK_TTL_SECONDS = 30.0;

	/**
	 * Constructor
	 *
	 * @param CachemanAPI       $api        The API handler instance.
	 * @param CachemanUrlDelver $url_delver The URL delver instance.
	 * @param CachemanLogger    $logger     The logger instance.
	 * @param CachemanWarmer    $warmer     The cache warmer instance.
	 */
	public function __construct(
		private CachemanAPI $api,
		private CachemanUrlDelver $url_delver,
		private CachemanLogger $logger,
		private CachemanWarmer $warmer
	) {
		// Hook into post status transitions.
		add_action( 'transition_post_status', $this->handle_post_status_change( ... ), 10, 3 );

		// Hook into post deletion.
		add_action( 'before_delete_post', $this->handle_post_deletion( ... ), 10, 2 );

		// Hook into taxonomy term changes.
		add_action( 'created_term', $this->handle_term_change( ... ), 10, 3 );
		add_action( 'edited_term', $this->handle_term_change( ... ), 10, 3 );
		add_action( 'delete_term', $this->handle_term_deletion( ... ), 10, 3 );

		// Set up cron handler.
		add_action( ZW_CACHEMAN_CRON_HOOK, $this->process_queue( ... ) );

		// Check if cron is scheduled.
		if ( ! wp_next_scheduled( ZW_CACHEMAN_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_minute', ZW_CACHEMAN_CRON_HOOK );
			$this->logger->debug( 'Manager', 'Scheduled missing cron job' );
		}

		// Add debug logging for cron scheduling checks (WP 6.8+).
		add_filter( 'wp_next_scheduled', $this->log_cron_check( ... ), 10, 3 );
	}

	/**
	 * Log cron scheduling checks for debugging (WP 6.8+)
	 *
	 * @param int|false   $timestamp  Unix timestamp of next scheduled event, or false.
	 * @param object|null $next_event The next scheduled event object.
	 * @param string      $hook       The hook name being checked.
	 * @return int|false The unmodified timestamp.
	 */
	public function log_cron_check( int|false $timestamp, ?object $next_event, string $hook ): int|false {
		// Only log checks for our own cron hook, and only once per request.
		static $logged = false;

		if ( ZW_CACHEMAN_CRON_HOOK === $hook && ! $logged ) {
			$logged = true;

			if ( $timestamp ) {
				$this->logger->debug(
					'Cron',
					'Next scheduled run: ' . gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC (in ' . ( $timestamp - time() ) . ' seconds)'
				);
			} else {
				$this->logger->debug( 'Cron', 'Hook not scheduled' );
			}
		}

		return $timestamp;
	}

	/**
	 * Handle post status changes
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post Post object.
	 */
	public function handle_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		// Skip if autosave or revision.
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		$this->logger->debug( 'Manager', 'Post ' . $post->ID . ' (' . $post->post_title . ') status changed from ' . $old_status . ' to ' . $new_status );

		// Only process on publish/unpublish.
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			// High priority purge items to process immediately.
			$this->purge_now_or_queue(
				$this->url_delver->get_high_priority_purge_items( $post ),
				'high priority purge items for post ID ' . $post->ID
			);

			// Queue low priority items for later processing.
			$low_priority_items = $this->url_delver->get_low_priority_purge_items( $post );

			if ( ! empty( $low_priority_items ) ) {
				$this->logger->debug( 'Manager', 'Queueing ' . count( $low_priority_items ) . ' low priority purge items for post ID ' . $post->ID );
				$this->queue_purge_items( $low_priority_items );
			} else {
				$this->logger->debug( 'Manager', 'No low priority purge items found for post ID ' . $post->ID );
			}
		}
	}

	/**
	 * Handle post deletion
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function handle_post_deletion( int $post_id, \WP_Post $post ): void {
		// Skip if autosave or revision.
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		$this->logger->debug( 'Manager', 'Post ' . $post_id . ' (' . $post->post_title . ') was deleted' );

		// Get purge items for a deleted post.
		$this->purge_now_or_queue(
			$this->url_delver->get_deleted_post_purge_items( $post_id, $post ),
			'purge items for deleted post ID ' . $post_id
		);
	}

	/**
	 * Handle taxonomy term changes (create/edit)
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function handle_term_change( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->logger->debug( 'Manager', 'Term ' . $term_id . ' in taxonomy ' . $taxonomy . ' was created or updated' );

		// Get the term.
		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) ) {
			$this->logger->error( 'Manager', 'Failed to get term: ' . $term->get_error_message() );
			return;
		}

		// High priority purge items to process immediately.
		$this->purge_now_or_queue(
			$this->url_delver->get_high_priority_term_purge_items( $term, $taxonomy ),
			'high priority purge items for term ID ' . $term_id
		);

		// Queue low priority items for later processing.
		$low_priority_items = $this->url_delver->get_low_priority_term_purge_items( $term, $taxonomy );

		if ( ! empty( $low_priority_items ) ) {
			$this->logger->debug( 'Manager', 'Queueing ' . count( $low_priority_items ) . ' low priority purge items for term ID ' . $term_id );
			$this->queue_purge_items( $low_priority_items );
		} else {
			$this->logger->debug( 'Manager', 'No low priority purge items found for term ID ' . $term_id );
		}
	}

	/**
	 * Handle taxonomy term deletion
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function handle_term_deletion( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->logger->debug( 'Manager', 'Term ' . $term_id . ' in taxonomy ' . $taxonomy . ' was deleted' );

		// Get purge items for a deleted term.
		$this->purge_now_or_queue(
			$this->url_delver->get_deleted_term_purge_items( $term_id, $taxonomy ),
			'purge items for deleted term ID ' . $term_id
		);
	}

	/**
	 * Purge items immediately, re-queueing them when the purge fails.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items       Items to purge.
	 * @param string                                     $description Item description for logs.
	 */
	private function purge_now_or_queue( array $items, string $description ): void {
		if ( empty( $items ) ) {
			$this->logger->debug( 'Manager', 'No ' . $description . ' found' );
			return;
		}

		$this->logger->debug( 'Manager', 'Processing ' . count( $items ) . ' ' . $description );

		$failed_items = $this->purge_items( $items );
		if ( ! empty( $failed_items ) ) {
			$this->logger->error( 'Manager', 'Failed to process ' . count( $failed_items ) . ' ' . $description );
			$this->queue_purge_items( $failed_items );
		}
	}

	/**
	 * Purge items via the API, queueing them for cache warming on success.
	 *
	 * @param array<array{type: PurgeType, url: string}> $items Items to purge.
	 * @return array<array{type: PurgeType, url: string}> Items whose purge failed.
	 */
	private function purge_items( array $items ): array {
		$results = $this->api->process_purge_items_by_type( $items );

		$this->warmer->enqueue(
			array_values(
				array_filter(
					$items,
					static fn ( array $item ): bool => PurgeType::File === $item['type'] && $results[ PurgeType::File->value ]
				)
			)
		);

		return array_values(
			array_filter(
				$items,
				static fn ( array $item ): bool => ! $results[ $item['type']->value ]
			)
		);
	}

	/**
	 * Stable identity of a purge item, used for queue deduplication and for
	 * removing processed items.
	 *
	 * @param array{type: PurgeType, url: string} $item Purge item.
	 * @return string
	 */
	private static function item_key( array $item ): string {
		return $item['type']->value . '|' . $item['url'];
	}

	/**
	 * Invalidate one option in every cache used by get_option().
	 *
	 * @param string $option Option name.
	 */
	private static function invalidate_option_cache( string $option ): void {
		wp_cache_delete( $option, 'options' );

		foreach ( [ 'alloptions', 'notoptions' ] as $cache_key ) {
			$cached_options = wp_cache_get( $cache_key, 'options' );
			if ( is_array( $cached_options ) && isset( $cached_options[ $option ] ) ) {
				unset( $cached_options[ $option ] );
				wp_cache_set( $cache_key, $cached_options, 'options' );
			}
		}
	}

	/**
	 * Read the current purge queue, bypassing any request-local snapshot.
	 *
	 * @return array<array{type: PurgeType, url: string}>
	 */
	private static function get_current_purge_queue(): array {
		self::invalidate_option_cache( ZW_CACHEMAN_QUEUE );
		$queue = get_option( ZW_CACHEMAN_QUEUE, [] );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Acquire the shared purge queue mutation lock.
	 *
	 * An INSERT IGNORE provides the atomic insert. The conditional database
	 * update only recovers locks abandoned by a terminated request.
	 *
	 * @return string|null The owned lock value, or null when acquisition timed out.
	 */
	private function acquire_purge_queue_lock(): ?string {
		global $wpdb;

		$deadline = microtime( true ) + self::QUEUE_LOCK_WAIT_SECONDS;

		do {
			$lock_value = sprintf(
				'%.6F:%s',
				microtime( true ) + self::QUEUE_LOCK_TTL_SECONDS,
				wp_generate_uuid4()
			);

			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
					$wpdb->options,
					ZW_CACHEMAN_QUEUE_LOCK,
					$lock_value,
					'off'
				)
			);

			if ( 1 === $inserted ) {
				self::invalidate_option_cache( ZW_CACHEMAN_QUEUE_LOCK );
				return $lock_value;
			}

			$stored_lock = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s',
					$wpdb->options,
					ZW_CACHEMAN_QUEUE_LOCK
				)
			);

			if ( ! is_string( $stored_lock ) ) {
				self::invalidate_option_cache( ZW_CACHEMAN_QUEUE_LOCK );
				usleep( 10_000 );
				continue;
			}

			$expires_at = (float) strstr( $stored_lock, ':', true );
			if ( $expires_at <= microtime( true ) ) {
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
						$wpdb->options,
						$lock_value,
						ZW_CACHEMAN_QUEUE_LOCK,
						$stored_lock
					)
				);

				if ( 1 === $updated ) {
					self::invalidate_option_cache( ZW_CACHEMAN_QUEUE_LOCK );
					return $lock_value;
				}
			}

			usleep( 10_000 );
		} while ( microtime( true ) < $deadline );

		return null;
	}

	/**
	 * Release the purge queue mutation lock when it is still owned.
	 *
	 * @param string $lock_value Value returned by acquire_purge_queue_lock().
	 */
	private function release_purge_queue_lock( string $lock_value ): void {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				ZW_CACHEMAN_QUEUE_LOCK,
				$lock_value
			)
		);

		if ( 1 === $deleted ) {
			self::invalidate_option_cache( ZW_CACHEMAN_QUEUE_LOCK );
			return;
		}

		$this->logger->error( 'Manager', 'Purge queue mutation lock expired before it could be released.' );
	}

	/**
	 * Queue purge items for later processing.
	 *
	 * Every queue read-modify-write operation uses the same short-lived lock,
	 * preventing producers, consumers, and admin clears from overwriting each
	 * other's changes.
	 *
	 * @param array<array{type: PurgeType, url: string}> $purge_items Items to add to the queue.
	 */
	public function queue_purge_items( array $purge_items ): void {
		if ( empty( $purge_items ) ) {
			return;
		}

		$lock_value = $this->acquire_purge_queue_lock();
		if ( null === $lock_value ) {
			$this->logger->error( 'Manager', 'Could not acquire purge queue lock; items were not queued.' );
			return;
		}

		try {
			$existing_items = self::get_current_purge_queue();

			// Combine and deduplicate based on URL and type.
			$all_items   = [];
			$unique_keys = [];

			// Process existing items first.
			foreach ( $existing_items as $item ) {
				$key = self::item_key( $item );
				if ( ! isset( $unique_keys[ $key ] ) ) {
					$unique_keys[ $key ] = true;
					$all_items[]         = $item;
				}
			}

			// Add new items if not already in queue.
			foreach ( $purge_items as $item ) {
				$key = self::item_key( $item );
				if ( ! isset( $unique_keys[ $key ] ) ) {
					$unique_keys[ $key ] = true;
					$all_items[]         = $item;
				}
			}

			$added_count = count( $all_items ) - count( $existing_items );
			update_option( ZW_CACHEMAN_QUEUE, $all_items, false );
		} finally {
			$this->release_purge_queue_lock( $lock_value );
		}

		$this->logger->debug( 'Manager', 'Added ' . $added_count . ' new purge items to queue. Total in queue: ' . count( $all_items ) );
	}

	/**
	 * Clear the purge queue while holding the shared mutation lock.
	 *
	 * @return int|null Number of removed items, or null when the lock timed out.
	 */
	public function clear_purge_queue(): ?int {
		$lock_value = $this->acquire_purge_queue_lock();
		if ( null === $lock_value ) {
			$this->logger->error( 'Manager', 'Could not acquire purge queue lock; queue was not cleared.' );
			return null;
		}

		try {
			$queue       = self::get_current_purge_queue();
			$queue_count = count( $queue );
			delete_option( ZW_CACHEMAN_QUEUE );
		} finally {
			$this->release_purge_queue_lock( $lock_value );
		}

		return $queue_count;
	}

	/**
	 * Process the queue - called by WP-Cron.
	 *
	 * Worst-case duration of one pass: batch_size is capped at
	 * CachemanAPI::PREFIX_BATCH_SIZE (30), so the purge phase sends at most
	 * one 'files' and one 'prefixes' request (2 x 30s timeout = 60s), and
	 * the warm phase adds at most 5 fetches x 10s = 50s (see CachemanWarmer).
	 * That 110s bound exceeds WordPress's cron lock (WP_CRON_LOCK_TIMEOUT,
	 * 60s by default), so a slow pass can overlap the next spawn. Overlap is
	 * benign: both runs may purge the same head-of-queue batch (duplicate
	 * Cloudflare requests), but all purge queue mutations use the same lock,
	 * and the warm queue is best-effort by design.
	 */
	public function process_queue(): void {
		$this->process_purge_queue();

		// Purge first, then drain the warm queue so pages are re-fetched
		// after their cache entries are gone.
		$this->warmer->process_queue();
	}

	/**
	 * Purge a batch of queued items.
	 *
	 * The Cloudflare calls can take tens of seconds, and producers keep
	 * enqueueing in the meantime. So instead of writing back a pre-call
	 * snapshot (which would erase those concurrent enqueues), the queue is
	 * re-read after the calls and only the successfully purged items are
	 * removed. The final read-modify-write uses the same short-lived lock as
	 * producers and admin clears. Failed items keep their place at the head
	 * of the queue and retry next run.
	 */
	private function process_purge_queue(): void {
		$queue = get_option( ZW_CACHEMAN_QUEUE, [] );
		if ( empty( $queue ) ) {
			$this->logger->debug( 'Manager', 'Queue is empty. Nothing to process.' );
			return;
		}

		$settings   = get_option( ZW_CACHEMAN_SETTINGS, [] );
		$batch_size = ! empty( $settings['batch_size'] ) ? (int) $settings['batch_size'] : 30;

		// Clamp values stored before sanitize_settings() enforced the cap.
		$batch_size = max( 1, min( $batch_size, CachemanAPI::PREFIX_BATCH_SIZE ) );

		// Take a batch of items from the head of the queue.
		$items_to_process = array_slice( $queue, 0, $batch_size );
		$remaining_count  = count( $queue ) - count( $items_to_process );

		$this->logger->debug( 'Manager', 'Processing ' . count( $items_to_process ) . ' items (' . $remaining_count . ' remaining)' );

		$failed_items = $this->purge_items( $items_to_process );

		$failed_keys = [];
		foreach ( $failed_items as $item ) {
			$failed_keys[ self::item_key( $item ) ] = true;
		}

		$purged_keys = [];
		foreach ( $items_to_process as $item ) {
			$key = self::item_key( $item );
			if ( ! isset( $failed_keys[ $key ] ) ) {
				$purged_keys[ $key ] = true;
			}
		}

		if ( empty( $purged_keys ) ) {
			$this->logger->error( 'Manager', 'Failed to process batch of ' . count( $items_to_process ) . ' items. Will retry next run.' );
			return;
		}

		$lock_value = $this->acquire_purge_queue_lock();
		if ( null === $lock_value ) {
			$this->logger->error( 'Manager', 'Could not acquire purge queue lock; purged items will retry next run.' );
			return;
		}

		try {
			// Re-read the queue and drop only what was purged (autoload
			// disabled for performance).
			$current_queue = self::get_current_purge_queue();
			$next_queue    = array_values(
				array_filter(
					$current_queue,
					static fn ( array $item ): bool => ! isset( $purged_keys[ self::item_key( $item ) ] )
				)
			);

			update_option( ZW_CACHEMAN_QUEUE, $next_queue, false );
		} finally {
			$this->release_purge_queue_lock( $lock_value );
		}

		if ( empty( $failed_items ) ) {
			$this->logger->debug( 'Manager', 'Successfully processed batch. ' . count( $next_queue ) . ' items remaining in queue.' );
		} else {
			$this->logger->error(
				'Manager',
				'Failed to process ' . count( $failed_items ) . ' of ' . count( $items_to_process ) . ' items. Failed items will retry next run.'
			);
		}
	}
}
