<?php

namespace PastPerfect\Archive;

/**
 * Direct import CLI command for running imports without wp-cron.
 */
class ImportCLI {
	/**
	 * Bootstrap CLI command when WP-CLI is available.
	 */
	public static function bootstrap(): void {
		if ( defined( 'WP_CLI' ) && true === constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'ppwp import-direct', array( __CLASS__, 'cli_import_direct' ) );
		}
	}

	/**
	 * Run import directly without wp-cron scheduling.
	 *
	 * ## OPTIONS
	 *
	 * --input=<type>
	 * : Source type: xml or dbf. Default: xml.
	 *
	 * --file-path=<path>
	 * : Absolute file path to the XML or DBF file (or XML analog for DBF).
	 *
	 * --media-path=<path>
	 * : Absolute path to directory containing referenced media files.
	 *
	 * [--increment=<number>]
	 * : Records to process per chunk. Default: 100. Min: 1, Max: 200.
	 *
	 * [--media-provider=<provider>]
	 * : wp_media_library|aws_s3|google_cloud_storage|google_drive. Default: wp_media_library.
	 *
	 * [--media-base-url=<url>]
	 * : Base URL for cloud media providers.
	 *
	 * [--dry-run=<bool>]
	 * : If true, simulate import without creating/updating posts. Default: false.
	 *
	 * [--format=<format>]
	 * : Output format: table|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ppwp import-direct \
	 *       --input=xml \
	 *       --file-path=/full/path/to/export.xml \
	 *       --media-path=/full/path/to/media
	 *
	 *     wp ppwp import-direct \
	 *       --input=dbf \
	 *       --file-path=/full/path/to/ARCHIVES.DBF \
	 *       --media-path=/full/path/to/media \
	 *       --increment=50
	 */
	public static function cli_import_direct( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		// Validate and prepare input type
		$input_type = isset( $assoc_args['input'] ) ? sanitize_key( (string) $assoc_args['input'] ) : 'xml';
		if ( ! in_array( $input_type, array( 'xml', 'dbf' ), true ) ) {
			\WP_CLI::error( 'Invalid input type. Use "xml" or "dbf".' );
			return;
		}

		// Validate file path
		$file_path = isset( $assoc_args['file-path'] ) ? (string) $assoc_args['file-path'] : '';
		if ( '' === trim( $file_path ) ) {
			\WP_CLI::error( 'Missing required --file-path argument.' );
			return;
		}

		if ( ! is_readable( $file_path ) ) {
			\WP_CLI::error( 'File path is not readable: ' . $file_path );
			return;
		}

		// Validate media path
		$media_path = isset( $assoc_args['media-path'] ) ? (string) $assoc_args['media-path'] : '';
		if ( '' === trim( $media_path ) ) {
			\WP_CLI::error( 'Missing required --media-path argument.' );
			return;
		}

		if ( ! is_dir( $media_path ) ) {
			\WP_CLI::error( 'Media path is not a valid directory: ' . $media_path );
			return;
		}

		// Parse optional parameters
		$increment = isset( $assoc_args['increment'] ) ? absint( $assoc_args['increment'] ) : 100;
		$increment = max( 1, min( 200, $increment ) );

		$media_provider = isset( $assoc_args['media-provider'] ) ? sanitize_key( (string) $assoc_args['media-provider'] ) : 'wp_media_library';
		$valid_providers = array( 'wp_media_library', 'aws_s3', 'google_cloud_storage', 'google_drive' );
		if ( ! in_array( $media_provider, $valid_providers, true ) ) {
			\WP_CLI::error( 'Invalid media provider. Must be one of: ' . implode( ', ', $valid_providers ) );
			return;
		}

		$media_base_url = isset( $assoc_args['media-base-url'] ) ? (string) $assoc_args['media-base-url'] : '';

		$dry_run = isset( $assoc_args['dry-run'] ) ? filter_var( $assoc_args['dry-run'], FILTER_VALIDATE_BOOLEAN ) : false;
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( 'Invalid format. Use "table" or "json".' );
			return;
		}

		// Build settings override for SyncCoordinator
		$settings_override = array(
			'enabled' => ! $dry_run,
			'source' => $file_path,
			'source_provider' => $input_type,
			'increment' => $increment,
			'media_provider' => $media_provider,
			'media_source_directory' => $media_path,
			'media_remote_base_url' => $media_base_url,
		);

		// For dry-run, simulate instead of importing
		if ( $dry_run ) {
			self::simulate_import( $file_path, $input_type, $format );
			return;
		}

		// Start the import job
		$job_state = SyncCoordinator::start_job( 'cli_direct', true, $settings_override );
		if ( is_wp_error( $job_state ) ) {
			\WP_CLI::error( $job_state->get_error_message() );
			return;
		}

		\WP_CLI::log( 'Starting direct import...' );
		\WP_CLI::log( 'Total records to process: ' . $job_state['count'] );

		// Process all chunks synchronously
		$total_created = 0;
		$total_updated = 0;
		$total_failed = 0;
		$last_processed = 0;

		$progressbar = \WP_CLI\Utils\make_progress_bar(
			'Processing records',
			$job_state['count'],
			500
		);

		while ( true ) {
			$current_state = get_option( SyncCoordinator::OPTION_JOB_STATE, array() );
			if ( ! is_array( $current_state ) || ( $current_state['status'] ?? '' ) !== 'running' ) {
				break;
			}

			$run = $current_state['run'] ?? '';
			$run_data = Admin::get_run_data( $run );
			if ( empty( $run ) || ! is_array( $run_data ) ) {
				\WP_CLI::warning( 'Could not find run data. Import may have been interrupted.' );
				break;
			}

			try {
				$run_data['run'] = $run;
				$result = Admin::process_import_chunk_data(
					$run_data,
					absint( $current_state['last'] ?? 0 ),
					$increment
				);

				$created = 0;
				$updated = 0;
				$failed = 0;

				foreach ( $result['results'] as $record_result ) {
					if ( 'created' === $record_result['status'] ) {
						$created++;
					} elseif ( 'updated' === $record_result['status'] ) {
						$updated++;
					} else {
						$failed++;
					}
				}

				$total_created += $created;
				$total_updated += $updated;
				$total_failed += $failed;

				$records_processed = $result['current'] - $last_processed;
				$progressbar->tick( $records_processed );
				$last_processed = $result['current'];

				// Update state
				$current_state['last'] = $result['current'];
				$current_state['counts'] = array(
					'created' => $total_created,
					'updated' => $total_updated,
					'failed' => $total_failed,
				);

				if ( $current_state['last'] >= $current_state['count'] ) {
					SyncCoordinator::finish_job( $current_state, 'completed' );
					break;
				}

				update_option( SyncCoordinator::OPTION_JOB_STATE, $current_state, false );
			} catch ( \Throwable $throwable ) {
				$current_state['last_error'] = $throwable->getMessage();
				SyncCoordinator::finish_job( $current_state, 'failed' );
				\WP_CLI::warning( 'Error during import: ' . $throwable->getMessage() );
				break;
			}
		}

		$progressbar->finish();

		// Get final state
		$final_state = get_option( SyncCoordinator::OPTION_JOB_STATE, array() );

		$summary = array(
			array(
				'metric' => 'Created',
				'count' => (string) ( $final_state['counts']['created'] ?? $total_created ),
			),
			array(
				'metric' => 'Updated',
				'count' => (string) ( $final_state['counts']['updated'] ?? $total_updated ),
			),
			array(
				'metric' => 'Failed',
				'count' => (string) ( $final_state['counts']['failed'] ?? $total_failed ),
			),
			array(
				'metric' => 'Total',
				'count' => (string) ( ( $final_state['counts']['created'] ?? 0 ) + ( $final_state['counts']['updated'] ?? 0 ) ),
			),
		);

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $summary, array( 'metric', 'count' ) );
		}

		if ( ! empty( $final_state['last_error'] ) ) {
			\WP_CLI::warning( 'Final error: ' . $final_state['last_error'] );
		}

		if ( 'completed' === ( $final_state['status'] ?? '' ) ) {
			\WP_CLI::success( 'Import completed successfully!' );
		} else {
			\WP_CLI::error( 'Import did not complete. Status: ' . ( $final_state['status'] ?? 'unknown' ) );
		}
	}

	/**
	 * Simulate import without creating posts.
	 *
	 * @param string $file_path Path to XML or DBF file.
	 * @param string $input_type 'xml' or 'dbf'.
	 * @param string $format Output format ('table' or 'json').
	 */
	private static function simulate_import( string $file_path, string $input_type, string $format ): void {
		// Use the existing ImportSimulator logic
		$source = $file_path;
		$media_provider = 'wp_media_library';
		$media_source_directory = '';

		$settings = array(
			'import_media' => true,
			'media_provider' => $media_provider,
			'media_source_directory' => $media_source_directory,
		);

		if ( 'dbf' === $input_type ) {
			// For DBF, use the DBF simulator if available
			if ( class_exists( '\\PastPerfect\\Archive\\DbfSourceProvider' ) ) {
				\WP_CLI::line( 'DBF simulation not yet implemented in CLI.' );
				return;
			}
		}

		// Use ImportSimulator for XML
		$result = ImportSimulator::simulate_import( $source, $settings );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		$summary = array(
			array(
				'metric' => 'Would Create',
				'count' => (string) ( $result['totals']['would_create'] ?? 0 ),
			),
			array(
				'metric' => 'Would Update',
				'count' => (string) ( $result['totals']['would_update'] ?? 0 ),
			),
			array(
				'metric' => 'Media References',
				'count' => (string) ( $result['media']['total_references'] ?? 0 ),
			),
			array(
				'metric' => 'Resolvable Media',
				'count' => (string) ( $result['media']['resolvable_references'] ?? 0 ),
			),
		);

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $summary, array( 'metric', 'count' ) );
		}

		\WP_CLI::success( 'Simulation completed (no posts created).' );
	}
}
