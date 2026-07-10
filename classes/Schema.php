<?php

namespace PastPerfect\Archive;

/**
 * Schema.
 *
 * @since 1.0.0
 */
class Schema {
	private const OPTION_SETUP_SETTINGS = 'pastperfect_setup_settings';
	private const OPTION_REWRITE_SIGNATURE = 'pastperfect_rewrite_signature';

	/**
	 * Hook into WP.
	 *
	 * @since 1.0.0
	 */
	public function set_up_hooks() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ), 12 );
		add_action( 'init', array( $this, 'maybe_refresh_rewrite_rules' ), 20 );
	}

	/**
	 * Refresh rewrite rules once when setup permalink settings drift.
	 *
	 * @since 1.0.0
	 */
	public function maybe_refresh_rewrite_rules() {
		$settings = self::get_setup_settings();
		$signature = implode( '|', array(
			(string) $settings['post_type_slug'],
			(string) $settings['subject_slug'],
			empty( $settings['subject_public'] ) ? '0' : '1',
		) );

		$stored_signature = get_option( self::OPTION_REWRITE_SIGNATURE, '' );
		if ( is_string( $stored_signature ) && $stored_signature === $signature ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::OPTION_REWRITE_SIGNATURE, $signature, false );
	}

	/**
	 * Register post types.
	 *
	 * - ppwp_record is a record.
	 *
	 * @since 1.0.0
	 */
	public function register_post_types() {
		$settings = self::get_setup_settings();
		$plural_label = (string) $settings['post_type_label_plural'];
		$singular_label = (string) $settings['post_type_label_singular'];
		$post_type_slug = (string) $settings['post_type_slug'];
		$menu_label = __( 'PastPerfect', 'pastperfect-wp' );

		register_post_type( 'ppwp_record', array(
			'label' => $plural_label,
			'labels' => array(
				'name' => $plural_label,
				'menu_name' => $menu_label,
				'all_items' => sprintf( __( 'Manage %s', 'webwork' ), $plural_label ),
				'singular_name' => $singular_label,
				'add_new_item' => sprintf( __( 'Add New %s', 'webwork' ), $singular_label ),
				'edit_item' => sprintf( __( 'Edit %s', 'webwork' ), $singular_label ),
				'new_item' => sprintf( __( 'New %s', 'webwork' ), $singular_label ),
				'view_item' => sprintf( __( 'View %s', 'webwork' ), $singular_label ),
				'search_items' => sprintf( __( 'Search %s', 'webwork' ), $plural_label ),
				'not_found' => sprintf( __( 'No %s found', 'webwork' ), strtolower( $plural_label ) ),
				'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'webwork' ), strtolower( $plural_label ) ),
			),
			'menu_icon' => 'dashicons-book-alt',
			'public' => true,
			'show_in_menu' => true,
			'has_archive' => true,
			'rewrite' => array(
				'slug' => $post_type_slug,
				'with_front' => false,
			),
			'show_in_rest' => true,
			'supports' => array( 'title', 'comments' ),
		) );
	}

	/**
	 * Register taxonomies.
	 *
	 * - ppwp_subject is Dublin Core <subject>
	 *
	 * @since 1.0.0
	 */
	public function register_taxonomies() {
		$settings = self::get_setup_settings();
		$subject_slug = (string) $settings['subject_slug'];
		$subject_public = ! empty( $settings['subject_public'] );

		register_taxonomy( 'ppwp_subject', 'ppwp_record', array(
			'public' => $subject_public,
			'hierarchical' => true,
			'show_ui' => true,
			'show_admin_column' => true,
			'show_in_rest' => true,
			'rewrite' => array(
				'slug' => $subject_slug,
				'with_front' => false,
			),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_default_setup_settings(): array {
		return array(
			'post_type_label_plural' => 'PastPerfect Records',
			'post_type_label_singular' => 'PastPerfect Record',
			'post_type_slug' => 'ppwp_record',
			'subject_slug' => 'ppwp-subject',
			'subject_public' => false,
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function sanitize_setup_settings( array $input ): array {
		$defaults = self::get_default_setup_settings();

		$plural = isset( $input['post_type_label_plural'] ) ? sanitize_text_field( (string) $input['post_type_label_plural'] ) : (string) $defaults['post_type_label_plural'];
		if ( '' === $plural ) {
			$plural = (string) $defaults['post_type_label_plural'];
		}

		$singular = isset( $input['post_type_label_singular'] ) ? sanitize_text_field( (string) $input['post_type_label_singular'] ) : (string) $defaults['post_type_label_singular'];
		if ( '' === $singular ) {
			$singular = (string) $defaults['post_type_label_singular'];
		}

		$post_type_slug = isset( $input['post_type_slug'] ) ? sanitize_title( (string) $input['post_type_slug'] ) : (string) $defaults['post_type_slug'];
		if ( '' === $post_type_slug ) {
			$post_type_slug = (string) $defaults['post_type_slug'];
		}

		$subject_slug = isset( $input['subject_slug'] ) ? sanitize_title( (string) $input['subject_slug'] ) : (string) $defaults['subject_slug'];
		if ( '' === $subject_slug ) {
			$subject_slug = (string) $defaults['subject_slug'];
		}

		$subject_public = ! empty( $input['subject_public'] );

		return array(
			'post_type_label_plural' => $plural,
			'post_type_label_singular' => $singular,
			'post_type_slug' => $post_type_slug,
			'subject_slug' => $subject_slug,
			'subject_public' => $subject_public,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_setup_settings(): array {
		$defaults = self::get_default_setup_settings();
		$stored = get_option( self::OPTION_SETUP_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::sanitize_setup_settings( array_merge( $defaults, $stored ) );
	}
}
