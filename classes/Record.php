<?php

namespace PastPerfect\Archive;

/**
 * Record object.
 *
 * @since 1.0.0
 */
class Record {
	/**
	 * DCMI Dublin Core Metadata Element Set (Core 15).
	 *
	 * @see https://www.dublincore.org/specifications/dublin-core/dces/
	 */
	protected static $dc_elements = array(
		'contributor',
		'coverage',
		'creator',
		'date',
		'description',
		'format',
		'identifier',
		'language',
		'publisher',
		'relation',
		'rights',
		'source',
		'subject',
		'title',
		'type',
	);

	protected static $singular_elements = array(
		'identifier',
		'title',
		'date',
		'description',
		'format',
		'language',
		'publisher',
		'rights',
		'source',
		'type',
		'coverage',
	);

	protected static $taxonomy_elements = array(
		'subject' => 'archive_subject',
	);

	protected $dc_metadata = array();

	protected $asset_base = 'https://s3.amazonaws.com/pastperfect.assets/';

	protected $post;

	protected $initial_post_status = 'publish';

	public function __construct( $post_id = null ) {
		if ( $post_id ) {
			$this->populate( $post_id );
		}
	}

	/**
	 * Set the initial post status for new records.
	 *
	 * @param string $status The post status (e.g., 'publish', 'draft').
	 */
	public function set_initial_post_status( string $status ): void {
		$this->initial_post_status = $status;
	}

	/**
	 * Check if this record has any media references.
	 *
	 * Returns true if relation metadata contains probable media references.
	 *
	 * @return bool
	 */
	public function has_media_references(): bool {
		$relation_values = $this->get_dc_metadata( 'relation', false );
		if ( empty( $relation_values ) ) {
			return false;
		}

		$relation_values = is_array( $relation_values ) ? $relation_values : array( $relation_values );
		foreach ( $relation_values as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$parts = strpos( $value, ';' ) !== false ? array_map( 'trim', explode( ';', $value ) ) : array( $value );
			foreach ( $parts as $part ) {
				if ( $this->is_probable_media_reference( $part ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Determine if a string is a probable media reference.
	 *
	 * @param string $reference The reference to check.
	 * @return bool
	 */
	private function is_probable_media_reference( string $reference ): bool {
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

	public function set_up_from_raw_atts( $atts ) {
		$dc_elements = self::get_dc_elements();
		foreach ( $atts as $att_type => $att ) {
			if ( in_array( $att_type, $dc_elements, true ) ) {
				$att = $this->sanitize_raw_attribute( $att_type, $att );

				// Skip empty fields.
				if ( empty( $att ) ) {
					continue;
				}

				$this->set_dc_metadata_value( $att_type, $att );
			}
		}

		return true;
	}

	/**
	 * Sanitize raw attributes from XML source file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $element Element name.
	 * @param mixed  $values  Values.
	 * @return mixed
	 */
	protected function sanitize_raw_attribute( $element, $values ) {
		switch ( $element ) {
			case 'subject' :
				$all_values = [];
				foreach ( (array) $values as $value ) {
					$_values    = explode( ';', $value );
					$all_values = array_merge( $_values, $all_values );
				}

				$values = array_map( 'trim', $all_values );
				$values = array_filter( $values );
			break;

			default :
				if ( is_array( $values ) ) {
					$values = array_filter( $values );
				} else {
					$values = trim( (string) $values );
				}
			break;
		}

		// Line breaks are encoded backwards. Wow.
		$values = $this->convert_line_breaks( $values );

		return $values;
	}

	protected function convert_line_breaks( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'convert_line_breaks' ), $value );
		} else {
			return str_replace( '/n', "\n", (string) $value );
		}
	}

	public function get_dc_metadata( $field, $single = true ) {
		if ( isset( $this->dc_metadata[ $field ] ) ) {
			$value = $this->dc_metadata[ $field ];
		} else {
			$value = $single ? '' : array();
		}

		if ( $single && is_array( $value ) ) {
			$value = reset( $value );
		}

		return $value;
	}

	public function set_dc_metadata( $field, $value ) {
		$this->dc_metadata[ $field ] = $value;
	}

	/**
	 * Determine WordPress post date based on Dublin Core date or identifier.
	 *
	 * Tries to parse the date field first. If not available, extracts year from identifier.
	 * Defaults to January 1 when only year is available.
	 *
	 * @return string|false Unix timestamp or false if no date can be determined.
	 */
	protected function get_post_date_from_metadata() {
		$date_str = $this->get_dc_metadata( 'date' );
		if ( $date_str ) {
			$timestamp = $this->parse_date_value( (string) $date_str );
			if ( $timestamp ) {
				return $timestamp;
			}
		}

		$identifier = $this->get_dc_metadata( 'identifier' );
		if ( $identifier ) {
			$year = $this->extract_year_from_identifier( (string) $identifier );
			if ( $year ) {
				return strtotime( 'January 1, ' . $year );
			}
		}

		return false;
	}

	/**
	 * Parse a date value from Dublin Core date field.
	 *
	 * Handles various formats:
	 * - Full dates: "April 8, 1905" -> timestamp
	 * - Years only: "1920", "1950?", "c. 1930's" -> Jan 1 of that year
	 * - Ranges: "1930-1940" -> earliest year
	 * - Missing: "n.d." -> null
	 *
	 * @param string $date_str Raw date value.
	 * @return int|false Unix timestamp or false if unparseable.
	 */
	protected function parse_date_value( string $date_str ) {
		$date_str = trim( $date_str );
		if ( '' === $date_str || 'n.d.' === $date_str ) {
			return false;
		}

		// Try parsing as a complete date first (e.g., "April 8, 1905")
		$timestamp = strtotime( $date_str );
		if ( false !== $timestamp ) {
			return $timestamp;
		}

		// Extract the first 4-digit year from the string
		if ( preg_match( '/\b(\d{4})\b/', $date_str, $matches ) ) {
			$year = (int) $matches[1];
			if ( $year >= 1800 && $year <= 2100 ) {
				return strtotime( 'January 1, ' . $year );
			}
		}

		return false;
	}

	/**
	 * Extract year from PastPerfect identifier.
	 *
	 * Examples:
	 * - "2025.02.007" -> 2025
	 * - "2014.6.2" -> 2014
	 * - "invalid" -> false
	 *
	 * @param string $identifier Identifier string.
	 * @return int|false Year or false if not found.
	 */
	protected function extract_year_from_identifier( string $identifier ): ?int {
		if ( ! $identifier || ! preg_match( '/^(\d{4})\./', $identifier, $matches ) ) {
			return null;
		}

		$year = (int) $matches[1];
		return ( $year >= 1800 && $year <= 2100 ) ? $year : null;
	}

	/**
	 * Check if a title is a single word and enhance it with description snippet.
	 *
	 * For titles that are single words (e.g., "photo", "photograph", "menu", "calendar"),
	 * append the first 12 characters from the description to make the title more distinctive.
	 *
	 * @param string $title The title to check and potentially enhance.
	 * @param string $description The description to extract snippet from.
	 * @return string The original or enhanced title.
	 */
	protected function enhance_single_word_title( string $title, string $description ): string {
		$title = trim( $title );
		if ( '' === $title ) {
			return $title;
		}

		// Check if title is a single word
		if ( 1 !== str_word_count( $title ) ) {
			return $title;
		}

		// Single-word title detected - enhance with description snippet
		$description = trim( $description );
		if ( '' === $description ) {
			return $title;
		}

		// Extract first 12 characters from description
		$snippet = substr( $description, 0, 12 );
		return $title . ' (' . $snippet . ')';
	}

	public function save() {
		// Determine whether this is a new or existing record.
		$identifier = $this->get_dc_metadata( 'identifier' );
		$post_id = null;
		$is_new = true;
		if ( $identifier ) {
			$post_id = $this->get_post_id_by_identifier( $identifier );
		}

		if ( $post_id ) {
			$post_data = array(
				'ID' => $post_id,
			);
			$is_new = false;
		} else {
			// Build post data for WP.
			$post_data = array(
				'post_type' => 'archive_item',
				'post_status' => $this->initial_post_status,
				'comment_status' => 'open',
			);
		}

		// post_title uses the title, enhanced for single-word titles.
		$title = $this->get_dc_metadata( 'title' );
		if ( $title ) {
			// Enhance single-word titles with description snippet
			$description = (string) $this->get_dc_metadata( 'description' );
			$title = $this->enhance_single_word_title( $title, $description );
		} else {
			// Fall back to identifier if no title
			$title = (string) $this->get_dc_metadata( 'identifier' );
		}

		$default_post_title = (string) $title;
		$post_data['post_title'] = apply_filters( 'archive_item_post_title', $default_post_title, $this->dc_metadata, $this );

		// post_content is description by default.
		$default_post_content = (string) $this->get_dc_metadata( 'description' );
		$post_data['post_content'] = apply_filters( 'archive_item_post_content', $default_post_content, $this->dc_metadata, $this );

		// Keep slug aligned with visible title by default.
		$slug_source = trim( (string) ( $post_data['post_title'] ?? '' ) );
		if ( '' === $slug_source ) {
			$slug_source = (string) $this->get_dc_metadata( 'identifier' );
		}
		$post_data['post_name'] = sanitize_title( $slug_source );

		if ( $is_new ) {
			// Set post_date for new records based on date metadata or identifier
			$post_date = $this->get_post_date_from_metadata();
			if ( $post_date ) {
				$post_data['post_date'] = gmdate( 'Y-m-d H:i:s', $post_date );
				$post_data['post_date_gmt'] = $post_data['post_date'];
			}
			$post_id = wp_insert_post( $post_data );
			if ( ! $post_id || is_wp_error( $post_id ) ) {
				self::write_save_error_debug( array(
					'time' => gmdate( 'c' ),
					'action' => 'insert',
					'post_data' => $post_data,
					'result' => $post_id,
				) );
			}
		} else {
			$post_id = wp_update_post( $post_data );
			if ( ! $post_id || is_wp_error( $post_id ) ) {
				self::write_save_error_debug( array(
					'time' => gmdate( 'c' ),
					'action' => 'update',
					'post_data' => $post_data,
					'result' => $post_id,
				) );
			}
		}

		if ( $post_id ) {
			$taxonomy_elements = apply_filters( 'archive_item_taxonomy_elements', self::get_taxonomy_elements(), $this );
			if ( ! is_array( $taxonomy_elements ) ) {
				$taxonomy_elements = self::get_taxonomy_elements();
			}

			foreach ( $taxonomy_elements as $element => $taxonomy ) {
				$terms = $this->get_dc_metadata( $element, false );
				$terms = apply_filters( 'archive_item_taxonomy_terms', $terms, $element, $taxonomy, $this );
				wp_set_object_terms( $post_id, $terms, $taxonomy );
				if ( class_exists( __NAMESPACE__ . '\\Admin' ) ) {
					Admin::maybe_auto_tag_post_from_content( (int) $post_id, (string) $taxonomy );
				}
			}

			foreach ( $this->dc_metadata as $dc_key => $_ ) {
				// Skip elements stored in taxonomy.
				if ( self::get_element_taxonomy( $dc_key ) ) {
					continue;
				}

				$meta_key = 'pastperfect_dc_' . $dc_key;

				// Delete existing keys, in case of update.
				delete_post_meta( $post_id, $meta_key );

				$f = $this->get_dc_metadata( $dc_key, false );

				// Don't save empty fields.
				if ( empty( $f ) ) {
					continue;
				}

				if ( is_array( $f ) ) {
					foreach ( $f as $value ) {
						add_post_meta( $post_id, $meta_key, $this->addslashes_deep( $value ) );
					}
				} else {
					add_post_meta( $post_id, $meta_key, $this->addslashes_deep( $f ) );
				}
			}

			$this->populate( $post_id );
		}

		return $post_id;
	}

	/**
	 * Append save error debug info to uploads/pastperfect-import-errors.json.
	 */
	private static function write_save_error_debug( array $data ): void {
		try {
			$uploads = wp_get_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return;
			}
			$path = trailingslashit( (string) $uploads['basedir'] ) . 'pastperfect-import-errors.json';
			$existing = array();
			if ( is_readable( $path ) ) {
				$contents = file_get_contents( $path );
				if ( false !== $contents ) {
					$decoded = json_decode( $contents, true );
					if ( is_array( $decoded ) ) {
						$existing = $decoded;
					}
				}
			}
			$existing[] = $data;
			@file_put_contents( $path, wp_json_encode( $existing, JSON_PRETTY_PRINT ) );
		} catch ( \Throwable $e ) {
			// Swallow errors to avoid interfering with import.
		}
	}

	public function get_post_id_by_identifier( $identifier ) {
		$found = get_posts( array(
			'posts_per_page' => 1,
			'post_type' => 'archive_item',
			'post_status' => 'any',
			'meta_query' => array(
				array(
					'key' => 'pastperfect_dc_identifier',
					'value' => $identifier,
				),
			),
			'fields' => 'ids',
		) );

		$post_id = null;
		if ( $found ) {
			$post_id = reset( $found );
		}

		return $post_id;
	}

	/**
	 * Populate object from database ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id
	 */
	protected function populate( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( ! $post || 'archive_item' !== $post->post_type ) {
			return;
		}

		$this->post = $post;

		foreach ( self::get_dc_elements() as $element ) {
			if ( $tax = self::get_element_taxonomy( $element ) ) {
				$terms = get_the_terms( $post_id, $tax );
				$values = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : '';
			} else {
				$get_single = in_array( $element, self::get_singular_elements(), true );
				$values = get_post_meta( $post_id, 'pastperfect_dc_' . $element, $get_single );
			}

			$this->dc_metadata[ $element ] = $values;
		}
	}

	public static function get_dc_elements() {
		return self::$dc_elements;
	}

	public static function get_dc_core_elements() {
		return self::$dc_elements;
	}

	public static function get_singular_elements() {
		return self::$singular_elements;
	}

	public static function get_taxonomy_elements() {
		return self::$taxonomy_elements;
	}

	/**
	 * Get the taxonomy corresponding to an element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $element Element name.
	 * @return string|null Taxonomy name if found, else null.
	 */
	public static function get_element_taxonomy( $element ) {
		$taxonomy_elements = self::get_taxonomy_elements();

		if ( isset( $taxonomy_elements[ $element ] ) ) {
			return $taxonomy_elements[ $element ];
		} else {
			return null;
		}
	}

	/**
	 * Formats a record for use in an endpoint.
	 *
	 * @param int $version Endpoint version number.
	 */
	public function format_for_endpoint( $version ) {
		$dc_metadata = array();

		foreach ( self::get_dc_core_elements() as $dc_element ) {
			$value = $this->get_dc_metadata( $dc_element, false );

			$value = $this->format_field_for_endpoint( $dc_element, $value, $version );

			$dc_metadata[ $dc_element ] = $value;
		}

		return $dc_metadata;
	}

	/**
	 * Merge incoming metadata while respecting singular/multi element behavior.
	 *
	 * @param string $field Metadata field name.
	 * @param mixed  $value Metadata value.
	 */
	protected function set_dc_metadata_value( $field, $value ) {
		$is_singular = in_array( $field, self::get_singular_elements(), true );

		if ( $is_singular ) {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			if ( '' !== (string) $value ) {
				$this->dc_metadata[ $field ] = $value;
			}

			return;
		}

		$existing = array();
		if ( isset( $this->dc_metadata[ $field ] ) ) {
			$existing = (array) $this->dc_metadata[ $field ];
		}

		$incoming = is_array( $value ) ? $value : array( $value );
		$merged = array_values( array_filter( array_merge( $existing, $incoming ) ) );
		if ( ! empty( $merged ) ) {
			$this->dc_metadata[ $field ] = $merged;
		}
	}

	/**
	 * Formats a specific field for use in an endpoint.
	 *
	 * @param string $element Element name (Dublin Core field name).
	 * @param string $value   Raw value.
	 * @param int    $version API endpoint version.
	 */
	protected function format_field_for_endpoint( $element, $value, $version ) {
		unset( $version, $element );
		return $value;
	}

	public function convert_filename_to_asset_path( $value ) {
		$value = str_replace( '\\', '/', $value );
		$value = trailingslashit( $this->asset_base ) . basename( $value );
		return $value;
	}

	public function addslashes_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'addslashes_deep' ), $value );
		} elseif ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			foreach ( $vars as $key => $data ) {
				$value->{$key} = $this->addslashes_deep( $data );
			}
			return $value;
		}

		return addslashes( $value );
	}
}
