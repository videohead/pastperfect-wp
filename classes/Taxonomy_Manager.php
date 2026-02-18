<?php

namespace WP\PastPerfect;

/**
 * Manages custom taxonomies for PastPerfect records.
 *
 * @since 1.0.0
 */
class Taxonomy_Manager {
	/**
	 * Option name for storing taxonomies.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wppp_custom_taxonomies';

	/**
	 * Get all registered custom taxonomies.
	 *
	 * @return array
	 */
	public function get_taxonomies() {
		$taxonomies = get_option( self::OPTION_NAME, array() );

		// If no taxonomies exist, create defaults.
		if ( empty( $taxonomies ) ) {
			$taxonomies = $this->get_default_taxonomies();
			update_option( self::OPTION_NAME, $taxonomies );
		}

		return $taxonomies;
	}

	/**
	 * Get default taxonomies.
	 *
	 * @return array
	 */
	protected function get_default_taxonomies() {
		return array(
			'wppp_subject_subject' => array(
				'slug'         => 'wppp_subject_subject',
				'name'         => __( 'Subjects', 'wp-pastperfect' ),
				'singular'     => __( 'Subject', 'wp-pastperfect' ),
				'hierarchical' => false,
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
			),
			'wppp_subject_people' => array(
				'slug'         => 'wppp_subject_people',
				'name'         => __( 'People', 'wp-pastperfect' ),
				'singular'     => __( 'Person', 'wp-pastperfect' ),
				'hierarchical' => false,
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
			),
			'wppp_subject_places' => array(
				'slug'         => 'wppp_subject_places',
				'name'         => __( 'Places', 'wp-pastperfect' ),
				'singular'     => __( 'Place', 'wp-pastperfect' ),
				'hierarchical' => false,
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
			),
			'wppp_subject_genre' => array(
				'slug'         => 'wppp_subject_genre',
				'name'         => __( 'Genres', 'wp-pastperfect' ),
				'singular'     => __( 'Genre', 'wp-pastperfect' ),
				'hierarchical' => false,
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Add a new taxonomy.
	 *
	 * @param array $taxonomy_data Taxonomy configuration.
	 * @return bool|\WP_Error
	 */
	public function add_taxonomy( $taxonomy_data ) {
		$validation = $this->validate_taxonomy_data( $taxonomy_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$taxonomies = $this->get_taxonomies();
		$slug       = $taxonomy_data['slug'];

		// Check if taxonomy already exists.
		if ( isset( $taxonomies[ $slug ] ) ) {
			return new \WP_Error( 'taxonomy_exists', __( 'A taxonomy with this slug already exists.', 'wp-pastperfect' ) );
		}

		$taxonomies[ $slug ] = $taxonomy_data;
		update_option( self::OPTION_NAME, $taxonomies );

		// Flush rewrite rules.
		flush_rewrite_rules();

		return true;
	}

	/**
	 * Update an existing taxonomy.
	 *
	 * @param string $slug          Taxonomy slug.
	 * @param array  $taxonomy_data Taxonomy configuration.
	 * @return bool|\WP_Error
	 */
	public function update_taxonomy( $slug, $taxonomy_data ) {
		$validation = $this->validate_taxonomy_data( $taxonomy_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$taxonomies = $this->get_taxonomies();

		if ( ! isset( $taxonomies[ $slug ] ) ) {
			return new \WP_Error( 'taxonomy_not_found', __( 'Taxonomy not found.', 'wp-pastperfect' ) );
		}

		$taxonomies[ $slug ] = $taxonomy_data;
		update_option( self::OPTION_NAME, $taxonomies );

		// Flush rewrite rules.
		flush_rewrite_rules();

		return true;
	}

	/**
	 * Delete a taxonomy.
	 *
	 * @param string $slug Taxonomy slug.
	 * @return bool|\WP_Error
	 */
	public function delete_taxonomy( $slug ) {
		$taxonomies = $this->get_taxonomies();

		if ( ! isset( $taxonomies[ $slug ] ) ) {
			return new \WP_Error( 'taxonomy_not_found', __( 'Taxonomy not found.', 'wp-pastperfect' ) );
		}

		unset( $taxonomies[ $slug ] );
		update_option( self::OPTION_NAME, $taxonomies );

		// Flush rewrite rules.
		flush_rewrite_rules();

		return true;
	}

	/**
	 * Get a single taxonomy by slug.
	 *
	 * @param string $slug Taxonomy slug.
	 * @return array|null
	 */
	public function get_taxonomy( $slug ) {
		$taxonomies = $this->get_taxonomies();
		return isset( $taxonomies[ $slug ] ) ? $taxonomies[ $slug ] : null;
	}

	/**
	 * Validate taxonomy data.
	 *
	 * @param array $data Taxonomy data to validate.
	 * @return true|\WP_Error
	 */
	protected function validate_taxonomy_data( $data ) {
		if ( empty( $data['slug'] ) ) {
			return new \WP_Error( 'missing_slug', __( 'Taxonomy slug is required.', 'wp-pastperfect' ) );
		}

		if ( empty( $data['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'Taxonomy name is required.', 'wp-pastperfect' ) );
		}

		if ( empty( $data['singular'] ) ) {
			return new \WP_Error( 'missing_singular', __( 'Taxonomy singular name is required.', 'wp-pastperfect' ) );
		}

		// Validate slug format.
		if ( ! preg_match( '/^[a-z0-9_]+$/', $data['slug'] ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Taxonomy slug can only contain lowercase letters, numbers, and underscores.', 'wp-pastperfect' ) );
		}

		// Ensure slug starts with wppp prefix for consistency.
		if ( strpos( $data['slug'], 'wppp_' ) !== 0 ) {
			return new \WP_Error( 'invalid_slug_prefix', __( 'Taxonomy slug must start with "wppp_".', 'wp-pastperfect' ) );
		}

		return true;
	}

	/**
	 * Reset taxonomies to defaults.
	 *
	 * @return bool
	 */
	public function reset_to_defaults() {
		$defaults = $this->get_default_taxonomies();
		update_option( self::OPTION_NAME, $defaults );
		flush_rewrite_rules();
		return true;
	}
}
