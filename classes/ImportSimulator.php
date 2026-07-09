<?php

namespace PastPerfect\Archive;

/**
 * Dry-run simulator for evaluating XML imports.
 */
class ImportSimulator {
	/**
	 * Bootstrap CLI command when WP-CLI is available.
	 */
	public static function bootstrap(): void {
		if ( defined( 'WP_CLI' ) && true === constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'ppwp import-simulate', array( __CLASS__, 'cli_import_simulate' ) );
		}
	}

	/**
	 * WP-CLI entrypoint.
	 *
	 * ## OPTIONS
	 *
	 * --xml=<path>
	 * : Absolute path to XML file.
	 *
	 * [--format=<format>]
	 * : Output format: table|json. Default: table.
	 *
	 * [--report=<path>]
	 * : Optional file path to write full JSON report.
	 *
	 * [--media-provider=<provider>]
	 * : wp_media_library|aws_s3|google_cloud_storage|google_drive.
	 *
	 * [--media-source-directory=<path>]
	 * : Local media source directory for file matching.
	 *
	 * [--media-remote-base-url=<url>]
	 * : Remote base URL for cloud URL mapping simulations.
	 *
	 * [--import-media=<bool>]
	 * : Whether to evaluate media matching. Default true.
	 */
	public static function cli_import_simulate( array $args, array $assoc_args ): void {
		unset( $args );

		$xml = isset( $assoc_args['xml'] ) ? (string) $assoc_args['xml'] : '';
		if ( '' === $xml ) {
			\WP_CLI::error( 'Missing required --xml argument.' );
			return;
		}

		$result = self::simulate(
			$xml,
			array(
				'media_provider' => isset( $assoc_args['media-provider'] ) ? (string) $assoc_args['media-provider'] : null,
				'media_source_directory' => isset( $assoc_args['media-source-directory'] ) ? (string) $assoc_args['media-source-directory'] : null,
				'media_remote_base_url' => isset( $assoc_args['media-remote-base-url'] ) ? (string) $assoc_args['media-remote-base-url'] : null,
				'import_media' => isset( $assoc_args['import-media'] ) ? filter_var( $assoc_args['import-media'], FILTER_VALIDATE_BOOLEAN ) : null,
			)
		);

		if ( ! empty( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$summary = array(
			array(
				'xml_path' => $result['xml_path'],
				'record_element' => $result['record_element'],
				'total_records' => $result['totals']['records'],
				'parse_errors' => $result['totals']['parse_errors'],
				'missing_identifier' => $result['totals']['missing_identifier'],
				'duplicate_identifiers' => $result['totals']['duplicate_identifiers'],
				'would_create' => $result['totals']['would_create'],
				'would_update' => $result['totals']['would_update'],
				'media_refs' => $result['media']['total_references'],
				'media_resolvable' => $result['media']['resolvable_references'],
				'media_missing' => $result['media']['missing_references'],
			)
		);

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $summary, array_keys( $summary[0] ) );
			if ( ! empty( $result['media']['missing_reference_samples'] ) ) {
				\WP_CLI::line( '' );
				\WP_CLI::line( 'Missing media samples:' );
				foreach ( $result['media']['missing_reference_samples'] as $sample ) {
					\WP_CLI::line( ' - ' . $sample );
				}
			}
		}

		if ( ! empty( $assoc_args['report'] ) ) {
			$report_path = (string) $assoc_args['report'];
			$dir = dirname( $report_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$written = file_put_contents( $report_path, wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			if ( false === $written ) {
				\WP_CLI::warning( 'Could not write report file to ' . $report_path );
			} else {
				\WP_CLI::success( 'Report written to ' . $report_path );
			}
		}
	}

	/**
	 * Execute a dry-run simulation and return diagnostics.
	 */
	public static function simulate( string $xml_path, array $options = array() ): array {
		$start = microtime( true );

		if ( ! is_readable( $xml_path ) ) {
			return array( 'error' => 'XML file is not readable: ' . $xml_path );
		}

		$sync_settings = SyncCoordinator::get_settings();
		$settings = array(
			'import_media' => array_key_exists( 'import_media', $options ) && null !== $options['import_media']
				? (bool) $options['import_media']
				: ! empty( $sync_settings['import_media'] ),
			'media_provider' => ! empty( $options['media_provider'] ) ? sanitize_key( (string) $options['media_provider'] ) : sanitize_key( (string) $sync_settings['media_provider'] ),
			'media_source_directory' => ! empty( $options['media_source_directory'] ) ? (string) $options['media_source_directory'] : (string) $sync_settings['media_source_directory'],
			'media_remote_base_url' => ! empty( $options['media_remote_base_url'] ) ? (string) $options['media_remote_base_url'] : (string) $sync_settings['media_remote_base_url'],
		);
		if ( '' === trim( (string) $settings['media_source_directory'] ) ) {
			$settings['media_source_directory'] = self::get_default_media_source_directory();
		}

		$xml_payload = file_get_contents( $xml_path );
		if ( false === $xml_payload ) {
			return array( 'error' => 'Could not read XML file: ' . $xml_path );
		}

		$xml_payload = self::sanitize_xml_payload( $xml_payload );

		$reader = new \XMLReader();
		libxml_use_internal_errors( true );
		if ( ! $reader->XML( $xml_payload ) ) {
			libxml_clear_errors();
			libxml_use_internal_errors( false );
			return array( 'error' => 'Could not open XML file: ' . $xml_path );
		}

		$record_element = self::find_record_element( $reader );
		if ( '' === $record_element ) {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( false );
			return array( 'error' => 'No supported record element found (expected record or dc-record).' );
		}

		$doc = new \DOMDocument();
		$record = new Record();
		$ids_seen = array();
		$field_counts = array();
		$subject_counts = array();
		$media_cache = array();
		$media_missing_samples = array();

		$totals = array(
			'records' => 0,
			'missing_identifier' => 0,
			'duplicate_identifiers' => 0,
			'would_create' => 0,
			'would_update' => 0,
			'parse_errors' => 0,
		);

		$media = array(
			'total_references' => 0,
			'resolvable_references' => 0,
			'missing_references' => 0,
			'missing_reference_samples' => array(),
		);

		while ( $reader->read() && $record_element !== $reader->name ) {
			// Move to first record node.
		}

		while ( $record_element === $reader->name ) {
			$totals['records']++;
			$expanded = @ $reader->expand();
			if ( ! $expanded ) {
				$totals['parse_errors']++;
				$reader->next( $record_element );
				continue;
			}

			$imported = $doc->importNode( $expanded, true );
			if ( ! $imported ) {
				$totals['parse_errors']++;
				$reader->next( $record_element );
				continue;
			}

			$node = simplexml_import_dom( $imported );
			if ( ! $node ) {
				$totals['parse_errors']++;
				$reader->next( $record_element );
				continue;
			}

			$parsed = self::parse_node( $node );
			$identifier = $parsed['identifier'];
			$atts = $parsed['atts'];

			foreach ( array_keys( $atts ) as $field ) {
				if ( ! isset( $field_counts[ $field ] ) ) {
					$field_counts[ $field ] = 0;
				}
				$field_counts[ $field ]++;
			}

			if ( isset( $atts['subject'] ) ) {
				foreach ( (array) $atts['subject'] as $subject ) {
					$key = trim( (string) $subject );
					if ( '' === $key ) {
						continue;
					}
					if ( ! isset( $subject_counts[ $key ] ) ) {
						$subject_counts[ $key ] = 0;
					}
					$subject_counts[ $key ]++;
				}
			}

			if ( '' === $identifier ) {
				$totals['missing_identifier']++;
			} else {
				if ( isset( $ids_seen[ $identifier ] ) ) {
					$totals['duplicate_identifiers']++;
				} else {
					$ids_seen[ $identifier ] = true;
				}

				$post_id = $record->get_post_id_by_identifier( $identifier );
				if ( $post_id ) {
					$totals['would_update']++;
				} else {
					$totals['would_create']++;
				}
			}

			if ( $settings['import_media'] ) {
				$references = self::extract_media_references( $atts['relation'] ?? array() );
				$references = array_values(
					array_unique(
						array_merge(
							$references,
							self::infer_media_references_from_identifier( $identifier, (string) $settings['media_source_directory'] )
						)
					)
				);

				foreach ( $references as $reference ) {
					$media['total_references']++;
					$cache_key = md5( $settings['media_provider'] . '|' . $settings['media_source_directory'] . '|' . $settings['media_remote_base_url'] . '|' . $reference );
					if ( ! array_key_exists( $cache_key, $media_cache ) ) {
						$media_cache[ $cache_key ] = self::simulate_media_reference_resolution( $reference, $settings );
					}

					if ( $media_cache[ $cache_key ] ) {
						$media['resolvable_references']++;
					} else {
						$media['missing_references']++;
						if ( count( $media_missing_samples ) < 25 ) {
							$media_missing_samples[] = $reference;
						}
					}
				}
			}

			$reader->next( $record_element );
		}

		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		arsort( $field_counts );
		arsort( $subject_counts );
		$media['missing_reference_samples'] = array_values( array_unique( $media_missing_samples ) );

		return array(
			'xml_path' => $xml_path,
			'record_element' => $record_element,
			'totals' => $totals,
			'media' => $media,
			'settings' => $settings,
			'field_usage' => $field_counts,
			'top_subjects' => array_slice( $subject_counts, 0, 20, true ),
			'duration_seconds' => round( microtime( true ) - $start, 3 ),
		);
	}

	private static function find_record_element( \XMLReader $reader ): string {
		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			if ( in_array( $reader->name, array( 'record', 'dc-record' ), true ) ) {
				return $reader->name;
			}
		}

		return '';
	}

	private static function sanitize_xml_payload( string $payload ): string {
		$payload = preg_replace( '/^\xEF\xBB\xBF/', '', $payload );
		if ( ! is_string( $payload ) ) {
			return '';
		}

		$payload = ltrim( $payload );
		return $payload;
	}

	/**
	 * @param \SimpleXMLElement $node Parsed node.
	 * @return array{identifier:string,atts:array<string,mixed>}
	 */
	private static function parse_node( \SimpleXMLElement $node ): array {
		$atts = array();
		$identifier = '';
		$singular = Record::get_singular_elements();

		foreach ( $node->children() as $field_name => $field_node ) {
			$field_name = (string) $field_name;
			$field_value = trim( (string) $field_node );

			if ( 'identifier' === $field_name && '' === $identifier ) {
				$identifier = $field_value;
			}

			if ( in_array( $field_name, $singular, true ) ) {
				$atts[ $field_name ] = $field_value;
			} else {
				$atts[ $field_name ][] = $field_value;
			}
		}

		return array(
			'identifier' => $identifier,
			'atts' => $atts,
		);
	}

	/**
	 * @param mixed $relation_values Relation values.
	 * @return array<int,string>
	 */
	private static function extract_media_references( $relation_values ): array {
		$relation_values = is_array( $relation_values ) ? $relation_values : array( $relation_values );
		$references = array();

		foreach ( $relation_values as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$parts = strpos( $value, ';' ) !== false ? array_map( 'trim', explode( ';', $value ) ) : array( $value );
			foreach ( $parts as $part ) {
				if ( self::is_probable_media_reference( $part ) ) {
					$references[] = $part;
				}
			}
		}

		return array_values( array_unique( $references ) );
	}

	/**
	 * Infer media references from PastPerfect identifier naming conventions.
	 *
	 * @return array<int,string>
	 */
	private static function infer_media_references_from_identifier( string $identifier, string $source_directory ): array {
		$indexed = MediaIndex::find_matches_for_identifier( $identifier, $source_directory );
		if ( ! empty( $indexed ) ) {
			return $indexed;
		}

		$stem = self::normalize_identifier_for_media_filename( $identifier );
		$roots = self::get_media_search_roots( $source_directory );

		if ( '' === $stem || empty( $roots ) ) {
			return array();
		}

		$matches = array();
		$pattern = '/^' . preg_quote( $stem, '/' ) . '(?:-\\d+)?\\.[A-Za-z0-9]+$/i';

		foreach ( $roots as $root ) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
				);

				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}

					$basename = $file->getBasename();
					if ( preg_match( $pattern, $basename ) && self::is_probable_media_reference( $basename ) ) {
						$matches[] = $file->getPathname();
					}
				}
			} catch ( \Throwable $unused ) {
				continue;
			}
		}

		natsort( $matches );
		return array_values( array_unique( $matches ) );
	}

	private static function normalize_identifier_for_media_filename( string $identifier ): string {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9]+/', '', $identifier ) ?? '';
	}

	private static function is_probable_media_reference( string $reference ): bool {
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

	private static function simulate_media_reference_resolution( string $reference, array $settings ): bool {
		if ( 'wp_media_library' === $settings['media_provider'] ) {
			$indexed_match = MediaIndex::find_match_for_reference( $reference, (string) $settings['media_source_directory'] );
			if ( '' !== $indexed_match ) {
				return true;
			}

			if ( is_readable( $reference ) ) {
				return true;
			}

			$roots = self::get_media_search_roots( (string) $settings['media_source_directory'] );
			if ( empty( $roots ) ) {
				return false;
			}

			foreach ( $roots as $root ) {
				$candidate = trailingslashit( $root ) . ltrim( str_replace( '\\', '/', $reference ), '/' );
				if ( is_readable( $candidate ) ) {
					return true;
				}

				$basename_candidate = trailingslashit( $root ) . wp_basename( $reference );
				if ( is_readable( $basename_candidate ) ) {
					return true;
				}
			}

			return false;
		}

		if ( wp_http_validate_url( $reference ) ) {
			return true;
		}

		$base_url = trim( (string) $settings['media_remote_base_url'] );
		return '' !== $base_url;
	}

	private static function get_default_media_source_directory(): string {
		$uploads = wp_get_upload_dir();
		$default_path = trailingslashit( (string) $uploads['basedir'] ) . 'pp5_share';

		if ( is_dir( $default_path ) ) {
			return $default_path;
		}

		return '';
	}

	/**
	 * @return array<int,string>
	 */
	private static function get_media_search_roots( string $source_directory ): array {
		$source_directory = trim( str_replace( '\\', '/', $source_directory ) );
		if ( '' === $source_directory ) {
			$source_directory = self::get_default_media_source_directory();
		}

		if ( '' === $source_directory || ! is_dir( $source_directory ) ) {
			return array();
		}

		$roots = array();
		$images = trailingslashit( $source_directory ) . 'Images';
		$multimedia = trailingslashit( $source_directory ) . 'Multimedia';

		if ( is_dir( $images ) || is_dir( $multimedia ) ) {
			if ( is_dir( $images ) ) {
				$roots[] = $images;
			}

			if ( is_dir( $multimedia ) ) {
				$roots[] = $multimedia;
			}
		} else {
			$roots[] = $source_directory;
		}

		return array_values( array_unique( $roots ) );
	}
}
