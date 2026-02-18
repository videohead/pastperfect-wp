<?php

namespace WP\PastPerfect;

/**
 * Admin UI for managing taxonomies.
 *
 * @since 1.0.0
 */
class Taxonomy_Admin {
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
	 * Set up hooks.
	 */
	public function set_up_hooks() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'wp_ajax_wppp_add_taxonomy', array( $this, 'ajax_add_taxonomy' ) );
		add_action( 'wp_ajax_wppp_update_taxonomy', array( $this, 'ajax_update_taxonomy' ) );
		add_action( 'wp_ajax_wppp_delete_taxonomy', array( $this, 'ajax_delete_taxonomy' ) );
		add_action( 'wp_ajax_wppp_reset_taxonomies', array( $this, 'ajax_reset_taxonomies' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register admin menu.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=wppp_record',
			__( 'Manage Taxonomies', 'wp-pastperfect' ),
			__( 'Taxonomies', 'wp-pastperfect' ),
			'manage_options',
			'wppp-taxonomies',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'wppp_record_page_wppp-taxonomies' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wppp-taxonomy-admin', WPPP_PLUGIN_URL . 'assets/css/taxonomy-admin.css', array(), WPPP_VERSION );
		wp_enqueue_script( 'wppp-taxonomy-admin', WPPP_PLUGIN_URL . 'assets/js/taxonomy-admin.js', array( 'jquery' ), WPPP_VERSION, true );
		
		wp_localize_script(
			'wppp-taxonomy-admin',
			'wpppTaxonomyAdmin',
			array(
				'nonce'   => wp_create_nonce( 'wppp_taxonomy_nonce' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Render the taxonomies management page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-pastperfect' ) );
		}

		$taxonomies = $this->taxonomy_manager->get_taxonomies();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Manage Taxonomies', 'wp-pastperfect' ); ?></h1>
			<p><?php esc_html_e( 'Configure custom taxonomies for PastPerfect records. These taxonomies will be used to organize and categorize your records.', 'wp-pastperfect' ); ?></p>

			<div class="wppp-taxonomy-management">
				<div class="wppp-taxonomy-list">
					<h2><?php esc_html_e( 'Current Taxonomies', 'wp-pastperfect' ); ?></h2>
					
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Slug', 'wp-pastperfect' ); ?></th>
								<th><?php esc_html_e( 'Name', 'wp-pastperfect' ); ?></th>
								<th><?php esc_html_e( 'Singular', 'wp-pastperfect' ); ?></th>
								<th><?php esc_html_e( 'Hierarchical', 'wp-pastperfect' ); ?></th>
								<th><?php esc_html_e( 'Public', 'wp-pastperfect' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wp-pastperfect' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $taxonomies ) ) : ?>
								<tr>
									<td colspan="6"><?php esc_html_e( 'No taxonomies found.', 'wp-pastperfect' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $taxonomies as $slug => $taxonomy ) : ?>
									<tr data-slug="<?php echo esc_attr( $slug ); ?>" data-taxonomy="<?php echo esc_attr( wp_json_encode( $taxonomy ) ); ?>">
										<td><code><?php echo esc_html( $taxonomy['slug'] ); ?></code></td>
										<td><?php echo esc_html( $taxonomy['name'] ); ?></td>
										<td><?php echo esc_html( $taxonomy['singular'] ); ?></td>
										<td><?php echo ! empty( $taxonomy['hierarchical'] ) ? esc_html__( 'Yes', 'wp-pastperfect' ) : esc_html__( 'No', 'wp-pastperfect' ); ?></td>
										<td><?php echo ! empty( $taxonomy['public'] ) ? esc_html__( 'Yes', 'wp-pastperfect' ) : esc_html__( 'No', 'wp-pastperfect' ); ?></td>
										<td>
											<button type="button" class="button button-small wppp-edit-taxonomy" data-slug="<?php echo esc_attr( $slug ); ?>">
												<?php esc_html_e( 'Edit', 'wp-pastperfect' ); ?>
											</button>
											<button type="button" class="button button-small button-link-delete wppp-delete-taxonomy" data-slug="<?php echo esc_attr( $slug ); ?>">
												<?php esc_html_e( 'Delete', 'wp-pastperfect' ); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="button" class="button button-secondary" id="wppp-reset-taxonomies">
							<?php esc_html_e( 'Reset to Defaults', 'wp-pastperfect' ); ?>
						</button>
					</p>
				</div>

				<div class="wppp-taxonomy-form">
					<h2 id="wppp-form-title"><?php esc_html_e( 'Add New Taxonomy', 'wp-pastperfect' ); ?></h2>
					
					<form id="wppp-taxonomy-form">
						<input type="hidden" name="action" value="wppp_add_taxonomy" id="wppp-form-action">
						<input type="hidden" name="original_slug" id="wppp-original-slug">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'wppp_taxonomy_nonce' ) ); ?>">

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="wppp-slug"><?php esc_html_e( 'Slug', 'wp-pastperfect' ); ?> <span class="required">*</span></label>
								</th>
								<td>
									<input type="text" name="slug" id="wppp-slug" class="regular-text" required pattern="^wppp_[a-z0-9_]+$">
									<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, and underscores only. Must start with "wppp_".', 'wp-pastperfect' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wppp-name"><?php esc_html_e( 'Plural Name', 'wp-pastperfect' ); ?> <span class="required">*</span></label>
								</th>
								<td>
									<input type="text" name="name" id="wppp-name" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'E.g., "People" or "Locations"', 'wp-pastperfect' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wppp-singular"><?php esc_html_e( 'Singular Name', 'wp-pastperfect' ); ?> <span class="required">*</span></label>
								</th>
								<td>
									<input type="text" name="singular" id="wppp-singular" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'E.g., "Person" or "Location"', 'wp-pastperfect' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'wp-pastperfect' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="hierarchical" id="wppp-hierarchical" value="1">
										<?php esc_html_e( 'Hierarchical (like categories)', 'wp-pastperfect' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="public" id="wppp-public" value="1" checked>
										<?php esc_html_e( 'Public', 'wp-pastperfect' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="show_ui" id="wppp-show-ui" value="1" checked>
										<?php esc_html_e( 'Show UI in admin', 'wp-pastperfect' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="show_in_rest" id="wppp-show-in-rest" value="1" checked>
										<?php esc_html_e( 'Show in REST API', 'wp-pastperfect' ); ?>
									</label>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary" id="wppp-submit-taxonomy">
								<?php esc_html_e( 'Add Taxonomy', 'wp-pastperfect' ); ?>
							</button>
							<button type="button" class="button button-secondary" id="wppp-cancel-edit" style="display:none;">
								<?php esc_html_e( 'Cancel', 'wp-pastperfect' ); ?>
							</button>
						</p>

						<div id="wppp-taxonomy-message" style="display:none;"></div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for adding a taxonomy.
	 */
	public function ajax_add_taxonomy() {
		check_ajax_referer( 'wppp_taxonomy_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-pastperfect' ) ) );
		}

		$taxonomy_data = array(
			'slug'         => sanitize_key( $_POST['slug'] ),
			'name'         => sanitize_text_field( $_POST['name'] ),
			'singular'     => sanitize_text_field( $_POST['singular'] ),
			'hierarchical' => ! empty( $_POST['hierarchical'] ),
			'public'       => ! empty( $_POST['public'] ),
			'show_ui'      => ! empty( $_POST['show_ui'] ),
			'show_in_rest' => ! empty( $_POST['show_in_rest'] ),
		);

		$result = $this->taxonomy_manager->add_taxonomy( $taxonomy_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Taxonomy added successfully.', 'wp-pastperfect' ) ) );
	}

	/**
	 * AJAX handler for updating a taxonomy.
	 */
	public function ajax_update_taxonomy() {
		check_ajax_referer( 'wppp_taxonomy_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-pastperfect' ) ) );
		}

		$original_slug = sanitize_key( $_POST['original_slug'] );
		$taxonomy_data = array(
			'slug'         => sanitize_key( $_POST['slug'] ),
			'name'         => sanitize_text_field( $_POST['name'] ),
			'singular'     => sanitize_text_field( $_POST['singular'] ),
			'hierarchical' => ! empty( $_POST['hierarchical'] ),
			'public'       => ! empty( $_POST['public'] ),
			'show_ui'      => ! empty( $_POST['show_ui'] ),
			'show_in_rest' => ! empty( $_POST['show_in_rest'] ),
		);

		$result = $this->taxonomy_manager->update_taxonomy( $original_slug, $taxonomy_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Taxonomy updated successfully.', 'wp-pastperfect' ) ) );
	}

	/**
	 * AJAX handler for deleting a taxonomy.
	 */
	public function ajax_delete_taxonomy() {
		check_ajax_referer( 'wppp_taxonomy_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-pastperfect' ) ) );
		}

		$slug   = sanitize_key( $_POST['slug'] );
		$result = $this->taxonomy_manager->delete_taxonomy( $slug );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Taxonomy deleted successfully.', 'wp-pastperfect' ) ) );
	}

	/**
	 * AJAX handler for resetting taxonomies to defaults.
	 */
	public function ajax_reset_taxonomies() {
		check_ajax_referer( 'wppp_taxonomy_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-pastperfect' ) ) );
		}

		$result = $this->taxonomy_manager->reset_to_defaults();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Taxonomies reset to defaults successfully.', 'wp-pastperfect' ) ) );
	}
}
