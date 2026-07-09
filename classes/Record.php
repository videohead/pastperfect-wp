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
		'subject' => 'ppwp_subject',
	);

	protected $dc_metadata = array();

	protected $asset_base = 'https://s3.amazonaws.com/pastperfect.assets/';

	protected $post;

	public function __construct( $post_id = null ) {
		if ( $post_id ) {
			$this->populate( $post_id );
		}
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
				'post_type' => 'ppwp_record',
				'post_status' => 'publish',
			);
		}

		// post_title is a combination of identifier + title.
		$title_parts = array( $this->get_dc_metadata( 'identifier' ) );

		if ( $title = $this->get_dc_metadata( 'title' ) ) {
			$title_parts[] = $title;
		}

		$post_data['post_title'] = implode( ' - ', $title_parts );

		// post_content is 'description'.
		$post_data['post_content'] = $this->get_dc_metadata( 'description' );

		// post_name is a URL-safe version of the identifier.
		$post_data['post_name'] = sanitize_title( $this->get_dc_metadata( 'identifier' ) );

		if ( $is_new ) {
			$post_id = wp_insert_post( $post_data );
		} else {
			$post_id = wp_update_post( $post_data );
		}

		if ( $post_id ) {
			foreach ( self::get_taxonomy_elements() as $element => $taxonomy ) {
				wp_set_object_terms( $post_id, $this->get_dc_metadata( $element, false ), $taxonomy );
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

	public function get_post_id_by_identifier( $identifier ) {
		$found = get_posts( array(
			'posts_per_page' => 1,
			'post_type' => 'ppwp_record',
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

		if ( ! $post || 'ppwp_record' !== $post->post_type ) {
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
