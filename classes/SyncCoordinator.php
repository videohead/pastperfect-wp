<?php

namespace PastPerfect\Archive;

/**
 * Coordinates scheduled XML sync runs.
 */
class SyncCoordinator {
	public const EVENT_RECURRING_START = 'pastperfect_sync_recurring_start';
	public const EVENT_PROCESS_CHUNK = 'pastperfect_sync_process_chunk';
	public const EVENT_MEDIA_INDEX_REFRESH = 'pastperfect_media_index_refresh';
	public const EVENT_AUTO_TAG_RESCAN = 'pastperfect_auto_tag_rescan';
	public const OPTION_SETTINGS = 'pastperfect_sync_settings';
	public const OPTION_JOB_STATE = 'pastperfect_sync_job_state';
	public const LOCK_KEY = 'pastperfect_sync_lock';
	private const LOCK_TTL = 20 * MINUTE_IN_SECONDS;
	private const DEFAULT_INCREMENT = 100;
	private const MEDIA_PROVIDERS = array(
		'wp_media_library',
		'aws_s3',
		'google_cloud_storage',
		'google_drive',
	);

	public static function bootstrap(): void {
		add_action( self::EVENT_RECURRING_START, array( __CLASS__, 'handle_recurring_start' ) );
		add_action( self::EVENT_PROCESS_CHUNK, array( __CLASS__, 'process_job' ) );
		add_action( self::EVENT_MEDIA_INDEX_REFRESH, array( __CLASS__, 'handle_media_index_refresh' ) );
		add_action( self::EVENT_AUTO_TAG_RESCAN, array( __CLASS__, 'handle_auto_tag_rescan' ) );
		add_action( 'update_option_' . self::OPTION_SETTINGS, array( __CLASS__, 'handle_settings_updated' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'initialize_schedule' ), 20 );
	}

	public static function initialize_schedule(): void {
		self::ensure_recurring_schedule();
	}

	public static function activate(): void {
		self::ensure_recurring_schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::EVENT_RECURRING_START );
		wp_clear_scheduled_hook( self::EVENT_PROCESS_CHUNK );
		wp_clear_scheduled_hook( self::EVENT_MEDIA_INDEX_REFRESH );
		wp_clear_scheduled_hook( self::EVENT_AUTO_TAG_RESCAN );
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Parse and persist sync settings from admin input.
	 *
	 * @param array $input Raw settings input.
	 */
	public static function update_settings( array $input ): array {
		$current = self::get_settings();

		$current['enabled'] = ! empty( $input['enabled'] );

		$requested_recurrence = isset( $input['recurrence'] ) ? sanitize_key( $input['recurrence'] ) : 'daily';
		$schedules = wp_get_schedules();
		$current['recurrence'] = isset( $schedules[ $requested_recurrence ] ) ? $requested_recurrence : 'daily';

		$current['source'] = isset( $input['source'] ) ? sanitize_text_field( wp_unslash( $input['source'] ) ) : '';
		$current['source_provider'] = isset( $input['source_provider'] ) ? sanitize_key( (string) wp_unslash( $input['source_provider'] ) ) : 'xml';

		$increment = isset( $input['increment'] ) ? absint( $input['increment'] ) : self::DEFAULT_INCREMENT;
		$current['increment'] = max( 1, min( 200, $increment ) );

		$provider = isset( $input['media_provider'] ) ? sanitize_key( $input['media_provider'] ) : 'wp_media_library';
		$current['media_provider'] = in_array( $provider, self::MEDIA_PROVIDERS, true ) ? $provider : 'wp_media_library';
		$current['media_source_directory'] = isset( $input['media_source_directory'] ) ? sanitize_text_field( wp_unslash( $input['media_source_directory'] ) ) : '';
		$current['media_remote_base_url'] = isset( $input['media_remote_base_url'] ) ? esc_url_raw( wp_unslash( $input['media_remote_base_url'] ) ) : '';
		$current['import_media'] = ! empty( $input['import_media'] );
		$current['media_index_refresh_enabled'] = ! empty( $input['media_index_refresh_enabled'] );

		$requested_index_recurrence = isset( $input['media_index_refresh_recurrence'] ) ? sanitize_key( (string) $input['media_index_refresh_recurrence'] ) : 'daily';
		$schedules = wp_get_schedules();
		$current['media_index_refresh_recurrence'] = isset( $schedules[ $requested_index_recurrence ] ) ? $requested_index_recurrence : 'daily';

		update_option( self::OPTION_SETTINGS, $current, false );

		return $current;
	}

	/**
	 * Return normalized sync settings.
	 */
	public static function get_settings(): array {
		$default_media_source_directory = self::get_default_media_source_directory();

		$defaults = array(
			'enabled' => false,
			'recurrence' => 'daily',
			'source' => '',
			'source_provider' => 'xml',
			'increment' => self::DEFAULT_INCREMENT,
			'media_provider' => 'wp_media_library',
			'media_source_directory' => $default_media_source_directory,
			'media_remote_base_url' => '',
			'import_media' => true,
			'media_index_refresh_enabled' => false,
			'media_index_refresh_recurrence' => 'daily',
		);

		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, $defaults );
		$settings['enabled'] = ! empty( $settings['enabled'] );
		$providers = apply_filters( 'ppwp_sync_source_providers', array( 'xml' => __( 'XML file', 'pastperfect-wp' ) ) );
		if ( ! is_array( $providers ) ) {
			$providers = array( 'xml' => __( 'XML file', 'pastperfect-wp' ) );
		}
		$provider = sanitize_key( (string) $settings['source_provider'] );
		$settings['source_provider'] = isset( $providers[ $provider ] ) ? $provider : 'xml';
		$settings['increment'] = max( 1, min( 200, absint( $settings['increment'] ) ) );
		$settings['media_provider'] = in_array( $settings['media_provider'], self::MEDIA_PROVIDERS, true ) ? $settings['media_provider'] : 'wp_media_library';
		$settings['media_source_directory'] = sanitize_text_field( (string) $settings['media_source_directory'] );
		if ( '' === $settings['media_source_directory'] ) {
			$settings['media_source_directory'] = $default_media_source_directory;
		}
		$settings['media_remote_base_url'] = esc_url_raw( (string) $settings['media_remote_base_url'] );
		$settings['import_media'] = ! empty( $settings['import_media'] );
		$settings['media_index_refresh_enabled'] = ! empty( $settings['media_index_refresh_enabled'] );

		$index_recurrence = sanitize_key( (string) $settings['media_index_refresh_recurrence'] );
		$schedules = wp_get_schedules();
		$settings['media_index_refresh_recurrence'] = isset( $schedules[ $index_recurrence ] ) ? $index_recurrence : 'daily';

		return $settings;
	}

	/**
	 * Start a sync job from cron or manual trigger.
	 *
	 * @param string     $source            Trigger source tag.
	 * @param bool       $force             Force start even when another lock exists.
	 * @param array|null $settings_override Optional normalized settings override.
	 */
	public static function start_job( string $source = 'manual', bool $force = false, ?array $settings_override = null ) {
		if ( self::is_locked() && ! $force ) {
			return new \WP_Error( 'pastperfect_sync_locked', __( 'A PastPerfect sync job is already running.', 'pastperfect-wp' ) );
		}

		$settings = is_array( $settings_override ) ? $settings_override : self::get_settings();
		if ( empty( $settings['source'] ) ) {
			return new \WP_Error( 'pastperfect_sync_missing_source', __( 'No source has been configured for scheduled sync.', 'pastperfect-wp' ) );
		}

		self::lock();

		$selected_provider = isset( $settings['source_provider'] ) ? sanitize_key( (string) $settings['source_provider'] ) : 'xml';
		$provider_run = null;
		if ( 'xml' !== $selected_provider ) {
			$provider_run = apply_filters( 'ppwp_create_import_run_from_source', null, $settings, $source );
		}
		if ( is_wp_error( $provider_run ) ) {
			self::unlock();
			return $provider_run;
		}

		if ( ! is_array( $provider_run ) && 'xml' !== $selected_provider ) {
			self::unlock();
			return new \WP_Error(
				'pastperfect_sync_provider_unhandled',
				sprintf(
					/* translators: 1: source provider key, 2: configured source value */
					__( 'Selected source provider "%1$s" is not available in this request for source "%2$s". Ensure the provider plugin is active and loaded before running sync.', 'pastperfect-wp' ),
					$selected_provider,
					(string) $settings['source']
				)
			);
		}

		if ( is_array( $provider_run ) ) {
			$run = $provider_run;
		} else {
			$local_file = Admin::resolve_sync_source_to_local_file( $settings['source'] );
			if ( is_wp_error( $local_file ) ) {
				self::unlock();
				return $local_file;
			}

			$run = Admin::create_import_run_from_file( $local_file, $settings );
		}

		if ( is_wp_error( $run ) ) {
			self::unlock();
			return $run;
		}

		$job_state = array(
			'status' => 'running',
			'source' => sanitize_key( $source ),
			'run' => $run['run'],
			'run_key' => $run['run_key'],
			'count' => $run['count'],
			'record_element' => $run['record_element'],
			'last' => 0,
			'increment' => $settings['increment'],
			'started_at' => current_time( 'mysql', true ),
			'finished_at' => null,
			'counts' => array(
				'created' => 0,
				'updated' => 0,
				'failed' => 0,
				'media_found' => 0,
			),
			'last_error' => '',
		);

		update_option( self::OPTION_JOB_STATE, $job_state, false );
		wp_schedule_single_event( time() + 3, self::EVENT_PROCESS_CHUNK );

		return $job_state;
	}

	public static function handle_recurring_start(): void {
		self::start_job( 'scheduled', false );
	}

	public static function handle_media_index_refresh(): void {
		MediaIndex::refresh_from_settings();
	}

	/**
	 * Stop the currently running sync job.
	 */
	public static function stop_job( string $source = 'manual' ): bool {
		$job_state = get_option( self::OPTION_JOB_STATE, array() );
		$was_running = is_array( $job_state ) && ( $job_state['status'] ?? '' ) === 'running';

		if ( ! is_array( $job_state ) ) {
			$job_state = array();
		}

		$job_state['status'] = 'stopped';
		$job_state['source'] = sanitize_key( $source );
		$job_state['finished_at'] = current_time( 'mysql', true );
		$job_state['last_error'] = '';

		update_option( self::OPTION_JOB_STATE, $job_state, false );
		wp_clear_scheduled_hook( self::EVENT_PROCESS_CHUNK );
		self::unlock();

		return $was_running;
	}

	public static function handle_auto_tag_rescan(): void {
		$taxonomies = Admin::get_auto_tag_taxonomies();
		if ( empty( $taxonomies ) ) {
			return;
		}

		$page = 1;
		do {
			$post_ids = get_posts(
				array(
					'post_type' => 'archive_item',
					'post_status' => 'any',
					'posts_per_page' => 200,
					'paged' => $page,
					'fields' => 'ids',
					'no_found_rows' => true,
				)
			);

			if ( empty( $post_ids ) ) {
				break;
			}

			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) {
					continue;
				}

				foreach ( $taxonomies as $taxonomy ) {
					Admin::maybe_auto_tag_post_from_content( $post_id, (string) $taxonomy );
				}
			}

			$page++;
		} while ( count( $post_ids ) === 200 && $page <= 25 );
	}

	public static function process_job(): void {
		$job_state = get_option( self::OPTION_JOB_STATE, array() );

		if ( empty( $job_state ) || ( $job_state['status'] ?? '' ) !== 'running' ) {
			self::unlock();
			return;
		}

		self::lock();

		$run = $job_state['run'] ?? '';
		$run_data = Admin::get_run_data( $run );
		if ( empty( $run ) || ! is_array( $run_data ) ) {
			$job_state['last_error'] = __( 'Could not find stored run data for scheduled sync.', 'pastperfect-wp' );
			self::finish_job( $job_state, 'failed' );
			return;
		}

		try {
			$run_data['run'] = $run;
			$result = Admin::process_import_chunk_data(
				$run_data,
				absint( $job_state['last'] ?? 0 ),
				absint( $job_state['increment'] ?? self::DEFAULT_INCREMENT )
			);

			$latest_state = get_option( self::OPTION_JOB_STATE, array() );
			if ( ! is_array( $latest_state ) || ( $latest_state['status'] ?? '' ) !== 'running' ) {
				self::unlock();
				return;
			}

			$job_state['last'] = absint( $result['current'] );
					$job_state['counts'] = self::merge_counts( $job_state['counts'], $result['results'] );
					// Merge media_found count if provided by the import processor
					$job_state['counts']['media_found'] = ( isset( $job_state['counts']['media_found'] ) ? intval( $job_state['counts']['media_found'] ) : 0 ) + ( isset( $result['media_found'] ) ? intval( $result['media_found'] ) : 0 );
			$job_state['last_error'] = '';

			if ( $job_state['last'] >= absint( $run_data['count'] ) ) {
				self::finish_job( $job_state, 'completed' );
				return;
			}

			update_option( self::OPTION_JOB_STATE, $job_state, false );
			wp_schedule_single_event( time() + 3, self::EVENT_PROCESS_CHUNK );
		} catch ( \Throwable $throwable ) {
			$job_state['last_error'] = $throwable->getMessage();
			self::finish_job( $job_state, 'failed' );
		}
	}

	public static function finish_job( array $job_state, string $status ): void {
		$job_state['status'] = $status;
		$job_state['finished_at'] = current_time( 'mysql', true );
		update_option( self::OPTION_JOB_STATE, $job_state, false );

		// Write a sync summary to the sync log
		try {
			$context = array(
				'run' => $job_state['run'] ?? '',
				'status' => $job_state['status'] ?? '',
				'total_records' => isset( $job_state['count'] ) ? intval( $job_state['count'] ) : 0,
				'attempted' => isset( $job_state['last'] ) ? intval( $job_state['last'] ) : 0,
				'media_found' => isset( $job_state['counts']['media_found'] ) ? intval( $job_state['counts']['media_found'] ) : 0,
				'counts' => $job_state['counts'] ?? array(),
				'started_at' => $job_state['started_at'] ?? '',
				'finished_at' => $job_state['finished_at'] ?? '',
			);
			Sync_Logger::write( 'info', 'sync_summary', $context );
		} catch ( \Throwable $e ) {
			// Swallow logging errors so job finish isn't blocked
		}

		self::unlock();
	}

	/**
	 * Handle settings updates by keeping cron schedule aligned.
	 *
	 * @param mixed $old_value Old option value.
	 * @param mixed $new_value New option value.
	 */
	public static function handle_settings_updated( $old_value, $new_value ): void {
		self::ensure_recurring_schedule();
	}

	private static function ensure_recurring_schedule(): void {
		$settings = self::get_settings();
		$next = wp_next_scheduled( self::EVENT_RECURRING_START );

		if ( ! $settings['enabled'] ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::EVENT_RECURRING_START );
			}
			self::ensure_media_index_refresh_schedule( $settings );
			self::ensure_auto_tag_rescan_schedule( $settings );
			return;
		}

		$schedules = wp_get_schedules();
		$recurrence = $settings['recurrence'];
		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$recurrence = 'daily';
		}

		if ( $next ) {
			$current_schedule = wp_get_schedule( self::EVENT_RECURRING_START );
			if ( $current_schedule === $recurrence ) {
				self::ensure_media_index_refresh_schedule( $settings );
				self::ensure_auto_tag_rescan_schedule( $settings );
				return;
			}
			wp_clear_scheduled_hook( self::EVENT_RECURRING_START );
		}

		wp_schedule_event( self::get_next_timestamp(), $recurrence, self::EVENT_RECURRING_START );
		self::ensure_media_index_refresh_schedule( $settings );
		self::ensure_auto_tag_rescan_schedule( $settings );
	}

	private static function ensure_auto_tag_rescan_schedule( array $settings ): void {
		$next = wp_next_scheduled( self::EVENT_AUTO_TAG_RESCAN );
		$taxonomies = Admin::get_auto_tag_taxonomies();

		if ( empty( $taxonomies ) ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::EVENT_AUTO_TAG_RESCAN );
			}
			return;
		}

		$schedules = wp_get_schedules();
		$recurrence = sanitize_key( (string) ( $settings['recurrence'] ?? 'daily' ) );
		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$recurrence = 'daily';
		}

		if ( $next ) {
			$current_schedule = wp_get_schedule( self::EVENT_AUTO_TAG_RESCAN );
			if ( $current_schedule === $recurrence ) {
				return;
			}
			wp_clear_scheduled_hook( self::EVENT_AUTO_TAG_RESCAN );
		}

		wp_schedule_event( self::get_next_timestamp(), $recurrence, self::EVENT_AUTO_TAG_RESCAN );
	}

	private static function ensure_media_index_refresh_schedule( array $settings ): void {
		$next = wp_next_scheduled( self::EVENT_MEDIA_INDEX_REFRESH );

		if ( empty( $settings['media_index_refresh_enabled'] ) ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::EVENT_MEDIA_INDEX_REFRESH );
			}
			return;
		}

		$source = trim( (string) ( $settings['media_source_directory'] ?? '' ) );
		if ( '' === $source ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::EVENT_MEDIA_INDEX_REFRESH );
			}
			return;
		}

		$schedules = wp_get_schedules();
		$recurrence = sanitize_key( (string) ( $settings['media_index_refresh_recurrence'] ?? 'daily' ) );
		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$recurrence = 'daily';
		}

		if ( $next ) {
			$current_schedule = wp_get_schedule( self::EVENT_MEDIA_INDEX_REFRESH );
			if ( $current_schedule === $recurrence ) {
				return;
			}
			wp_clear_scheduled_hook( self::EVENT_MEDIA_INDEX_REFRESH );
		}

		wp_schedule_event( self::get_next_timestamp(), $recurrence, self::EVENT_MEDIA_INDEX_REFRESH );
	}

	private static function get_next_timestamp(): int {
		$timezone = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $timezone );
		$next = $now->setTime( 3, 15, 0 );

		if ( $now >= $next ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	private static function merge_counts( array $existing, array $results ): array {
		foreach ( $results as $result ) {
			$status = $result['status'] ?? '';
			if ( isset( $existing[ $status ] ) ) {
				$existing[ $status ]++;
			}
		}

		return $existing;
	}

	private static function lock(): void {
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
	}

	private static function unlock(): void {
		delete_transient( self::LOCK_KEY );
	}

	private static function is_locked(): bool {
		return (bool) get_transient( self::LOCK_KEY );
	}

	private static function get_default_media_source_directory(): string {
		$uploads = wp_get_upload_dir();
		$default_path = trailingslashit( (string) $uploads['basedir'] ) . 'pp5_share';

		if ( is_dir( $default_path ) ) {
			return $default_path;
		}

		return '';
	}
}
