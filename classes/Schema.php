<?php

namespace WP\PastPerfect;

/**
 * Schema.
 *
 * @since 1.0.0
 */
class Schema {
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
				'label'       => __( 'PastPerfect Records', 'wp-pastperfect' ),
				'labels'      => array(
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
				'menu_icon'   => 'dashicons-book-alt',
				'public'      => true,
				'show_in_rest' => true,
				'rest_base'   => 'pastperfect-records',
				'supports'    => array( 'title', 'editor', 'custom-fields' ),
				'has_archive' => true,
				'rewrite'     => array(
					'slug'       => 'pastperfect-record',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Register taxonomies.
	 *
	 * - wppp_subject_subject is <subject_subject>
	 * - wppp_subject_people is <subject_people>
	 * - wppp_subject_places is <subject_places>
	 * - wppp_subject_genre is <subject_genre>
	 *
	 * @since 1.0.0
	 */
	public function register_taxonomies() {
		register_taxonomy(
			'wppp_subject_subject',
			'wppp_record',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'labels'       => array(
					'name'          => __( 'Subjects', 'wp-pastperfect' ),
					'singular_name' => __( 'Subject', 'wp-pastperfect' ),
				),
			)
		);
		register_taxonomy(
			'wppp_subject_people',
			'wppp_record',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'labels'       => array(
					'name'          => __( 'People', 'wp-pastperfect' ),
					'singular_name' => __( 'Person', 'wp-pastperfect' ),
				),
			)
		);
		register_taxonomy(
			'wppp_subject_places',
			'wppp_record',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'labels'       => array(
					'name'          => __( 'Places', 'wp-pastperfect' ),
					'singular_name' => __( 'Place', 'wp-pastperfect' ),
				),
			)
		);
		register_taxonomy(
			'wppp_subject_genre',
			'wppp_record',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'labels'       => array(
					'name'          => __( 'Genres', 'wp-pastperfect' ),
					'singular_name' => __( 'Genre', 'wp-pastperfect' ),
				),
			)
		);
	}
}
