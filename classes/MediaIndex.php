<?php

namespace PastPerfect\Archive;

/**
 * Persistent media file index for fast lookup against large source trees.
 */
class MediaIndex {
	private const TABLE_SUFFIX = 'ppwp_media_index';
	private const REFRESH_LOCK_KEY = 'ppwp_media_index_refresh_lock';
	private const REFRESH_LOCK_TTL = 45 * MINUTE_IN_SECONDS;

	/**
	 * Register CLI commands.
	 */
	public static function bootstrap(): void {
		if ( defined( 'WP_CLI' ) && true === constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'ppwp media-index', array( __CLASS__, 'cli_media_index' ) );
			\WP_CLI::add_command( 'ppwp media-import-all', array( __CLASS__, 'cli_media_import_all' ) );
			\WP_CLI::add_command( 'ppwp draft-missing-media', array( __CLASS__, 'cli_draft_missing_media' ) );
			\WP_CLI::add_command( 'ppwp script draft-missing-media', array( __CLASS__, 'cli_draft_missing_media' ) );
		}
	}

	/**
	 * Set posts without associated media to draft.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<post-type>]
	 * : Post type to process. Default: archive_item.
	 *
	 * [--statuses=<statuses>]
	 * : Comma-separated statuses to scan. Default: publish,pending,future,private.
	 *
	 * [--dry-run=<bool>]
	 * : Report what would change without updating posts. Default: false.
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 */
	public static function cli_draft_missing_media( array $args, array $assoc_args ): void {
		unset( $args );

		$post_type = isset( $assoc_args['post-type'] ) ? sanitize_key( (string) $assoc_args['post-type'] ) : 'archive_item';
		$statuses_raw = isset( $assoc_args['statuses'] ) ? (string) $assoc_args['statuses'] : 'publish,pending,future,private';
		$statuses = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $statuses_raw ) ) ) ) );
		$dry_run = isset( $assoc_args['dry-run'] ) ? filter_var( $assoc_args['dry-run'], FILTER_VALIDATE_BOOLEAN ) : false;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( '' === $post_type ) {
			\WP_CLI::error( 'Invalid post type.' );
			return;
		}

		if ( empty( $statuses ) ) {
			\WP_CLI::error( 'At least one status is required in --statuses.' );
			return;
		}

		$post_ids = get_posts(
			array(
				'post_type' => $post_type,
				'post_status' => $statuses,
				'posts_per_page' => -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
			)
		);

		$scanned = is_array( $post_ids ) ? count( $post_ids ) : 0;
		$updated = 0;
		$unchanged = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}

			if ( self::post_has_associated_media( $post_id ) ) {
				$unchanged++;
				continue;
			}

			if ( ! $dry_run ) {
				wp_update_post(
					array(
						'ID' => $post_id,
						'post_status' => 'draft',
					)
				);
			}

			$updated++;
		}

		$result = array(
			array(
				'post_type' => $post_type,
				'statuses' => implode( ',', $statuses ),
				'scanned' => $scanned,
				'updated_to_draft' => $updated,
				'unchanged_with_media' => $unchanged,
				'dry_run' => $dry_run ? 'true' : 'false',
			)
		);

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $result[0], JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $result, array_keys( $result[0] ) );
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run complete.' );
			return;
		}

		\WP_CLI::success( 'Posts without associated media were moved to draft.' );
	}

	/**
	 * Determine whether a post has associated media.
	 */
	private static function post_has_associated_media( int $post_id ): bool {
		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id > 0 ) {
			return true;
		}

		$attachments = get_posts(
			array(
				'post_type' => 'attachment',
				'post_parent' => $post_id,
				'posts_per_page' => 1,
				'fields' => 'ids',
				'post_status' => 'inherit',
			)
		);

		return ! empty( $attachments );
	}

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		self::create_table();
	}

	/**
	 * Refresh index using current plugin settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function refresh_from_settings(): array {
		if ( self::is_refresh_locked() ) {
			return array( 'error' => 'Media index refresh is already running.' );
		}

		$settings = SyncCoordinator::get_settings();
		$source = isset( $settings['media_source_directory'] ) ? (string) $settings['media_source_directory'] : '';

		self::lock_refresh();
		try {
			return self::index_source(
				$source,
				array(
					'rebuild' => false,
					'prune' => true,
					'hash' => false,
				)
			);
		} finally {
			self::unlock_refresh();
		}
	}

	/**
	 * WP-CLI entrypoint for indexing media.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<path>]
	 * : Absolute source directory. Defaults to configured sync media source directory.
	 *
	 * [--rebuild=<bool>]
	 * : Rebuild index from scratch. Default: false.
	 *
	 * [--prune=<bool>]
	 * : Remove entries for files no longer present on disk. Default: true.
	 *
	 * [--hash=<bool>]
	 * : Calculate SHA1 hashes while indexing (slower). Default: false.
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 */
	public static function cli_media_index( array $args, array $assoc_args ): void {
		unset( $args );

		$settings = SyncCoordinator::get_settings();
		$source = isset( $assoc_args['source'] ) ? (string) $assoc_args['source'] : (string) ( $settings['media_source_directory'] ?? '' );

		$result = self::index_source(
			$source,
			array(
				'rebuild' => isset( $assoc_args['rebuild'] ) ? filter_var( $assoc_args['rebuild'], FILTER_VALIDATE_BOOLEAN ) : false,
				'prune' => isset( $assoc_args['prune'] ) ? filter_var( $assoc_args['prune'], FILTER_VALIDATE_BOOLEAN ) : true,
				'hash' => isset( $assoc_args['hash'] ) ? filter_var( $assoc_args['hash'], FILTER_VALIDATE_BOOLEAN ) : false,
			)
		);

		if ( ! empty( $result['error'] ) ) {
			\WP_CLI::error( (string) $result['error'] );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$summary = array(
			array(
				'source' => $result['source'],
				'roots' => count( $result['roots'] ),
				'scanned' => $result['scanned'],
				'indexed_new' => $result['indexed_new'],
				'indexed_updated' => $result['indexed_updated'],
				'skipped_unchanged' => $result['skipped_unchanged'],
				'pruned_missing' => $result['pruned_missing'],
				'index_total' => $result['index_total'],
				'duration_seconds' => $result['duration_seconds'],
			)
		);

		\WP_CLI\Utils\format_items( 'table', $summary, array_keys( $summary[0] ) );
		\WP_CLI::success( 'Media index updated.' );
	}

	/**
	 * Import all indexed media files into the Media Library.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<path>]
	 * : Absolute source directory. Defaults to configured sync media source directory.
	 *
	 * [--limit=<number>]
	 * : Maximum number of files to process in this run. Default: 0 (no limit).
	 *
	 * [--dry-run=<bool>]
	 * : Report what would be imported without writing attachments. Default: false.
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 */
	public static function cli_media_import_all( array $args, array $assoc_args ): void {
		unset( $args );

		$settings = SyncCoordinator::get_settings();
		$source = isset( $assoc_args['source'] ) ? (string) $assoc_args['source'] : (string) ( $settings['media_source_directory'] ?? '' );
		$limit = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$dry_run = isset( $assoc_args['dry-run'] ) ? filter_var( $assoc_args['dry-run'], FILTER_VALIDATE_BOOLEAN ) : false;

		$result = self::import_all_indexed_media(
			$source,
			array(
				'limit' => $limit,
				'dry_run' => $dry_run,
			)
		);

		if ( ! empty( $result['error'] ) ) {
			\WP_CLI::error( (string) $result['error'] );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$summary = array(
			array(
				'source' => $result['source'],
				'roots' => count( $result['roots'] ),
				'total_indexed' => $result['total_indexed'],
				'processed' => $result['processed'],
				'already_imported' => $result['already_imported'],
				'would_import' => $result['would_import'],
				'imported' => $result['imported'],
				'missing_on_disk' => $result['missing_on_disk'],
				'failed' => $result['failed'],
				'duration_seconds' => $result['duration_seconds'],
			)
		);

		\WP_CLI\Utils\format_items( 'table', $summary, array_keys( $summary[0] ) );

		if ( ! empty( $result['error_samples'] ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Error samples:' );
			foreach ( $result['error_samples'] as $sample ) {
				\WP_CLI::line( ' - ' . $sample );
			}
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run complete.' );
			return;
		}

		\WP_CLI::success( 'Bulk media import complete.' );
	}

	/**
	 * Build or refresh index for media files.
	 *
	 * @param array<string,mixed> $args Index options.
	 * @return array<string,mixed>
	 */
	public static function index_source( string $source_directory, array $args = array() ): array {
		$started = microtime( true );
		self::create_table();

		$source_directory = self::normalize_source_directory( $source_directory );
		$roots = self::get_media_search_roots( $source_directory );
		if ( empty( $roots ) ) {
			return array( 'error' => 'Could not resolve a readable media source directory.' );
		}

		$rebuild = ! empty( $args['rebuild'] );
		$prune = ! array_key_exists( 'prune', $args ) || ! empty( $args['prune'] );
		$with_hash = ! empty( $args['hash'] );

		global $wpdb;
		$table = self::get_table_name();

		$scanned = 0;
		$indexed_new = 0;
		$indexed_updated = 0;
		$skipped_unchanged = 0;
		$pruned_missing = 0;

		foreach ( $roots as $root ) {
			$root = wp_normalize_path( untrailingslashit( $root ) );

			if ( $rebuild ) {
				$wpdb->delete( $table, array( 'source_root' => $root ), array( '%s' ) );
			}

			$existing = self::get_existing_index_map_for_root( $root );
			$seen = array();

			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
				);

				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}

					$absolute_path = wp_normalize_path( $file->getPathname() );
					$basename = $file->getBasename();
					if ( ! self::is_probable_media_reference( $basename ) ) {
						continue;
					}

					$relative_path = ltrim( (string) substr( $absolute_path, strlen( trailingslashit( $root ) ) ), '/' );
					if ( '' === $relative_path ) {
						continue;
					}

					$scanned++;
					$seen[ $relative_path ] = true;

					$file_mtime = (int) $file->getMTime();
					$file_size = (int) $file->getSize();
					$file_hash = '';
					if ( $with_hash ) {
						$hash = @ sha1_file( $absolute_path );
						$file_hash = is_string( $hash ) ? $hash : '';
					}

					if ( isset( $existing[ $relative_path ] ) ) {
						$prior = $existing[ $relative_path ];
						$same = (int) $prior['file_mtime'] === $file_mtime
							&& (int) $prior['file_size'] === $file_size;

						if ( $same && ! $with_hash ) {
							$skipped_unchanged++;
							continue;
						}

						if ( $same && $with_hash && (string) $prior['file_hash'] === $file_hash ) {
							$skipped_unchanged++;
							continue;
						}
					}

					$data = array(
						'source_root' => $root,
						'relative_path' => $relative_path,
						'basename' => $basename,
						'identifier_stem' => self::stem_from_basename( $basename ),
						'extension' => strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) ),
						'file_mtime' => $file_mtime,
						'file_size' => $file_size,
						'file_hash' => $file_hash,
						'updated_at' => current_time( 'mysql', true ),
					);

					$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
					$written = $wpdb->replace( $table, $data, $formats );
					if ( false !== $written ) {
						if ( isset( $existing[ $relative_path ] ) ) {
							$indexed_updated++;
						} else {
							$indexed_new++;
						}
					}
				}
			} catch ( \Throwable $unused ) {
				continue;
			}

			if ( $prune && ! empty( $existing ) ) {
				$missing = array_diff_key( $existing, $seen );
				if ( ! empty( $missing ) ) {
					foreach ( array_keys( $missing ) as $relative_path ) {
						$deleted = $wpdb->delete(
							$table,
							array(
								'source_root' => $root,
								'relative_path' => (string) $relative_path,
							),
							array( '%s', '%s' )
						);

						if ( false !== $deleted ) {
							$pruned_missing += (int) $deleted;
						}
					}
				}
			}
		}

		$index_total = self::count_indexed_files_for_source( $source_directory );

		return array(
			'source' => $source_directory,
			'roots' => $roots,
			'scanned' => $scanned,
			'indexed_new' => $indexed_new,
			'indexed_updated' => $indexed_updated,
			'skipped_unchanged' => $skipped_unchanged,
			'pruned_missing' => $pruned_missing,
			'index_total' => $index_total,
			'duration_seconds' => round( microtime( true ) - $started, 3 ),
		);
	}

	/**
	 * Import indexed media rows for a source into the Media Library.
	 *
	 * @param array<string,mixed> $args Import options.
	 * @return array<string,mixed>
	 */
	public static function import_all_indexed_media( string $source_directory, array $args = array() ): array {
		$started = microtime( true );
		self::create_table();

		$source_directory = self::normalize_source_directory( $source_directory );
		$roots = self::get_media_search_roots( $source_directory );
		if ( empty( $roots ) ) {
			return array( 'error' => 'Could not resolve a readable media source directory.' );
		}

		$limit = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
		$dry_run = ! empty( $args['dry_run'] );

		$total_indexed = self::count_indexed_files_for_source( $source_directory );
		$rows = self::get_index_rows_for_roots( $roots, $limit );

		$processed = 0;
		$already_imported = 0;
		$would_import = 0;
		$imported = 0;
		$missing_on_disk = 0;
		$failed = 0;
		$error_samples = array();

		foreach ( $rows as $row ) {
			$root = isset( $row['source_root'] ) ? (string) $row['source_root'] : '';
			$relative = isset( $row['relative_path'] ) ? (string) $row['relative_path'] : '';
			if ( '' === $root || '' === $relative ) {
				continue;
			}

			$source_file = trailingslashit( wp_normalize_path( untrailingslashit( $root ) ) ) . ltrim( $relative, '/' );
			$processed++;

			if ( self::find_attachment_id_by_source_file( $source_file ) > 0 ) {
				$already_imported++;
				continue;
			}

			if ( ! is_readable( $source_file ) ) {
				$missing_on_disk++;
				continue;
			}

			if ( $dry_run ) {
				$would_import++;
				continue;
			}

			$attachment_id = self::import_source_file_to_media_library( $source_file, 0 );
			if ( is_wp_error( $attachment_id ) ) {
				$failed++;
				if ( count( $error_samples ) < 10 ) {
					$error_samples[] = $source_file . ' => ' . $attachment_id->get_error_message();
				}
				continue;
			}

			$imported++;
		}

		return array(
			'source' => $source_directory,
			'roots' => $roots,
			'total_indexed' => $total_indexed,
			'processed' => $processed,
			'already_imported' => $already_imported,
			'would_import' => $would_import,
			'imported' => $imported,
			'missing_on_disk' => $missing_on_disk,
			'failed' => $failed,
			'error_samples' => $error_samples,
			'dry_run' => $dry_run,
			'duration_seconds' => round( microtime( true ) - $started, 3 ),
		);
	}

	/**
	 * Whether index rows exist for a source directory.
	 */
	public static function has_index_for_source( string $source_directory ): bool {
		return self::count_indexed_files_for_source( $source_directory ) > 0;
	}

	/**
	 * Return index status for a source directory.
	 *
	 * @return array{source:string,roots:array<int,string>,indexed_files:int,last_updated_gmt:string,last_updated_local:string}
	 */
	public static function get_source_status( string $source_directory ): array {
		self::create_table();
		$source = self::normalize_source_directory( $source_directory );
		$roots = self::get_media_search_roots( $source );

		$indexed_files = 0;
		$last_updated_gmt = '';
		$last_updated_local = '';

		if ( ! empty( $roots ) ) {
			global $wpdb;
			$table = self::get_table_name();

			foreach ( $roots as $root ) {
				$root = wp_normalize_path( untrailingslashit( $root ) );
				$indexed_files += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_root = %s", $root ) );
				$max_updated = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(updated_at) FROM {$table} WHERE source_root = %s", $root ) );

				if ( is_string( $max_updated ) && '' !== $max_updated ) {
					if ( '' === $last_updated_gmt || strtotime( $max_updated ) > strtotime( $last_updated_gmt ) ) {
						$last_updated_gmt = $max_updated;
					}
				}
			}

			if ( '' !== $last_updated_gmt ) {
				$timestamp = strtotime( $last_updated_gmt . ' UTC' );
				if ( false !== $timestamp ) {
					$last_updated_local = wp_date( 'Y-m-d H:i:s', $timestamp );
				}
			}
		}

		return array(
			'source' => $source,
			'roots' => $roots,
			'indexed_files' => $indexed_files,
			'last_updated_gmt' => $last_updated_gmt,
			'last_updated_local' => $last_updated_local,
		);
	}

	/**
	 * Resolve one media reference from index.
	 */
	public static function find_match_for_reference( string $reference, string $source_directory ): string {
		self::create_table();

		$reference = trim( str_replace( '\\', '/', $reference ) );
		if ( '' === $reference ) {
			return '';
		}

		if ( is_readable( $reference ) ) {
			return (string) $reference;
		}

		$roots = self::get_media_search_roots( $source_directory );
		if ( empty( $roots ) ) {
			return '';
		}

		$reference_candidates = array_values( array_unique( self::build_reference_candidates( $reference ) ) );

		foreach ( $roots as $root ) {
			$root = wp_normalize_path( untrailingslashit( $root ) );
			foreach ( $reference_candidates as $candidate ) {
				$relative_match = self::find_relative_path_match( $root, $candidate );
				if ( '' !== $relative_match ) {
					return trailingslashit( $root ) . $relative_match;
				}
			}
		}

		$basename = wp_basename( $reference );
		if ( '' === $basename ) {
			return '';
		}

		$matches = array();
		foreach ( $roots as $root ) {
			$root = wp_normalize_path( untrailingslashit( $root ) );
			$relative = self::find_basename_match( $root, $basename );
			if ( '' !== $relative ) {
				$matches[] = trailingslashit( $root ) . $relative;
			}
		}

		if ( empty( $matches ) ) {
			return '';
		}

		natsort( $matches );
		$first = reset( $matches );
		return is_string( $first ) ? $first : '';
	}

	/**
	 * Resolve identifier-derived media matches from index.
	 *
	 * @return array<int,string>
	 */
	public static function find_matches_for_identifier( string $identifier, string $source_directory ): array {
		self::create_table();

		$stem = self::normalize_identifier_for_media_filename( $identifier );
		$roots = self::get_media_search_roots( $source_directory );

		if ( '' === $stem || empty( $roots ) ) {
			return array();
		}

		$matches = array();
		foreach ( $roots as $root ) {
			$root = wp_normalize_path( untrailingslashit( $root ) );
			foreach ( self::find_identifier_rows( $root, $stem ) as $relative_path ) {
				$matches[] = trailingslashit( $root ) . $relative_path;
			}
		}

		natsort( $matches );
		return array_values( array_unique( $matches ) );
	}

	/**
	 * Return canonical media roots for a source directory.
	 *
	 * @return array<int,string>
	 */
	public static function get_media_search_roots( string $source_directory ): array {
		$source_directory = self::normalize_source_directory( $source_directory );

		if ( '' === $source_directory || ! is_dir( $source_directory ) ) {
			return array();
		}

		$roots = array();
		$images = trailingslashit( $source_directory ) . 'Images';
		$multimedia = trailingslashit( $source_directory ) . 'Multimedia';

		if ( is_dir( $images ) || is_dir( $multimedia ) ) {
			if ( is_dir( $images ) ) {
				$roots[] = wp_normalize_path( $images );
			}

			if ( is_dir( $multimedia ) ) {
				$roots[] = wp_normalize_path( $multimedia );
			}
		} else {
			$roots[] = wp_normalize_path( $source_directory );
		}

		return array_values( array_unique( $roots ) );
	}

	private static function count_indexed_files_for_source( string $source_directory ): int {
		self::create_table();
		$roots = self::get_media_search_roots( $source_directory );
		if ( empty( $roots ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::get_table_name();
		$total = 0;

		foreach ( $roots as $root ) {
			$root = wp_normalize_path( untrailingslashit( $root ) );
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_root = %s", $root ) );
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * @return array<string,array<string,string|int>>
	 */
	private static function get_existing_index_map_for_root( string $root ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT relative_path, file_mtime, file_size, file_hash FROM {$table} WHERE source_root = %s",
				$root
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$relative = (string) ( $row['relative_path'] ?? '' );
			if ( '' === $relative ) {
				continue;
			}

			$map[ $relative ] = array(
				'file_mtime' => (int) ( $row['file_mtime'] ?? 0 ),
				'file_size' => (int) ( $row['file_size'] ?? 0 ),
				'file_hash' => (string) ( $row['file_hash'] ?? '' ),
			);
		}

		return $map;
	}

	private static function find_relative_path_match( string $root, string $candidate ): string {
		global $wpdb;
		$table = self::get_table_name();

		$match = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT relative_path FROM {$table} WHERE source_root = %s AND relative_path = %s LIMIT 1",
				$root,
				$candidate
			)
		);

		return is_string( $match ) ? $match : '';
	}

	private static function find_basename_match( string $root, string $basename ): string {
		global $wpdb;
		$table = self::get_table_name();

		$match = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT relative_path FROM {$table} WHERE source_root = %s AND basename = %s ORDER BY relative_path ASC LIMIT 1",
				$root,
				$basename
			)
		);

		return is_string( $match ) ? $match : '';
	}

	/**
	 * @return array<int,string>
	 */
	private static function find_identifier_rows( string $root, string $stem ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT relative_path FROM {$table} WHERE source_root = %s AND identifier_stem = %s ORDER BY relative_path ASC",
				$root,
				$stem
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $rows ) ) );
	}

	/**
	 * @return array<int,string>
	 */
	private static function build_reference_candidates( string $reference ): array {
		$reference = ltrim( $reference, '/' );
		$candidates = array( $reference );

		foreach ( array( 'images/', 'multimedia/' ) as $prefix ) {
			if ( 0 === stripos( $reference, $prefix ) ) {
				$candidates[] = ltrim( (string) substr( $reference, strlen( $prefix ) ), '/' );
			}
		}

		return $candidates;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private static function get_index_rows_for_roots( array $roots, int $limit = 0 ): array {
		$roots = array_values( array_filter( array_map( 'strval', $roots ) ) );
		if ( empty( $roots ) ) {
			return array();
		}

		global $wpdb;
		$table = self::get_table_name();

		$root_clause = implode( ' OR ', array_fill( 0, count( $roots ), 'source_root = %s' ) );
		$sql = "SELECT source_root, relative_path FROM {$table} WHERE ({$root_clause}) ORDER BY source_root ASC, relative_path ASC";
		$values = $roots;

		if ( $limit > 0 ) {
			$sql .= ' LIMIT %d';
			$values[] = $limit;
		}

		$prepared = $wpdb->prepare( $sql, $values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return array();
		}

		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $rows;
	}

	private static function find_attachment_id_by_source_file( string $source_file ): int {
		$existing = get_posts(
			array(
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'fields' => 'ids',
				'posts_per_page' => 1,
				'meta_query' => array(
					array(
						'key' => '_ppwp_source_file',
						'value' => $source_file,
					),
				),
			)
		);

		if ( empty( $existing ) ) {
			return 0;
		}

		return (int) reset( $existing );
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function import_source_file_to_media_library( string $source_file, int $post_id = 0 ) {
		$existing_id = self::find_attachment_id_by_source_file( $source_file );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		$contents = file_get_contents( $source_file );
		if ( false === $contents ) {
			return new \WP_Error( 'ppwp_media_read_failed', __( 'Could not read media file from source directory.', 'pastperfect-wp' ) );
		}

		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir_no_date_subdirs' ) );
		$uploaded = wp_upload_bits( wp_basename( $source_file ), null, $contents );
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir_no_date_subdirs' ) );

		if ( ! empty( $uploaded['error'] ) ) {
			return new \WP_Error( 'ppwp_media_upload_failed', (string) $uploaded['error'] );
		}

		$wp_filetype = wp_check_filetype( $uploaded['file'], null );
		$attachment_id = wp_insert_attachment(
			array(
				'post_title' => preg_replace( '/\.[^.]+$/', '', wp_basename( $uploaded['file'] ) ),
				'post_mime_type' => $wp_filetype['type'] ?? '',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
			),
			$uploaded['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta( $attachment_id, '_ppwp_source_file', $source_file );

		return $attachment_id;
	}

	/**
	 * Force Media Library uploads into the base uploads folder (no date subdirs).
	 */
	public static function filter_upload_dir_no_date_subdirs( array $uploads ): array {
		$uploads['subdir'] = '';
		$uploads['path'] = $uploads['basedir'];
		$uploads['url'] = $uploads['baseurl'];

		return $uploads;
	}

	private static function create_table(): void {
		global $wpdb;
		$table = self::get_table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_root varchar(1024) NOT NULL,
			relative_path varchar(1024) NOT NULL,
			basename varchar(255) NOT NULL,
			identifier_stem varchar(191) NOT NULL,
			extension varchar(20) NOT NULL,
			file_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			file_hash varchar(40) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_relative (source_root(191), relative_path(191)),
			KEY source_basename (source_root(191), basename),
			KEY source_identifier (source_root(191), identifier_stem)
		) {$charset};";

		dbDelta( $sql );
	}

	private static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	private static function stem_from_basename( string $basename ): string {
		$name = (string) pathinfo( $basename, PATHINFO_FILENAME );
		if ( preg_match( '/^([A-Za-z0-9]+)(?:-\d+)?$/', $name, $matches ) ) {
			return strtolower( (string) $matches[1] );
		}

		return '';
	}

	private static function normalize_identifier_for_media_filename( string $identifier ): string {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return '';
		}

		$normalized = preg_replace( '/[^A-Za-z0-9]+/', '', $identifier );
		if ( ! is_string( $normalized ) ) {
			return '';
		}

		return strtolower( $normalized );
	}

	private static function normalize_source_directory( string $source_directory ): string {
		$source_directory = trim( str_replace( '\\', '/', $source_directory ) );
		if ( '' === $source_directory ) {
			$source_directory = self::get_default_media_source_directory();
		}

		if ( '' === $source_directory ) {
			return '';
		}

		return wp_normalize_path( untrailingslashit( $source_directory ) );
	}

	private static function get_default_media_source_directory(): string {
		$uploads = wp_get_upload_dir();
		$default_path = trailingslashit( (string) $uploads['basedir'] ) . 'pp5_share';

		if ( is_dir( $default_path ) ) {
			return $default_path;
		}

		return '';
	}

	private static function is_probable_media_reference( string $reference ): bool {
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return false;
		}

		$path = wp_parse_url( $reference, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = $reference;
		}

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$media_exts = array(
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp', 'svg',
			'mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg',
			'mp4', 'mov', 'm4v', 'avi', 'mkv', 'webm',
			'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt',
		);

		return in_array( $ext, $media_exts, true );
	}

	private static function lock_refresh(): void {
		set_transient( self::REFRESH_LOCK_KEY, 1, self::REFRESH_LOCK_TTL );
	}

	private static function unlock_refresh(): void {
		delete_transient( self::REFRESH_LOCK_KEY );
	}

	private static function is_refresh_locked(): bool {
		return (bool) get_transient( self::REFRESH_LOCK_KEY );
	}
}