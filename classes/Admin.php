<?php

namespace WP\PastPerfect;

/**
 * Entrance class for admin functionality.
 *
 * @since 1.0.0
 */
class Admin {
	/**
	 * Import handler instance.
	 *
	 * @var Import_Handler
	 */
	protected $import_handler;

	/**
	 * AJAX import handler instance.
	 *
	 * @var Ajax_Import_Handler
	 */
	protected $ajax_import_handler;

	/**
	 * Meta box handler instance.
	 *
	 * @var Meta_Box
	 */
	protected $meta_box;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->import_handler      = new Import_Handler();
		$this->ajax_import_handler = new Ajax_Import_Handler();
		$this->meta_box            = new Meta_Box();
	}

	/**
	 * Register CSS and JS assets.
	 *
	 * @since 1.0.0
	 */
	public function register_assets() {
		wp_register_script(
			'wppp_admin',
			WPPP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-progressbar' ),
			WPPP_VERSION,
			true
		);

		wp_register_style(
			'wppp-jquery-ui-progressbar',
			WPPP_PLUGIN_URL . 'assets/css/progressbar.css',
			array(),
			WPPP_VERSION
		);

		wp_register_style(
			'wppp_admin',
			WPPP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WPPP_VERSION
		);
	}

	/**
	 * Hook into WP.
	 *
	 * @since 1.0.0
	 */
	public function set_up_hooks() {
		add_action( 'admin_menu', array( $this, 'route_admin_load' ) );
		add_action( 'add_meta_boxes', array( $this->meta_box, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_wppp_import_upload', array( $this->ajax_import_handler, 'process_ajax_submit' ) );
		add_action( 'wp_ajax_wppp_import_chunk', array( $this->ajax_import_handler, 'process_ajax_chunk' ) );

		// List table mods.
		add_filter( 'manage_wppp_record_posts_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_edit-wppp_record_sortable_columns', array( $this, 'add_sortable_column' ) );
		add_action( 'manage_wppp_record_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
	}

	/**
	 * Adds an 'updated' column to the list table.
	 *
	 * @param array $columns Column IDs and labels.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['updated'] = 'Last Updated';
		return $columns;
	}

	/**
	 * Specifies that 'updated' is a sortable column.
	 *
	 * @param array $columns Array of sortable columns.
	 * @return array
	 */
	public function add_sortable_column( $columns ) {
		$columns['updated'] = 'modified';
		return $columns;
	}

	/**
	 * Specifies custom column content.
	 *
	 * @param string $column  Column ID.
	 * @param int    $post_id ID of the current post.
	 */
	public function custom_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'updated':
				$post = get_post( $post_id );
				$date = wp_date( 'Y/m/d H:i:s', strtotime( $post->post_modified ) );
				echo esc_html( $date );
				break;
		}
	}

	/**
	 * Route the admin page.
	 *
	 * @since 1.0.0
	 */
	public function route_admin_load() {
		if ( $this->is_import_page() && current_user_can( 'manage_options' ) && ! empty( $_FILES['wppp-xml'] ) ) {
			check_admin_referer( 'wppp-import', 'wppp-import-nonce' );

			$is_trial = isset( $_POST['wppp-trial-run'] ) && '1' === $_POST['wppp-trial-run'];

			if ( $is_trial ) {
				$result = $this->import_handler->process_trial_import( $_FILES['wppp-xml'] );
			} else {
				$result = $this->import_handler->process_import( $_FILES['wppp-xml'] );
			}

			$args = array(
				'post_type' => 'wppp_record',
				'page'      => 'wppp-import-records',
			);

			if ( is_wp_error( $result ) ) {
				$args['import_error'] = urlencode( $result->get_error_message() );
			} else {
				if ( $is_trial ) {
					$args['trial_key'] = urlencode( $result );
				} else {
					$args['results_key'] = urlencode( $result );
				}
			}

			$redirect_to = add_query_arg( $args, admin_url( 'edit.php' ) );
			wp_safe_redirect( $redirect_to );
			die();
		}

		$this->register_admin_menu();
	}

	/**
	 * Register admin menus.
	 *
	 * @since 1.0.0
	 */
	protected function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=wppp_record',
			__( 'Import PastPerfect Records', 'wp-pastperfect' ),
			__( 'Import', 'wp-pastperfect' ),
			'manage_options',
			'wppp-import-records',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Render Import page.
	 *
	 * @since 1.0.0
	 */
	public function render_import_page() {
		wp_enqueue_script( 'wppp_admin' );
		wp_enqueue_style( 'wppp-jquery-ui-progressbar' );
		wp_enqueue_style( 'wppp_admin' );

		$results_key = isset( $_GET['results_key'] ) ? sanitize_text_field( wp_unslash( $_GET['results_key'] ) ) : null;
		$trial_key = isset( $_GET['trial_key'] ) ? sanitize_text_field( wp_unslash( $_GET['trial_key'] ) ) : null;
		$import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : null;
		$results     = null;
		$trial_results = null;
		if ( $results_key ) {
			$results = get_option( 'wppp_import_results_' . $results_key );
			// delete_option( 'bhs_import_results_' . $results_key );
		}
		if ( $trial_key ) {
			$trial_results = get_option( 'wppp_trial_results_' . $trial_key );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import PastPerfect Records', 'wp-pastperfect' ); ?></h1>

			<?php if ( $import_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Import Error:', 'wp-pastperfect' ); ?></strong> <?php echo esc_html( $import_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $trial_results ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'Trial Run - No Records Were Saved', 'wp-pastperfect' ); ?></strong></p>
				</div>

				<h2><?php esc_html_e( 'Trial Import Results', 'wp-pastperfect' ); ?></h2>

				<div class="wppp-trial-summary">
					<h3><?php esc_html_e( 'Summary', 'wp-pastperfect' ); ?></h3>
					<table class="widefat">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'Total Records in XML:', 'wp-pastperfect' ); ?></th>
								<td><strong><?php echo esc_html( $trial_results['total'] ); ?></strong></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Would Be Created:', 'wp-pastperfect' ); ?></th>
								<td style="color: #46b450;"><strong><?php echo esc_html( count( $trial_results['would_create'] ) ); ?></strong></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Would Be Updated:', 'wp-pastperfect' ); ?></th>
								<td style="color: #00a0d2;"><strong><?php echo esc_html( count( $trial_results['would_update'] ) ); ?></strong></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Would Fail:', 'wp-pastperfect' ); ?></th>
								<td style="color: #dc3232;"><strong><?php echo esc_html( count( $trial_results['would_fail'] ) ); ?></strong></td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php if ( $trial_results['would_create'] ) : ?>
					<h3><?php esc_html_e( 'Records That Would Be Created:', 'wp-pastperfect' ); ?></h3>
					<pre class="wppp-import-results"><?php
						foreach ( $trial_results['would_create'] as $id ) {
							echo esc_html( $id ) . "\n";
						}
					?></pre>
				<?php endif; ?>

				<?php if ( $trial_results['would_update'] ) : ?>
					<h3><?php esc_html_e( 'Records That Would Be Updated:', 'wp-pastperfect' ); ?></h3>
					<pre class="wppp-import-results"><?php
						foreach ( $trial_results['would_update'] as $id ) {
							echo esc_html( $id ) . "\n";
						}
					?></pre>
				<?php endif; ?>

				<?php if ( $trial_results['would_fail'] ) : ?>
					<h3><?php esc_html_e( 'Records That Would Fail:', 'wp-pastperfect' ); ?></h3>
					<pre class="wppp-import-results"><?php
						foreach ( $trial_results['would_fail'] as $failure ) {
							printf(
								'Record #%d - %s: %s' . "\n",
								esc_html( $failure['record_num'] ),
								esc_html( $failure['id'] ),
								esc_html( $failure['reason'] )
							);
						}
					?></pre>
				<?php endif; ?>

				<?php if ( ! empty( $trial_results['skipped'] ) ) : ?>
					<h3><?php esc_html_e( 'Records Skipped (No Identifier):', 'wp-pastperfect' ); ?></h3>
					<pre class="wppp-import-results"><?php
						foreach ( $trial_results['skipped'] as $skipped ) {
							printf(
								'Record #%d - %d elements - %s' . "\n",
								esc_html( $skipped['record_num'] ),
								esc_html( $skipped['elements'] ),
								esc_html( $skipped['reason'] )
							);
						}
					?></pre>
				<?php endif; ?>

				<?php if ( ! empty( $trial_results['log'] ) ) : ?>
					<h3><?php esc_html_e( 'Processing Log:', 'wp-pastperfect' ); ?></h3>
					<pre class="wppp-import-log"><?php
						foreach ( $trial_results['log'] as $log_entry ) {
							echo esc_html( $log_entry ) . "\n";
						}
					?></pre>
				<?php endif; ?>

				<style type="text/css">
					pre.wppp-import-results {
						width: 400px;
						height: 100px;
						overflow: scroll;
						background: #fff;
						padding: 5px;
						border: 1px solid #ddd;
					}
					pre.wppp-import-log {
						width: 600px;
						height: 150px;
						overflow: scroll;
						background: #f5f5f5;
						padding: 10px;
						border: 1px solid #ddd;
						font-family: monospace;
						font-size: 12px;
					}
					.wppp-trial-summary {
						margin: 20px 0;
					}
					.wppp-trial-summary table {
						max-width: 500px;
					}
					.wppp-trial-summary th {
						width: 200px;
					}
				</style>

				<p class="submit">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wppp_record&page=wppp-import-records' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Import This File For Real', 'wp-pastperfect' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wppp_record&page=wppp-import-records' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Upload a Different File', 'wp-pastperfect' ); ?></a>
				</p>

			<?php elseif ( $results ) : ?>
				<h2>Results</h2>

				<?php if ( $results['created'] ) : ?>
				<p><?php esc_html_e( 'The following records were created:', 'wp-pastperfect' ); ?></p>
					<pre class="wppp-import-results"><?php
						foreach ( $results['created'] as $created ) {
							echo esc_html( $created ) . "\n";
						}
					?></pre>
				<?php endif; ?>

				<?php if ( $results['updated'] ) : ?>
				<p><?php esc_html_e( 'The following records were updated:', 'wp-pastperfect' ); ?></p>
					<pre class="wppp-import-results"><?php
						foreach ( $results['updated'] as $updated ) {
							echo esc_html( $updated ) . "\n";
						}
					?></pre>
				<?php endif; ?>

				<?php if ( $results['failed'] ) : ?>
				<p><?php esc_html_e( 'The following records could not be processed:', 'wp-pastperfect' ); ?></p>
					<pre class="wppp-import-results"><?php
						foreach ( $results['failed'] as $failed ) {
							echo esc_html( $failed ) . "\n";
						}
					?></pre>
				<?php endif; ?>

				<style type="text/css">
					pre.wppp-import-results {
						width: 400px;
						height: 100px;
						overflow: scroll;
						background: #fff;
						padding: 5px;
					}
				</style>

				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wppp_record&page=wppp-import-records' ) ); ?>"><?php esc_html_e( '<<< Import another set of records', 'wp-pastperfect' ); ?></a>

			<?php else : ?>
				<form action="" method="post" enctype="multipart/form-data">
					<p><?php esc_html_e( 'Upload a PastPerfect-generated XML file to begin the import process.', 'wp-pastperfect' ); ?></p>
					<p class="description">
					</p>
					<input type="file" name="wppp-xml" id="wppp-xml" accept=".xml" />

					<p>
						<label>
							<input type="checkbox" name="wppp-trial-run" id="wppp-trial-run" value="1" />
							<?php esc_html_e( 'Trial Run (preview import without saving records)', 'wp-pastperfect' ); ?>
						</label>
					</p>

					<p class="submit">
						<input type="submit" id="wppp-import-submit" class="button button-primary" value="<?php esc_attr_e( 'Begin Import', 'wp-pastperfect' ); ?>" />
					</p>

					<div id="wppp-error" style="display: none;"></div>
					<div id="wppp-success" style="display: none;">
						<div id="wppp-import-progressbar"></div>
						<div id="wppp-import-message"></div>
					</div>

					<?php wp_nonce_field( 'wppp-import', 'wppp-import-nonce', false ); ?>
				</form>
			<?php endif; ?>

		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Check if the current page is the import page.
	 *
	 * @return bool
	 */
	protected function is_import_page() {
		global $pagenow;

		return 'edit.php' === $pagenow
			&& isset( $_GET['post_type'] )
			&& 'wppp_record' === sanitize_text_field( wp_unslash( $_GET['post_type'] ) )
			&& isset( $_GET['page'] )
			&& 'wppp-import-records' === sanitize_text_field( wp_unslash( $_GET['page'] ) );
	}
}