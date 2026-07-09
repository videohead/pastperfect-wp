<?php

namespace PastPerfect\Archive;

/**
 * Entrance class for admin functionality.
 *
 * @since 1.0.0
 */
class Admin {
	/**
	 * Register CSS and JS assets.
	 */
	public function register_assets(): void {
		wp_register_script(
			'ppwp_admin',
			ppwp_plugin_url . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-progressbar' ),
			ppwp_version,
			true
		);

		wp_register_style(
			'pastperfect-jquery-ui-progressbar',
			ppwp_plugin_url . 'assets/css/jquery-ui.min.css',
			array(),
			ppwp_version
		);

		wp_register_style(
			'ppwp_admin',
			ppwp_plugin_url . 'assets/css/admin.css',
			array(),
			ppwp_version
		);
	}

	/**
	 * Hook into WP.
	 */
	public function set_up_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_ppwp_import_upload', array( $this, 'process_ajax_submit' ) );
		add_action( 'wp_ajax_ppwp_import_chunk', array( $this, 'process_ajax_chunk' ) );

		add_filter( 'manage_ppwp_record_posts_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_edit-ppwp_record_sortable_columns', array( $this, 'add_sortable_column' ) );
		add_action( 'manage_ppwp_record_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
	}

	/**
	 * Adds an 'updated' column to the list table.
	 *
	 * @param array $columns Column IDs and labels.
	 */
	public function add_column( array $columns ): array {
		$columns['updated'] = __( 'Last Updated', 'pastperfect-wp' );
		return $columns;
	}

	/**
	 * Specifies that 'updated' is a sortable column.
	 *
	 * @param array $columns Array of sortable columns.
	 */
	public function add_sortable_column( array $columns ): array {
		$columns['updated'] = 'modified';
		return $columns;
	}

	/**
	 * Specifies custom column content.
	 */
	public function custom_column_content( string $column, int $post_id ): void {
		if ( 'updated' !== $column ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		echo esc_html( get_date_from_gmt( $post->post_modified_gmt, 'Y/m/d H:i:s' ) );
	}

	/**
	 * Handle postbacks on the import/sync admin screen.
	 */
	public function handle_admin_actions(): void {
		if ( ! $this->is_import_page() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['pastperfect-sync-save'] ) ) {
			check_admin_referer( 'pastperfect-sync-settings', 'pastperfect-sync-settings-nonce' );

			$settings_input = array(
				'enabled' => ! empty( $_POST['pastperfect-sync-enabled'] ),
				'recurrence' => isset( $_POST['pastperfect-sync-recurrence'] ) ? wp_unslash( $_POST['pastperfect-sync-recurrence'] ) : 'daily',
				'source' => isset( $_POST['pastperfect-sync-source'] ) ? wp_unslash( $_POST['pastperfect-sync-source'] ) : '',
				'source_provider' => isset( $_POST['pastperfect-sync-source-provider'] ) ? wp_unslash( $_POST['pastperfect-sync-source-provider'] ) : 'xml',
				'increment' => isset( $_POST['pastperfect-sync-increment'] ) ? wp_unslash( $_POST['pastperfect-sync-increment'] ) : 10,
				'import_media' => ! empty( $_POST['pastperfect-import-media'] ),
				'media_provider' => isset( $_POST['pastperfect-media-provider'] ) ? wp_unslash( $_POST['pastperfect-media-provider'] ) : 'wp_media_library',
				'media_source_directory' => isset( $_POST['pastperfect-media-source-directory'] ) ? wp_unslash( $_POST['pastperfect-media-source-directory'] ) : '',
				'media_remote_base_url' => isset( $_POST['pastperfect-media-remote-base-url'] ) ? wp_unslash( $_POST['pastperfect-media-remote-base-url'] ) : '',
				'media_index_refresh_enabled' => ! empty( $_POST['pastperfect-media-index-refresh-enabled'] ),
				'media_index_refresh_recurrence' => isset( $_POST['pastperfect-media-index-refresh-recurrence'] ) ? wp_unslash( $_POST['pastperfect-media-index-refresh-recurrence'] ) : 'daily',
			);

			SyncCoordinator::update_settings( $settings_input );
			$this->safe_redirect_with_notice( 'sync-saved' );
		}

		if ( isset( $_POST['pastperfect-sync-run-now'] ) ) {
			check_admin_referer( 'pastperfect-sync-run-now', 'pastperfect-sync-run-now-nonce' );

			$started = SyncCoordinator::start_job( 'manual', false );
			if ( is_wp_error( $started ) ) {
				$this->safe_redirect_with_notice( 'sync-error', $started->get_error_message() );
			}

			$this->safe_redirect_with_notice( 'sync-started' );
		}

		if ( ! empty( $_FILES['pastperfect-xml'] ) ) {
			check_admin_referer( 'pastperfect-import', 'pastperfect-import-nonce' );

			$success = $this->process_import( $_FILES['pastperfect-xml'] );
			$redirect_to = admin_url( 'edit.php?post_type=ppwp_record&page=pastperfect-import-records&results_key=' . urlencode( (string) $success ) );
			wp_safe_redirect( $redirect_to );
			exit;
		}
	}

	/**
	 * Register admin menus.
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ppwp_record',
			__( 'Import PastPerfect Records', 'pastperfect-wp' ),
			__( 'Import', 'pastperfect-wp' ),
			'manage_options',
			'pastperfect-import-records',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Render Import page.
	 */
	public function render_import_page(): void {
		wp_enqueue_script( 'ppwp_admin' );
		wp_enqueue_style( 'pastperfect-jquery-ui-progressbar' );
		wp_enqueue_style( 'ppwp_admin' );

		$results_key = isset( $_GET['results_key'] ) ? sanitize_text_field( wp_unslash( $_GET['results_key'] ) ) : '';
		$results = $results_key ? get_option( 'pastperfect_import_results_' . $results_key ) : null;
		$sync_settings = SyncCoordinator::get_settings();
		$sync_status = get_option( SyncCoordinator::OPTION_JOB_STATE, array() );
		$schedules = wp_get_schedules();
		$media_provider_options = array(
			'wp_media_library' => __( 'WordPress Media Library (default)', 'pastperfect-wp' ),
			'aws_s3' => __( 'AWS S3 (URL mapping)', 'pastperfect-wp' ),
			'google_cloud_storage' => __( 'Google Cloud Storage (URL mapping)', 'pastperfect-wp' ),
			'google_drive' => __( 'Google Drive (URL mapping)', 'pastperfect-wp' ),
		);
		$source_provider_options = apply_filters( 'ppwp_sync_source_providers', array( 'xml' => __( 'XML file', 'pastperfect-wp' ) ) );
		if ( ! is_array( $source_provider_options ) || empty( $source_provider_options ) ) {
			$source_provider_options = array( 'xml' => __( 'XML file', 'pastperfect-wp' ) );
		}
		$media_source_warning = $this->get_media_source_directory_warning( $sync_settings );
		$media_index_status = MediaIndex::get_source_status( (string) $sync_settings['media_source_directory'] );
		$current_schedule = wp_next_scheduled( SyncCoordinator::EVENT_RECURRING_START );
		$current_index_schedule = wp_next_scheduled( SyncCoordinator::EVENT_MEDIA_INDEX_REFRESH );
		$notice = isset( $_GET['pastperfect_notice'] ) ? sanitize_key( wp_unslash( $_GET['pastperfect_notice'] ) ) : '';
		$notice_text = isset( $_GET['pastperfect_notice_text'] ) ? sanitize_text_field( wp_unslash( $_GET['pastperfect_notice_text'] ) ) : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import PastPerfect Records', 'pastperfect-wp' ); ?></h1>

			<?php if ( $notice ) : ?>
				<?php
				$notice_class = 'notice-info';
				$message = '';
				if ( 'sync-saved' === $notice ) {
					$message = __( 'Scheduled sync settings were saved.', 'pastperfect-wp' );
				} elseif ( 'sync-started' === $notice ) {
					$message = __( 'Manual sync started. WP-Cron will continue processing in the background.', 'pastperfect-wp' );
				} elseif ( 'sync-error' === $notice ) {
					$notice_class = 'notice-error';
					$message = $notice_text ? $notice_text : __( 'Could not start sync job.', 'pastperfect-wp' );
				}
				?>
				<?php if ( $message ) : ?>
					<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( '' !== $media_source_warning ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $media_source_warning ); ?></p></div>
			<?php endif; ?>

			<div class="pastperfect-sync-card">
				<h2><?php esc_html_e( 'Scheduled Sync (WP-Cron)', 'pastperfect-wp' ); ?></h2>
				<p><?php esc_html_e( 'Use this to run regular chunked sync imports from a URL or local XML file path.', 'pastperfect-wp' ); ?></p>

				<form action="" method="post">
					<?php wp_nonce_field( 'pastperfect-sync-settings', 'pastperfect-sync-settings-nonce', false ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable scheduled sync', 'pastperfect-wp' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="pastperfect-sync-enabled" value="1" <?php checked( ! empty( $sync_settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Run import on a recurring WP-Cron schedule', 'pastperfect-wp' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-sync-source-provider"><?php esc_html_e( 'Source provider', 'pastperfect-wp' ); ?></label></th>
							<td>
								<select name="pastperfect-sync-source-provider" id="pastperfect-sync-source-provider">
									<?php foreach ( $source_provider_options as $provider_key => $provider_label ) : ?>
										<option value="<?php echo esc_attr( (string) $provider_key ); ?>" <?php selected( (string) $sync_settings['source_provider'], (string) $provider_key ); ?>>
											<?php echo esc_html( (string) $provider_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-sync-source"><?php esc_html_e( 'Source', 'pastperfect-wp' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" name="pastperfect-sync-source" id="pastperfect-sync-source" value="<?php echo esc_attr( (string) $sync_settings['source'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Provide an absolute file path or https URL to the configured source provider.', 'pastperfect-wp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-sync-recurrence"><?php esc_html_e( 'Recurrence', 'pastperfect-wp' ); ?></label></th>
							<td>
								<select name="pastperfect-sync-recurrence" id="pastperfect-sync-recurrence">
									<?php foreach ( $schedules as $schedule_key => $schedule_data ) : ?>
										<option value="<?php echo esc_attr( $schedule_key ); ?>" <?php selected( $sync_settings['recurrence'], $schedule_key ); ?>>
											<?php echo esc_html( $schedule_data['display'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-sync-increment"><?php esc_html_e( 'Chunk size', 'pastperfect-wp' ); ?></label></th>
							<td>
								<input type="number" min="1" max="200" name="pastperfect-sync-increment" id="pastperfect-sync-increment" value="<?php echo esc_attr( (string) $sync_settings['increment'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Number of records processed per cron step.', 'pastperfect-wp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Import media files', 'pastperfect-wp' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="pastperfect-import-media" value="1" <?php checked( ! empty( $sync_settings['import_media'] ) ); ?> />
									<?php esc_html_e( 'Resolve and import media referenced by Dublin Core relation entries', 'pastperfect-wp' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-media-provider"><?php esc_html_e( 'Media destination', 'pastperfect-wp' ); ?></label></th>
							<td>
								<select name="pastperfect-media-provider" id="pastperfect-media-provider">
									<?php foreach ( $media_provider_options as $provider_key => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $sync_settings['media_provider'], $provider_key ); ?>>
											<?php echo esc_html( $provider_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'For cloud providers, files are mapped to URLs using the base URL below; upload/transfer is handled outside this plugin.', 'pastperfect-wp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-media-source-directory"><?php esc_html_e( 'Local media source folder', 'pastperfect-wp' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" name="pastperfect-media-source-directory" id="pastperfect-media-source-directory" value="<?php echo esc_attr( (string) $sync_settings['media_source_directory'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Absolute server path to the folder that contains files referenced in the XML relation field.', 'pastperfect-wp' ); ?></p>
								<p class="description">
									<?php
									printf(
										/* translators: 1: file count, 2: datetime or fallback text */
										esc_html__( 'Media index status: %1$d indexed files. Last update: %2$s.', 'pastperfect-wp' ),
										absint( $media_index_status['indexed_files'] ?? 0 ),
										esc_html( (string) ( $media_index_status['last_updated_local'] ?: __( 'never', 'pastperfect-wp' ) ) )
									);
									?>
								</p>
								<p class="description"><?php esc_html_e( 'Build or refresh index via WP-CLI: wp ppwp media-index --source=/absolute/path', 'pastperfect-wp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-refresh media index', 'pastperfect-wp' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="pastperfect-media-index-refresh-enabled" value="1" <?php checked( ! empty( $sync_settings['media_index_refresh_enabled'] ) ); ?> />
									<?php esc_html_e( 'Run incremental media indexing on a dedicated WP-Cron schedule (disabled by default)', 'pastperfect-wp' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-media-index-refresh-recurrence"><?php esc_html_e( 'Media index refresh recurrence', 'pastperfect-wp' ); ?></label></th>
							<td>
								<select name="pastperfect-media-index-refresh-recurrence" id="pastperfect-media-index-refresh-recurrence">
									<?php foreach ( $schedules as $schedule_key => $schedule_data ) : ?>
										<option value="<?php echo esc_attr( $schedule_key ); ?>" <?php selected( $sync_settings['media_index_refresh_recurrence'], $schedule_key ); ?>>
											<?php echo esc_html( $schedule_data['display'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( $current_index_schedule ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: 1: recurrence key, 2: localized datetime */
											esc_html__( 'Next media index refresh (%1$s): %2$s', 'pastperfect-wp' ),
											esc_html( (string) wp_get_schedule( SyncCoordinator::EVENT_MEDIA_INDEX_REFRESH ) ),
											esc_html( wp_date( 'Y-m-d H:i:s', (int) $current_index_schedule ) )
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-media-remote-base-url"><?php esc_html_e( 'Remote media base URL', 'pastperfect-wp' ); ?></label></th>
							<td>
								<input type="url" class="regular-text" name="pastperfect-media-remote-base-url" id="pastperfect-media-remote-base-url" value="<?php echo esc_attr( (string) $sync_settings['media_remote_base_url'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Required for AWS S3, Google Cloud Storage, or Google Drive URL mapping. Example: https://cdn.example.org/archive-media', 'pastperfect-wp' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary" name="pastperfect-sync-save" value="1"><?php esc_html_e( 'Save Scheduled Sync Settings', 'pastperfect-wp' ); ?></button>
					</p>
				</form>

				<form action="" method="post">
					<?php wp_nonce_field( 'pastperfect-sync-run-now', 'pastperfect-sync-run-now-nonce', false ); ?>
					<p>
						<button type="submit" class="button button-secondary" name="pastperfect-sync-run-now" value="1"><?php esc_html_e( 'Run Sync Now', 'pastperfect-wp' ); ?></button>
					</p>
				</form>

				<?php if ( $current_schedule ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: recurrence key, 2: localized datetime */
							esc_html__( 'Next scheduled sync (%1$s): %2$s', 'pastperfect-wp' ),
							esc_html( (string) wp_get_schedule( SyncCoordinator::EVENT_RECURRING_START ) ),
							esc_html( wp_date( 'Y-m-d H:i:s', (int) $current_schedule ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( is_array( $sync_status ) && ! empty( $sync_status['status'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: status, 2: progress */
							esc_html__( 'Current sync status: %1$s (%2$d/%3$d)', 'pastperfect-wp' ),
							esc_html( (string) $sync_status['status'] ),
							absint( $sync_status['last'] ?? 0 ),
							absint( $sync_status['count'] ?? 0 )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $results ) : ?>
				<h2><?php esc_html_e( 'Results', 'pastperfect-wp' ); ?></h2>

				<?php if ( ! empty( $results['created'] ) ) : ?>
					<p><?php esc_html_e( 'The following records were created:', 'pastperfect-wp' ); ?></p>
					<pre class="pastperfect-import-results"><?php foreach ( $results['created'] as $created ) { echo esc_html( $created ) . "\n"; } ?></pre>
				<?php endif; ?>

				<?php if ( ! empty( $results['updated'] ) ) : ?>
					<p><?php esc_html_e( 'The following records were updated:', 'pastperfect-wp' ); ?></p>
					<pre class="pastperfect-import-results"><?php foreach ( $results['updated'] as $updated ) { echo esc_html( $updated ) . "\n"; } ?></pre>
				<?php endif; ?>

				<?php if ( ! empty( $results['failed'] ) ) : ?>
					<p><?php esc_html_e( 'The following records could not be processed:', 'pastperfect-wp' ); ?></p>
					<pre class="pastperfect-import-results"><?php foreach ( $results['failed'] as $failed ) { echo esc_html( $failed ) . "\n"; } ?></pre>
				<?php endif; ?>

				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ppwp_record&page=pastperfect-import-records' ) ); ?>"><?php esc_html_e( '<<< Import another set of records', 'pastperfect-wp' ); ?></a>
			<?php else : ?>
				<form action="" method="post" enctype="multipart/form-data">
					<p><?php esc_html_e( 'Upload a PastPerfect-generated XML file to begin the import process.', 'pastperfect-wp' ); ?></p>
					<input type="file" name="pastperfect-xml" id="pastperfect-xml" />

					<p class="submit">
						<input type="submit" id="pastperfect-import-submit" class="button button-secondary" value="<?php esc_attr_e( 'Begin Import', 'pastperfect-wp' ); ?>" />
					</p>

					<div id="pastperfect-error" class="pastperfect-hidden"></div>
					<div id="pastperfect-success" class="pastperfect-hidden">
						<div id="pastperfect-import-progressbar"></div>
						<div id="pastperfect-import-message"></div>
					</div>

					<?php wp_nonce_field( 'pastperfect-import', 'pastperfect-import-nonce', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	protected function is_import_page(): bool {
		global $pagenow;

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'edit.php' === $pagenow
			&& 'ppwp_record' === $post_type
			&& 'pastperfect-import-records' === $page;
	}

	/**
	 * Legacy non-AJAX full import.
	 *
	 * @param array $file Uploaded file array.
	 */
	protected function process_import( array $file ): int {
		$time = time();
		$results = array(
			'created' => array(),
			'updated' => array(),
			'failed' => array(),
		);

		$run = self::create_import_run_from_file( $file['tmp_name'] ?? '', SyncCoordinator::get_settings() );
		if ( is_wp_error( $run ) ) {
			update_option( 'pastperfect_import_results_' . $time, $results, false );
			return $time;
		}

		$run_data = self::get_run_data( (string) $run['run'] );
		if ( ! is_array( $run_data ) ) {
			update_option( 'pastperfect_import_results_' . $time, $results, false );
			return $time;
		}

		$current = 0;
		$count = absint( $run_data['count'] );
		while ( $current < $count ) {
			$chunk = self::process_import_chunk_data( $run_data, $current, 100 );
			$current = absint( $chunk['current'] );
			$run_data = self::get_run_data( (string) $run['run'] );

			foreach ( $chunk['results'] as $result ) {
				$id = $result['identifier'] ?? '';
				if ( ! $id ) {
					continue;
				}

				if ( 'created' === $result['status'] ) {
					$results['created'][] = $id;
				} elseif ( 'updated' === $result['status'] ) {
					$results['updated'][] = $id;
				} else {
					$results['failed'][] = $id;
				}
			}
		}

		update_option( 'pastperfect_import_results_' . $time, $results, false );
		return $time;
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'pastperfect-dc-metadata',
			__( 'Dublin Core Metadata', 'pastperfect-wp' ),
			array( $this, 'render_meta_box' ),
			'ppwp_record'
		);
	}

	/**
	 * @param \WP_Post $post Post object.
	 */
	public function render_meta_box( $post ): void {
		echo '<table class="form-table">';
		$record = new Record( $post->ID );
		foreach ( Record::get_dc_elements() as $element ) {
			$all_values = $record->get_dc_metadata( $element, false );
			$values_formatted = array();
			foreach ( (array) $all_values as $key => $value ) {
				if ( is_array( $value ) ) {
					$this_item = '<dl>';
					$this_item .= sprintf(
						'<dt>%s</dt><dd>%s</dd>',
						esc_html( (string) $key ),
						implode( "\n", array_map( 'esc_html', $value ) )
					);
					$this_item .= '</dl>';
					$values_formatted[] = '<p>' . $this_item . '</p>';
				} else {
					$value = esc_html( (string) $value );
					$values_formatted[] = wpautop( $value );
				}
			}

			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $element ),
				implode( "\n", $values_formatted )
			);
		}
		echo '</table>';
	}

	public function process_ajax_submit(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'pastperfect-wp' ), 403 );
		}

		$nonce = isset( $_POST['pastperfect-import-nonce'] ) ? wp_unslash( $_POST['pastperfect-import-nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pastperfect-import' ) ) {
			wp_send_json_error( __( 'Security check failed. Please reload and try again.', 'pastperfect-wp' ), 400 );
		}

		if ( empty( $_FILES ) || empty( $_FILES['file-0']['tmp_name'] ) ) {
			wp_send_json_error( __( 'File could not be uploaded. Check server upload limits.', 'pastperfect-wp' ) );
		}

		$uploads = wp_upload_dir();
		$timestamp = time();
		$dest = trailingslashit( $uploads['basedir'] ) . 'pastperfect-import-' . $timestamp . '.xml';

		$moved = move_uploaded_file( $_FILES['file-0']['tmp_name'], $dest );
		if ( ! $moved ) {
			wp_send_json_error( __( 'File could not be uploaded.', 'pastperfect-wp' ) );
		}

		$run = self::create_import_run_from_file( $dest, SyncCoordinator::get_settings() );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( $run->get_error_message() );
		}

		wp_send_json_success(
			array(
				'run' => $run['run'],
				'pct' => 0,
			)
		);
	}

	public function process_ajax_chunk(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'pastperfect-wp' ), 403 );
		}

		$nonce = isset( $_POST['pastperfect-import-nonce'] ) ? wp_unslash( $_POST['pastperfect-import-nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pastperfect-import' ) ) {
			wp_send_json_error( __( 'Security check failed. Please reload and try again.', 'pastperfect-wp' ), 400 );
		}

		$run = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : '';
		$run_data = self::get_run_data( $run );
		if ( ! $run || ! is_array( $run_data ) ) {
			wp_send_json_error( __( 'Could not find uploaded XML file. Please upload again.', 'pastperfect-wp' ) );
		}

		$chunk = self::process_import_chunk_data( $run_data, absint( $run_data['last'] ?? 0 ), 5 );
		$pct = 0;
		if ( ! empty( $run_data['count'] ) ) {
			$pct = (int) floor( 100 * ( absint( $chunk['current'] ) / absint( $run_data['count'] ) ) );
		}

		wp_send_json_success(
			array(
				'run' => $run,
				'pct' => $pct,
				'results' => $chunk['results'],
			)
		);
	}

	/**
	 * Build or refresh a run from a source XML file.
	 *
	 * @return array|\WP_Error
	 */
	public static function create_import_run_from_file( string $xml_path, array $settings = array() ) {
		if ( empty( $xml_path ) || ! is_readable( $xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_missing', __( 'Could not read XML source file.', 'pastperfect-wp' ) );
		}

		$reader = new \XMLReader();
		if ( ! $reader->open( $xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_open_failed', __( 'Could not open XML source file.', 'pastperfect-wp' ) );
		}

		$record_element = self::find_record_element( $reader );
		if ( ! $record_element ) {
			$reader->close();
			return new \WP_Error( 'pastperfect_no_records', __( 'No supported record elements were found in the XML source.', 'pastperfect-wp' ) );
		}

		$count = 0;
		while ( $record_element === $reader->name ) {
			$count++;
			$reader->next( $record_element );
		}

		$reader->close();

		if ( 0 === $count ) {
			return new \WP_Error( 'pastperfect_no_records', __( 'No records were found in the XML source.', 'pastperfect-wp' ) );
		}

		$run = (string) ( time() . wp_rand( 100, 999 ) );
		$run_key = self::get_run_key( $run );
		$run_data = array(
			'run' => $run,
			'xml' => $xml_path,
			'last' => 0,
			'count' => $count,
			'record_element' => $record_element,
			'import_settings' => self::normalize_import_settings( $settings ),
		);
		update_option( $run_key, $run_data, false );

		return array(
			'run' => $run,
			'run_key' => $run_key,
			'count' => $count,
			'record_element' => $record_element,
		);
	}

	/**
	 * Retrieve run data for a run token.
	 */
	public static function get_run_data( string $run ): ?array {
		$run_data = get_option( self::get_run_key( $run ) );
		if ( ! is_array( $run_data ) ) {
			return null;
		}

		$run_data['run'] = $run;
		return $run_data;
	}

	/**
	 * Process one chunk and update run data in option storage.
	 *
	 * @return array{current:int,results:array<int,array{identifier:string,status:string}>}
	 */
	public static function process_import_chunk_data( array $run_data, int $last, int $increment = 5 ): array {
		$xml_file = $run_data['xml'] ?? '';
		$record_element = $run_data['record_element'] ?? 'record';
		if ( ! $xml_file || ! is_readable( $xml_file ) ) {
			return array(
				'current' => $last,
				'results' => array(
					array(
						'identifier' => '',
						'status' => 'failed',
					),
				),
			);
		}

		$reader = new \XMLReader();
		if ( ! $reader->open( $xml_file ) ) {
			return array(
				'current' => $last,
				'results' => array(
					array(
						'identifier' => '',
						'status' => 'failed',
					),
				),
			);
		}

		$doc = new \DOMDocument();

		while ( $reader->read() && $record_element !== $reader->name ) {
			// No-op, advance reader.
		}

		$results = array();
		$current = 0;

		while ( $record_element === $reader->name ) {
			if ( $current >= ( $last + $increment ) ) {
				break;
			}

			$current++;

			if ( $current <= $last ) {
				$reader->next( $record_element );
				continue;
			}

			$node = simplexml_import_dom( $doc->importNode( $reader->expand(), true ) );
			if ( ! $node ) {
				$results[] = array(
					'identifier' => '',
					'status' => 'failed',
				);
				$reader->next( $record_element );
				continue;
			}

			$parsed = self::build_record_atts_from_node( $node );

			$record = new Record();
			$exists = (bool) $record->get_post_id_by_identifier( $parsed['identifier'] );
			$record->set_up_from_raw_atts( $parsed['atts'] );
			$saved = $record->save();
			if ( $saved ) {
				self::handle_media_for_record(
					absint( $saved ),
					$parsed['atts'],
					is_array( $run_data['import_settings'] ?? null ) ? $run_data['import_settings'] : array()
				);
			}

			$status = 'failed';
			if ( $saved ) {
				$status = $exists ? 'updated' : 'created';
			}

			$results[] = array(
				'identifier' => $parsed['identifier'],
				'status' => $status,
			);

			$reader->next( $record_element );
		}

		$reader->close();

		$run_data['last'] = $current;
		if ( ! empty( $run_data['run'] ) ) {
			update_option( self::get_run_key( (string) $run_data['run'] ), $run_data, false );
		}

		return array(
			'current' => $current,
			'results' => $results,
		);
	}

	/**
	 * Resolve a configured source (URL or path) into a local XML file.
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_sync_source_to_local_file( string $source ) {
		$source = trim( $source );
		if ( '' === $source ) {
			return new \WP_Error( 'pastperfect_sync_source_empty', __( 'XML source is empty.', 'pastperfect-wp' ) );
		}

		if ( wp_http_validate_url( $source ) ) {
			$response = wp_remote_get(
				$source,
				array(
					'timeout' => 60,
					'redirection' => 5,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			if ( 200 !== $code || '' === $body ) {
				return new \WP_Error( 'pastperfect_sync_source_fetch_failed', __( 'Could not fetch XML source URL.', 'pastperfect-wp' ) );
			}

			$uploads = wp_upload_dir();
			$path = trailingslashit( $uploads['basedir'] ) . 'pastperfect-sync-source.xml';
			$written = file_put_contents( $path, $body );
			if ( false === $written ) {
				return new \WP_Error( 'pastperfect_sync_source_write_failed', __( 'Could not write downloaded XML to uploads directory.', 'pastperfect-wp' ) );
			}

			return $path;
		}

		if ( ! is_readable( $source ) ) {
			return new \WP_Error( 'pastperfect_sync_source_not_readable', __( 'Configured XML source path is not readable.', 'pastperfect-wp' ) );
		}

		return $source;
	}

	/**
	 * @param \SimpleXMLElement $node Current XML node.
	 * @return array{identifier:string,atts:array<string,mixed>}
	 */
	private static function build_record_atts_from_node( \SimpleXMLElement $node ): array {
		$atts = array();
		$identifier = '';
		$singular_elements = Record::get_singular_elements();

		foreach ( $node->children() as $field_name => $field_node ) {
			$field_name = (string) $field_name;
			$field_value = (string) $field_node;

			if ( 'identifier' === $field_name && '' === $identifier ) {
				$identifier = $field_value;
			}

			$children = $field_node->children();
			if ( $children && count( $children ) > 0 ) {
				foreach ( $children as $child_key => $child_value ) {
					$atts[ $field_name ][ (string) $child_key ][] = (string) $child_value;
				}
				continue;
			}

			if ( in_array( $field_name, $singular_elements, true ) ) {
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
	 * Normalize media import settings used by manual and scheduled runs.
	 */
	private static function normalize_import_settings( array $settings ): array {
		$provider = isset( $settings['media_provider'] ) ? sanitize_key( (string) $settings['media_provider'] ) : 'wp_media_library';
		$allowed_providers = array( 'wp_media_library', 'aws_s3', 'google_cloud_storage', 'google_drive' );
		if ( ! in_array( $provider, $allowed_providers, true ) ) {
			$provider = 'wp_media_library';
		}

		$media_source_directory = isset( $settings['media_source_directory'] ) ? sanitize_text_field( (string) $settings['media_source_directory'] ) : '';
		if ( '' === $media_source_directory ) {
			$media_source_directory = self::get_default_media_source_directory();
		}

		return array(
			'import_media' => array_key_exists( 'import_media', $settings ) ? ! empty( $settings['import_media'] ) : true,
			'media_provider' => $provider,
			'media_source_directory' => $media_source_directory,
			'media_remote_base_url' => isset( $settings['media_remote_base_url'] ) ? esc_url_raw( (string) $settings['media_remote_base_url'] ) : '',
		);
	}

	/**
	 * Resolve media references for a record and update Dublin Core relation values.
	 */
	private static function handle_media_for_record( int $post_id, array $atts, array $settings ): void {
		$settings = self::normalize_import_settings( $settings );
		if ( empty( $settings['import_media'] ) ) {
			return;
		}

		$references = self::extract_media_references( $atts['relation'] ?? array() );
		$identifier_references = self::infer_media_references_from_identifier(
			isset( $atts['identifier'] ) ? (string) $atts['identifier'] : '',
			$settings['media_source_directory']
		);
		$references = array_values( array_unique( array_merge( $references, $identifier_references ) ) );

		if ( empty( $references ) ) {
			return;
		}

		$relation_values = get_post_meta( $post_id, 'pastperfect_dc_relation', false );
		if ( ! is_array( $relation_values ) ) {
			$relation_values = array();
		}
		$used_source_files = array();

		foreach ( $references as $reference ) {
			$resolved_url = '';

			if ( 'wp_media_library' === $settings['media_provider'] ) {
				$source_file = self::resolve_local_media_path( $reference, $settings['media_source_directory'] );
				if ( $source_file && ! isset( $used_source_files[ $source_file ] ) ) {
					$used_source_files[ $source_file ] = true;
					$resolved_url = self::import_file_to_media_library( $source_file, $post_id );
					if ( is_wp_error( $resolved_url ) ) {
						$resolved_url = '';
					}
				}
			} else {
				$resolved_url = self::map_media_reference_to_remote_url( $reference, $settings['media_remote_base_url'] );
			}

			if ( $resolved_url ) {
				$relation_values[] = $resolved_url;
			}
		}

		$relation_values = array_values( array_unique( array_filter( array_map( 'strval', $relation_values ) ) ) );
		delete_post_meta( $post_id, 'pastperfect_dc_relation' );
		foreach ( $relation_values as $relation_value ) {
			add_post_meta( $post_id, 'pastperfect_dc_relation', $relation_value );
		}
	}

	/**
	 * Collect probable media references from relation values.
	 *
	 * @param mixed $relation_values Dublin Core relation values.
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
	 * Example: identifier 2003.2.2 => 200322.jpg, 200322-2.jpg, ...
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

	private static function resolve_local_media_path( string $reference, string $source_directory ): string {
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

		foreach ( $roots as $root ) {
			$candidate = trailingslashit( $root ) . ltrim( $reference, '/' );
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}

			$basename_candidate = trailingslashit( $root ) . wp_basename( $reference );
			if ( is_readable( $basename_candidate ) ) {
				return $basename_candidate;
			}
		}

		$indexed_match = MediaIndex::find_match_for_reference( $reference, $source_directory );
		if ( '' !== $indexed_match ) {
			return $indexed_match;
		}

		foreach ( $roots as $root ) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}

					if ( wp_basename( $file->getPathname() ) === wp_basename( $reference ) ) {
						return $file->getPathname();
					}
				}
			} catch ( \Throwable $unused ) {
				continue;
			}
		}

		return '';
	}

	/**
	 * Import a local file into the Media Library while disabling year/month subfolders.
	 *
	 * @return string|\WP_Error Media URL or error.
	 */
	private static function import_file_to_media_library( string $source_file, int $post_id ) {
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

		if ( ! empty( $existing ) ) {
			$existing_id = (int) reset( $existing );
			$existing_url = wp_get_attachment_url( $existing_id );
			if ( $existing_url ) {
				return $existing_url;
			}
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

		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( ! $attachment_url ) {
			return new \WP_Error( 'ppwp_media_url_failed', __( 'Media was imported but no attachment URL was generated.', 'pastperfect-wp' ) );
		}

		return $attachment_url;
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

	private static function map_media_reference_to_remote_url( string $reference, string $base_url ): string {
		$reference = trim( str_replace( '\\', '/', $reference ) );
		if ( '' === $reference ) {
			return '';
		}

		if ( wp_http_validate_url( $reference ) ) {
			return $reference;
		}

		$base_url = trim( $base_url );
		if ( '' === $base_url ) {
			return '';
		}

		return trailingslashit( $base_url ) . ltrim( $reference, '/' );
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
	 * Build canonical media search roots from user-provided source directory.
	 *
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

	private static function get_run_key( string $run ): string {
		return 'pastperfect_import_run_' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $run );
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

	private function safe_redirect_with_notice( string $notice, string $notice_text = '' ): void {
		$redirect_to = add_query_arg(
			array_filter(
				array(
					'post_type' => 'ppwp_record',
					'page' => 'pastperfect-import-records',
					'pastperfect_notice' => $notice,
					'pastperfect_notice_text' => $notice_text,
				)
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect_to );
		exit;
	}

	private function get_media_source_directory_warning( array $settings ): string {
		$provider = isset( $settings['media_provider'] ) ? sanitize_key( (string) $settings['media_provider'] ) : 'wp_media_library';
		if ( 'wp_media_library' !== $provider ) {
			return '';
		}

		$source = isset( $settings['media_source_directory'] ) ? trim( (string) $settings['media_source_directory'] ) : '';
		if ( '' === $source ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( untrailingslashit( (string) $uploads['basedir'] ) ) : '';
		$source = wp_normalize_path( untrailingslashit( $source ) );

		if ( '' === $basedir || 0 !== strpos( trailingslashit( $source ), trailingslashit( $basedir ) ) ) {
			if ( ! MediaIndex::has_index_for_source( $source ) ) {
				return __( 'Media index is empty for this source directory. Run: wp ppwp media-index --source=<absolute-path> for faster large-library syncs.', 'pastperfect-wp' );
			}

			return '';
		}

		return __( 'Large media sources should live outside wp-content/uploads for safer backups and better performance. Move the source folder externally and run: wp ppwp media-index --source=<absolute-path>.', 'pastperfect-wp' );
	}
}
