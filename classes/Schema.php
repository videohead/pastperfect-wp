<?php

namespace WP\PastPerfect;

/**
 * Schema.
 *
 * @since 1.0.0
 */
class Schema {
	/**
	 * Taxonomy manager instance.
	 *
	 * @var Taxonomy_Manager
	 */
	protected $taxonomy_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->taxonomy_manager = new Taxonomy_Manager();
	}

	/**
	 * Hook into WP.
	 *
	 * @since 1.0.0
	 */
	public function set_up_hooks() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ), 12 );
	}

	/**
	 * Register post types.
	 *
	 * - wppp_record is a record.
	 *
	 * @since 1.0.0
	 */
	public function register_post_types() {
		register_post_type(
			'wppp_record',
			array(
				'label'        => __( 'PastPerfect Records', 'wp-pastperfect' ),
				'labels'       => array(
					'name'               => __( 'PastPerfect Records', 'wp-pastperfect' ),
					'all_items'          => __( 'Manage Records', 'wp-pastperfect' ),
					'singular_name'      => __( 'PastPerfect Record', 'wp-pastperfect' ),
					'add_new_item'       => __( 'Add New PastPerfect Record', 'wp-pastperfect' ),
					'edit_item'          => __( 'Edit PastPerfect Record', 'wp-pastperfect' ),
					'new_item'           => __( 'New PastPerfect Record', 'wp-pastperfect' ),
					'view_item'          => __( 'View PastPerfect Record', 'wp-pastperfect' ),
					'search_items'       => __( 'Search PastPerfect Records', 'wp-pastperfect' ),
					'not_found'          => __( 'No PastPerfect Records found', 'wp-pastperfect' ),
					'not_found_in_trash' => __( 'No PastPerfect Records found in Trash.', 'wp-pastperfect' ),
				),
				'menu_icon'    => 'dashicons-book-alt',
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'pastperfect-records',
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'  => true,
				'rewrite'      => array(
					'slug'       => 'pastperfect-record',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Register taxonomies dynamically from Taxonomy_Manager.
	 *
	 * @since 1.0.0
	 */
	public function register_taxonomies() {
		$taxonomies = $this->taxonomy_manager->get_taxonomies();

		foreach ( $taxonomies as $taxonomy ) {
			$args = array(
				'public'       => ! empty( $taxonomy['public'] ),
				'show_ui'      => ! empty( $taxonomy['show_ui'] ),
				'show_in_rest' => ! empty( $taxonomy['show_in_rest'] ),
				'hierarchical' => ! empty( $taxonomy['hierarchical'] ),
				'labels'       => array(
					'name'          => $taxonomy['name'],
					'singular_name' => $taxonomy['singular'],
				),
			);

			register_taxonomy(
				$taxonomy['slug'],
				'wppp_record',
				$args
			);
		}
	}
}
