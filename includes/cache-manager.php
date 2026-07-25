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
	 * Invalidate the non-autoloaded purge queue cache.
	 */
	private static function invalidate_purge_queue_cache(): void {
		wp_cache_delete( ZW_CACHEMAN_QUEUE, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ ZW_CACHEMAN_QUEUE ] ) ) {
			unset( $notoptions[ ZW_CACHEMAN_QUEUE ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Read the current purge queue, bypassing any request-local snapshot.
	 *
	 * @return array<array{type: PurgeType, url: string}>
	 */
	private static function get_current_purge_queue(): array {
		self::invalidate_purge_queue_cache();
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

		$deadline    = microtime( true ) + self::QUEUE_LOCK_WAIT_SECONDS;
		$lock_id     = wp_generate_uuid4();
		$retry_delay = 10_000;

		do {
			$lock_value = sprintf(
				'%.6F:%s',
				microtime( true ) + self::QUEUE_LOCK_TTL_SECONDS,
				$lock_id
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
				return $lock_value;
			}

			$stored_lock = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s',
					$wpdb->options,
					ZW_CACHEMAN_QUEUE_LOCK
				)
			);

			if ( is_string( $stored_lock ) ) {
				$expires_at = (float) strstr( $stored_lock, ':', true );
				if ( $expires_at <= microtime( true ) ) {
					$updated = $wpdb->update(
						$wpdb->options,
						[ 'option_value' => $lock_value ],
						[
							'option_name'  => ZW_CACHEMAN_QUEUE_LOCK,
							'option_value' => $stored_lock,
						],
						[ '%s' ],
						[ '%s', '%s' ]
					);

					if ( 1 === $updated ) {
						return $lock_value;
					}
				}
			}

			usleep( $retry_delay );
			$retry_delay = min( $retry_delay * 2, 100_000 );
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

		$deleted = $wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => ZW_CACHEMAN_QUEUE_LOCK,
				'option_value' => $lock_value,
			],
			[ '%s', '%s' ]
		);

		if ( 1 === $deleted ) {
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

			// Preserve the first item for each URL and type.
			$items_by_key = [];
			foreach ( array_merge( $existing_items, $purge_items ) as $item ) {
				$items_by_key[ self::item_key( $item ) ] ??= $item;
			}
			$all_items = array_values( $items_by_key );

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
			$queue_count = count( self::get_current_purge_queue() );
			delete_option( ZW_CACHEMAN_QUEUE );
		} finally {
			$this->release_purge_queue_lock( $lock_value );
		}

		return $queue_count;
	}

	/**
	 * Process the queue - called by WP-Cron.
	 *
	 * Network timeouts can make a pass outlive WordPress's cron lock, so
	 * overlapping runs may purge the same batch. Queue mutations remain
	 * serialized, and warming is best-effort.
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
	 * Re-read the queue after the Cloudflare calls and remove only successful
	 * items under the mutation lock. This preserves concurrent enqueues and
	 * leaves failed items at the head for retry.
	 */
	private function process_purge_queue(): void {
		$queue = get_option( ZW_CACHEMAN_QUEUE, [] );
		if ( empty( $queue ) ) {
			$this->logger->debug( 'Manager', 'Queue is empty. Nothing to process.' );
			return;
		}

		$settings   = get_option( ZW_CACHEMAN_SETTINGS, [] );
		$batch_size = ! empty( $settings['batch_size'] )
			? (int) $settings['batch_size']
			: CachemanAdmin::DEFAULT_SETTINGS['batch_size'];

		// Clamp values stored before sanitize_settings() enforced the cap.
		$batch_size = max( 1, min( $batch_size, CachemanAPI::PREFIX_BATCH_SIZE ) );

		// Take a batch of items from the head of the queue.
		$items_to_process = array_slice( $queue, 0, $batch_size );
		$remaining_count  = count( $queue ) - count( $items_to_process );

		$this->logger->debug( 'Manager', 'Processing ' . count( $items_to_process ) . ' items (' . $remaining_count . ' remaining)' );

		$failed_items = $this->purge_items( $items_to_process );

		$purged_keys = [];
		foreach ( $items_to_process as $item ) {
			$purged_keys[ self::item_key( $item ) ] = true;
		}
		foreach ( $failed_items as $item ) {
			unset( $purged_keys[ self::item_key( $item ) ] );
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
