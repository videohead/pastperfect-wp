<?php

namespace PastPerfect\Archive;

/**
 * Entrance class for admin functionality.
 *
 * @since 1.0.0
 */
class Admin {
	private const OPTION_XML_FIELD_MAP = 'pastperfect_xml_field_map';
	private const OPTION_XML_WP_BEHAVIOR = 'pastperfect_xml_wp_behavior';
	private const XML_MAPPING_PAGE_SLUG = 'pastperfect-xml-mapping';
	private const SETUP_PAGE_SLUG = 'pastperfect-setup';

	/**
	 * Register CSS and JS assets.
	 */
	public function register_assets(): void {
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
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );

		add_filter( 'manage_ppwp_record_posts_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_edit-ppwp_record_sortable_columns', array( $this, 'add_sortable_column' ) );
		add_action( 'manage_ppwp_record_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );

		add_filter( 'ppwp_record_post_title', array( $this, 'filter_record_post_title' ), 10, 3 );
		add_filter( 'ppwp_record_post_content', array( $this, 'filter_record_post_content' ), 10, 3 );
		add_filter( 'ppwp_record_taxonomy_elements', array( $this, 'filter_record_taxonomy_elements' ) );
		add_filter( 'ppwp_record_taxonomy_terms', array( $this, 'filter_record_taxonomy_terms' ), 10, 4 );
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
	 * @param array<string,mixed> $dc_metadata
	 */
	public function filter_record_post_title( string $default_title, array $dc_metadata, Record $record ): string {
		unset( $record );

		$behavior = self::get_xml_wp_behavior();
		$title_mode = isset( $behavior['post_title_mode'] ) ? (string) $behavior['post_title_mode'] : 'first_title';

		if ( 'identifier_only' === $title_mode ) {
			return self::extract_first_scalar_value( $dc_metadata['identifier'] ?? '' );
		}

		if ( 'identifier_plus_title' === $title_mode ) {
			$identifier = self::extract_first_scalar_value( $dc_metadata['identifier'] ?? '' );
			$title = self::extract_first_scalar_value( $dc_metadata['title'] ?? '' );
			if ( '' === $identifier ) {
				return $title;
			}

			if ( '' === $title ) {
				return $identifier;
			}

			return $identifier . ' - ' . $title;
		}

		// For 'first_title' mode, use the default_title which may have been enhanced
		// with description snippets for single-word titles.
		if ( '' !== $default_title ) {
			return $default_title;
		}

		// Fallback if default_title is empty
		$title = self::extract_first_scalar_value( $dc_metadata['title'] ?? '' );
		if ( '' !== $title ) {
			return $title;
		}

		return self::extract_first_scalar_value( $dc_metadata['identifier'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $dc_metadata
	 */
	public function filter_record_post_content( string $default_content, array $dc_metadata, Record $record ): string {
		unset( $record );

		$behavior = self::get_xml_wp_behavior();
		$content_field = isset( $behavior['post_content_field'] ) ? (string) $behavior['post_content_field'] : 'description';
		if ( '' === $content_field ) {
			return $default_content;
		}

		$value = self::extract_first_scalar_value( $dc_metadata[ $content_field ] ?? '' );
		if ( '' !== $value ) {
			return $value;
		}

		return $default_content;
	}

	/**
	 * @param array<string,string> $taxonomy_elements
	 * @return array<string,string>
	 */
	public function filter_record_taxonomy_elements( array $taxonomy_elements ): array {
		$behavior = self::get_xml_wp_behavior();
		$type_taxonomy = isset( $behavior['type_taxonomy'] ) ? sanitize_key( (string) $behavior['type_taxonomy'] ) : '';
		if ( '' === $type_taxonomy ) {
			return $taxonomy_elements;
		}

		$taxonomy_elements['type'] = $type_taxonomy;
		return $taxonomy_elements;
	}

	/**
	 * @param mixed $terms
	 * @return array<int|string,string|int>
	 */
	public function filter_record_taxonomy_terms( $terms, string $element, string $taxonomy, Record $record ): array {
		unset( $element );
		return self::merge_terms_with_content_matches( $terms, $taxonomy, self::build_term_detection_source_text( $record ) );
	}

	public static function maybe_auto_tag_post_from_content( int $post_id, string $taxonomy ): void {
		if ( $post_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$source_text = self::build_term_detection_source_text_for_post( $post );
		if ( '' === $source_text ) {
			return;
		}

		$existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		$existing = is_wp_error( $existing ) ? array() : (array) $existing;

		$merged = self::merge_terms_with_content_matches( $existing, $taxonomy, $source_text );
		if ( empty( $merged ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $merged, $taxonomy );
	}

	/**
	 * @return array<int,string>
	 */
	public static function get_auto_tag_taxonomies(): array {
		$behavior = self::get_xml_wp_behavior();
		if ( empty( $behavior['auto_tag_terms_from_content'] ) ) {
			return array();
		}

		$taxonomies = array( 'ppwp_subject' );
		$type_taxonomy = isset( $behavior['type_taxonomy'] ) ? sanitize_key( (string) $behavior['type_taxonomy'] ) : '';
		if ( '' !== $type_taxonomy ) {
			$taxonomies[] = $type_taxonomy;
		}

		$taxonomies = array_values( array_unique( $taxonomies ) );
		return array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );
	}

	/**
	 * @param mixed $terms
	 * @return array<int|string,string|int>
	 */
	private static function merge_terms_with_content_matches( $terms, string $taxonomy, string $source_text ): array {
		$normalized_terms = array();
		foreach ( (array) $terms as $term ) {
			if ( is_int( $term ) ) {
				$normalized_terms[] = $term;
				continue;
			}

			$term = trim( (string) $term );
			if ( '' !== $term ) {
				$normalized_terms[] = $term;
			}
		}

		$behavior = self::get_xml_wp_behavior();
		if ( empty( $behavior['auto_tag_terms_from_content'] ) || ! taxonomy_exists( $taxonomy ) || '' === $source_text ) {
			return array_values( array_unique( $normalized_terms, SORT_REGULAR ) );
		}

		$matches = self::detect_taxonomy_terms_in_text( $taxonomy, $source_text );
		if ( empty( $matches ) ) {
			return array_values( array_unique( $normalized_terms, SORT_REGULAR ) );
		}

		return array_values( array_unique( array_merge( $normalized_terms, $matches ), SORT_REGULAR ) );
	}

	private static function build_term_detection_source_text( Record $record ): string {
		$title = self::extract_first_scalar_value( $record->get_dc_metadata( 'title' ) );
		$description = self::extract_first_scalar_value( $record->get_dc_metadata( 'description' ) );

		return self::implode_term_detection_parts( array( $title, $description ) );
	}

	private static function build_term_detection_source_text_for_post( \WP_Post $post ): string {
		$title = (string) $post->post_title;
		$description_values = get_post_meta( $post->ID, 'pastperfect_dc_description', false );
		$description = '';
		if ( is_array( $description_values ) && ! empty( $description_values ) ) {
			$description = self::extract_first_scalar_value( $description_values );
		}
		if ( '' === $description ) {
			$description = (string) $post->post_content;
		}

		return self::implode_term_detection_parts( array( $title, $description ) );
	}

	private static function implode_term_detection_parts( array $parts ): string {
		$parts = array_filter(
			$parts,
			static function ( $value ): bool {
				return '' !== trim( (string) $value );
			}
		);

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * @return array<int,string>
	 */
	private static function detect_taxonomy_terms_in_text( string $taxonomy, string $text ): array {
		static $term_cache = array();

		$text = self::normalize_term_match_text( $text );
		if ( '' === $text ) {
			return array();
		}

		if ( ! isset( $term_cache[ $taxonomy ] ) ) {
			$terms = get_terms(
				array(
					'taxonomy' => $taxonomy,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				$term_cache[ $taxonomy ] = array();
			} else {
				$candidates = array();
				foreach ( $terms as $term ) {
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}

					$name = trim( (string) $term->name );
					if ( mb_strlen( $name ) < 3 ) {
						continue;
					}

					$candidates[] = $name;
				}

				usort(
					$candidates,
					static function ( string $a, string $b ): int {
						return mb_strlen( $b ) <=> mb_strlen( $a );
					}
				);

				$term_cache[ $taxonomy ] = $candidates;
			}
		}

		$matches = array();
		foreach ( $term_cache[ $taxonomy ] as $term_name ) {
			$needle = self::normalize_term_match_text( $term_name );
			if ( '' === $needle ) {
				continue;
			}

			$pattern = '/(^|[^[:alnum:]])' . preg_quote( $needle, '/' ) . '([^[:alnum:]]|$)/ui';
			if ( 1 === preg_match( $pattern, $text ) ) {
				$matches[] = $term_name;
			}
		}

		return $matches;
	}

	private static function normalize_term_match_text( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = remove_accents( $value );
		$value = mb_strtolower( $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( $value );
	}

	/**
	 * Print sync notice markup.
	 */
	private function print_sync_notice( string $notice, string $notice_text ): void {
		if ( ! $notice ) {
			return;
		}

		$notice_class = 'notice-info';
		$message = '';

		if ( 'sync-saved' === $notice ) {
			$message = __( 'Scheduled sync settings were saved.', 'pastperfect-wp' );
		} elseif ( 'sync-started' === $notice ) {
			$message = __( 'Manual sync started. WP-Cron will continue processing in the background.', 'pastperfect-wp' );
		} elseif ( 'sync-stopped' === $notice ) {
			$message = __( 'Running sync was stopped. Pending chunk jobs were cleared.', 'pastperfect-wp' );
		} elseif ( 'sync-error' === $notice ) {
			$notice_class = 'notice-error';
			$message = $notice_text ?: __( 'Could not start sync job.', 'pastperfect-wp' );
		}

		if ( $message ) {
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice_class ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Print setup page notice.
	 */
	private function print_setup_notice( string $notice ): void {
		if ( 'saved' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Setup settings saved. Permalinks were refreshed.', 'pastperfect-wp' )
			);
		}
	}

	/**
	 * Print XML mapping page notice.
	 */
	private function print_xml_mapping_notice( string $notice ): void {
		if ( 'saved' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'XML mapping saved.', 'pastperfect-wp' )
			);
		} elseif ( 'reset' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'XML mapping reset to defaults.', 'pastperfect-wp' )
			);
		}
	}

	/**
	 * Print import results (created, updated, failed records).
	 */
	private function print_import_results( array $results ): void {
		echo '<h2>' . esc_html__( 'Results', 'pastperfect-wp' ) . '</h2>';

		if ( ! empty( $results['created'] ) ) {
			$this->print_result_section( 'created', $results['created'] );
		}

		if ( ! empty( $results['updated'] ) ) {
			$this->print_result_section( 'updated', $results['updated'] );
		}

		if ( ! empty( $results['failed'] ) ) {
			$this->print_result_section( 'failed', $results['failed'] );
		}

		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?post_type=ppwp_record&page=pastperfect-import-records' ) ),
			esc_html__( '<<< Import another set of records', 'pastperfect-wp' )
		);
	}

	/**
	 * Print a single result section (created/updated/failed).
	 */
	private function print_result_section( string $type, array $items ): void {
		switch ( $type ) {
			case 'created':
				$label = __( 'The following records were created:', 'pastperfect-wp' );
				break;
			case 'updated':
				$label = __( 'The following records were updated:', 'pastperfect-wp' );
				break;
			case 'failed':
				$label = __( 'The following records could not be processed:', 'pastperfect-wp' );
				break;
			default:
				return;
		}

		echo '<p>' . esc_html( $label ) . '</p>';
		echo '<pre class="pastperfect-import-results">';
		foreach ( $items as $item ) {
			echo esc_html( $item ) . "\n";
		}
		echo '</pre>';
	}

	/**
	 * Handle postbacks on the import/sync admin screen.
	 */
	public function handle_admin_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->is_setup_page() ) {
			$this->handle_setup_actions();
			return;
		}

		if ( $this->is_xml_mapping_page() ) {
			$this->handle_xml_mapping_actions();
			return;
		}

		if ( ! $this->is_import_page() ) {
			return;
		}

		if ( isset( $_POST['pastperfect-sync-save'] ) ) {
			check_admin_referer( 'pastperfect-sync-settings', 'pastperfect-sync-settings-nonce' );

			$settings_input = $this->get_sync_settings_input_from_post();

			SyncCoordinator::update_settings( $settings_input );
			$this->safe_redirect_with_notice( 'sync-saved' );
		}

		if ( isset( $_POST['pastperfect-sync-simulate'] ) ) {
			check_admin_referer( 'pastperfect-sync-settings', 'pastperfect-sync-settings-nonce' );

			$settings_input = $this->get_sync_settings_input_from_post();
			$simulation = self::simulate_import_source( $settings_input );
			if ( is_wp_error( $simulation ) ) {
				$this->safe_redirect_with_notice( 'sync-error', $simulation->get_error_message() );
			}

			$key = (string) ( time() . wp_rand( 100, 999 ) );
			set_transient( 'pastperfect_simulation_results_' . $key, $simulation, HOUR_IN_SECONDS );
			$redirect_to = add_query_arg(
				array(
					'post_type' => 'ppwp_record',
					'page' => 'pastperfect-import-records',
					'simulation_key' => $key,
				),
				admin_url( 'edit.php' )
			);
			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( isset( $_POST['pastperfect-sync-run-now'] ) ) {
			check_admin_referer( 'pastperfect-sync-settings', 'pastperfect-sync-settings-nonce' );

			$settings_input = $this->get_sync_settings_input_from_post();
			$settings = SyncCoordinator::update_settings( $settings_input );

			$started = SyncCoordinator::start_job( 'manual', false, $settings );
			if ( is_wp_error( $started ) ) {
				$this->safe_redirect_with_notice( 'sync-error', $started->get_error_message() );
			}

			$this->safe_redirect_with_notice( 'sync-started' );
		}

		if ( isset( $_POST['pastperfect-sync-stop'] ) ) {
			check_admin_referer( 'pastperfect-sync-settings', 'pastperfect-sync-settings-nonce' );

			SyncCoordinator::stop_job( 'manual-stop' );
			$this->safe_redirect_with_notice( 'sync-stopped' );
		}

	}

	/**
	 * Register admin menus.
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ppwp_record',
			__( 'PastPerfect Setup', 'pastperfect-wp' ),
			__( 'Setup', 'pastperfect-wp' ),
			'manage_options',
			self::SETUP_PAGE_SLUG,
			array( $this, 'render_setup_page' )
		);

		add_submenu_page(
			'edit.php?post_type=ppwp_record',
			__( 'Import PastPerfect Records', 'pastperfect-wp' ),
			__( 'Import', 'pastperfect-wp' ),
			'manage_options',
			'pastperfect-import-records',
			array( $this, 'render_import_page' )
		);

		add_submenu_page(
			'edit.php?post_type=ppwp_record',
			__( 'XML Mapping', 'pastperfect-wp' ),
			__( 'XML Mapping', 'pastperfect-wp' ),
			'manage_options',
			self::XML_MAPPING_PAGE_SLUG,
			array( $this, 'render_xml_mapping_page' )
		);
	}

	/**
	 * Render Import page.
	 */
	public function render_import_page(): void {
		wp_enqueue_style( 'ppwp_admin' );

		$results_key = isset( $_GET['results_key'] ) ? sanitize_text_field( wp_unslash( $_GET['results_key'] ) ) : '';
		$results = $results_key ? get_option( 'pastperfect_import_results_' . $results_key ) : null;
		$simulation_key = isset( $_GET['simulation_key'] ) ? sanitize_text_field( wp_unslash( $_GET['simulation_key'] ) ) : '';
		$simulation = $simulation_key ? get_transient( 'pastperfect_simulation_results_' . $simulation_key ) : null;
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

			<?php $this->print_sync_notice( $notice, $notice_text ); ?>

			<?php if ( '' !== $media_source_warning ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $media_source_warning ); ?></p></div>
			<?php endif; ?>

			<div class="pastperfect-sync-card">
				<h2><?php esc_html_e( 'Scheduled Sync (WP-Cron)', 'pastperfect-wp' ); ?></h2>
				<p><?php esc_html_e( 'Use this to run regular chunked sync imports from XML or DBF-backed PastPerfect sources.', 'pastperfect-wp' ); ?></p>

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
								<p class="description"><?php esc_html_e( 'Choose XML for a standard XML export. Choose DBF when importing from PastPerfect database files through the DBF add-on.', 'pastperfect-wp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pastperfect-sync-source"><?php esc_html_e( 'Source', 'pastperfect-wp' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" name="pastperfect-sync-source" id="pastperfect-sync-source" value="<?php echo esc_attr( (string) $sync_settings['source'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Enter a specific file path or https URL. Do not point to a directory.', 'pastperfect-wp' ); ?></p>
								<p class="description"><?php esc_html_e( 'XML examples: /full/path/to/export.xml or https://example.org/export.xml . The XML file should contain <record> or <dc-record> elements.', 'pastperfect-wp' ); ?></p>
								<p class="description"><?php esc_html_e( 'DBF examples: archive-files/PPSdata-archives.xml (XML analog) or /full/path/to/PastPerfect/Data/ARCHIVES.DBF . Do not enter only the PastPerfect/Data directory.', 'pastperfect-wp' ); ?></p>
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
						<button type="submit" class="button button-secondary" name="pastperfect-sync-simulate" value="1"><?php esc_html_e( 'Run Import Simulation', 'pastperfect-wp' ); ?></button>
						<button type="submit" class="button button-secondary" name="pastperfect-sync-run-now" value="1"><?php esc_html_e( 'Run Sync Now', 'pastperfect-wp' ); ?></button>
						<button type="submit" class="button button-secondary" name="pastperfect-sync-stop" value="1" onclick="return window.confirm('<?php echo esc_js( __( 'Stop the running sync and clear queued chunk jobs?', 'pastperfect-wp' ) ); ?>');"><?php esc_html_e( 'Stop Running Sync', 'pastperfect-wp' ); ?></button>
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

			<?php if ( is_array( $simulation ) ) : ?>
				<?php $this->render_simulation_results( $simulation ); ?>
			<?php endif; ?>

			<?php if ( $results ) : ?>
				<h2><?php esc_html_e( 'Results', 'pastperfect-wp' ); ?></h2>

				<?php $this->print_import_results( $results ); ?>

				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ppwp_record&page=pastperfect-import-records' ) ); ?>"><?php esc_html_e( '<<< Import another set of records', 'pastperfect-wp' ); ?></a>
			<?php else : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'For detailed information about these settings, please see the README.md file', 'pastperfect-wp' ); ?></p>
				</div>
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

	protected function is_setup_page(): bool {
		global $pagenow;

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'edit.php' === $pagenow
			&& 'ppwp_record' === $post_type
			&& self::SETUP_PAGE_SLUG === $page;
	}

	private function handle_setup_actions(): void {
		if ( empty( $_POST['ppwp-setup-save'] ) ) {
			return;
		}

		check_admin_referer( 'ppwp-setup-save', 'ppwp-setup-save-nonce' );

		$input = isset( $_POST['ppwp-setup'] ) ? (array) wp_unslash( $_POST['ppwp-setup'] ) : array();
		$settings = Schema::sanitize_setup_settings( $input );
		update_option( 'pastperfect_setup_settings', $settings, false );

		flush_rewrite_rules( false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'ppwp_record',
					'page' => self::SETUP_PAGE_SLUG,
					'ppwp_setup_notice' => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	public function render_setup_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Schema::get_setup_settings();
		$notice = isset( $_GET['ppwp_setup_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['ppwp_setup_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PastPerfect Setup', 'pastperfect-wp' ); ?></h1>
			<p><?php esc_html_e( 'Control post type labels and permalink bases used by PastPerfect records.', 'pastperfect-wp' ); ?></p>

			<?php $this->print_setup_notice( $notice ); ?>

			<form action="" method="post">
				<?php wp_nonce_field( 'ppwp-setup-save', 'ppwp-setup-save-nonce', false ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ppwp-setup-post-type-label-plural"><?php esc_html_e( 'Post type plural label', 'pastperfect-wp' ); ?></label></th>
						<td><input id="ppwp-setup-post-type-label-plural" name="ppwp-setup[post_type_label_plural]" class="regular-text" type="text" value="<?php echo esc_attr( (string) $settings['post_type_label_plural'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwp-setup-post-type-label-singular"><?php esc_html_e( 'Post type singular label', 'pastperfect-wp' ); ?></label></th>
						<td><input id="ppwp-setup-post-type-label-singular" name="ppwp-setup[post_type_label_singular]" class="regular-text" type="text" value="<?php echo esc_attr( (string) $settings['post_type_label_singular'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwp-setup-post-type-slug"><?php esc_html_e( 'Record permalink base', 'pastperfect-wp' ); ?></label></th>
						<td>
							<input id="ppwp-setup-post-type-slug" name="ppwp-setup[post_type_slug]" class="regular-text" type="text" value="<?php echo esc_attr( (string) $settings['post_type_slug'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for record archive and single-record URLs.', 'pastperfect-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwp-setup-subject-slug"><?php esc_html_e( 'Subject permalink base', 'pastperfect-wp' ); ?></label></th>
						<td>
							<input id="ppwp-setup-subject-slug" name="ppwp-setup[subject_slug]" class="regular-text" type="text" value="<?php echo esc_attr( (string) $settings['subject_slug'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for subject term archive URLs.', 'pastperfect-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable subject archives', 'pastperfect-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ppwp-setup[subject_public]" value="1" <?php checked( ! empty( $settings['subject_public'] ) ); ?> />
								<?php esc_html_e( 'Make ppwp_subject taxonomy publicly queryable.', 'pastperfect-wp' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" name="ppwp-setup-save" value="1"><?php esc_html_e( 'Save Setup', 'pastperfect-wp' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	protected function is_xml_mapping_page(): bool {
		global $pagenow;

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'edit.php' === $pagenow
			&& 'ppwp_record' === $post_type
			&& self::XML_MAPPING_PAGE_SLUG === $page;
	}

	private function handle_xml_mapping_actions(): void {
		$save_mapping = ! empty( $_POST['ppwp-xml-map-save'] );
		$reset_mapping = ! empty( $_POST['ppwp-xml-map-reset'] );

		if ( ! $save_mapping && ! $reset_mapping ) {
			return;
		}

		check_admin_referer( 'ppwp-xml-map-save', 'ppwp-xml-map-save-nonce' );

		$notice = 'saved';
		if ( $reset_mapping ) {
			delete_option( self::OPTION_XML_FIELD_MAP );
			delete_option( self::OPTION_XML_WP_BEHAVIOR );
			$notice = 'reset';
		} else {
			$map_input = isset( $_POST['ppwp-xml-map'] ) ? (array) wp_unslash( $_POST['ppwp-xml-map'] ) : array();
			$wp_behavior_input = isset( $_POST['ppwp-xml-wp'] ) ? (array) wp_unslash( $_POST['ppwp-xml-wp'] ) : array();
			$map = self::sanitize_xml_field_map_input( $map_input );
			$wp_behavior = self::sanitize_xml_wp_behavior_input( $wp_behavior_input );
			if ( empty( $map ) ) {
				delete_option( self::OPTION_XML_FIELD_MAP );
			} else {
				update_option( self::OPTION_XML_FIELD_MAP, $map, false );
			}

			if ( $wp_behavior === self::get_default_xml_wp_behavior() ) {
				delete_option( self::OPTION_XML_WP_BEHAVIOR );
			} else {
				update_option( self::OPTION_XML_WP_BEHAVIOR, $wp_behavior, false );
			}
		}

		SyncCoordinator::initialize_schedule();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'ppwp_record',
					'page' => self::XML_MAPPING_PAGE_SLUG,
					'ppwp_xml_notice' => $notice,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	public function render_xml_mapping_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = SyncCoordinator::get_settings();
		$source = isset( $settings['source'] ) ? (string) $settings['source'] : '';
		$provider = isset( $settings['source_provider'] ) ? (string) $settings['source_provider'] : 'xml';
		$notice = isset( $_GET['ppwp_xml_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['ppwp_xml_notice'] ) ) : '';

		$profile_error = '';
		$profile = array(
			'record_element' => '',
			'records_scanned' => 0,
			'fields' => array(),
		);

		if ( 'xml' !== $provider ) {
			$profile_error = __( 'Source provider is not set to XML. Switch the source provider to XML before editing XML mapping.', 'pastperfect-wp' );
		} elseif ( '' === trim( $source ) ) {
			$profile_error = __( 'No XML source is configured yet. Set a source path on the Import screen first.', 'pastperfect-wp' );
		} else {
			$local_file = self::resolve_sync_source_to_local_file( $source );
			if ( is_wp_error( $local_file ) ) {
				$profile_error = $local_file->get_error_message();
			} else {
				$field_profile = self::inspect_xml_field_usage( (string) $local_file, 250 );
				if ( is_wp_error( $field_profile ) ) {
					$profile_error = $field_profile->get_error_message();
				} elseif ( is_array( $field_profile ) ) {
					$profile = $field_profile;
				}
			}
		}

		$stored_map = get_option( self::OPTION_XML_FIELD_MAP, array() );
		if ( ! is_array( $stored_map ) ) {
			$stored_map = array();
		}

		$effective_map = self::get_xml_field_map();
		$wp_behavior = self::get_xml_wp_behavior();
		$available_fields = array_keys( isset( $profile['fields'] ) && is_array( $profile['fields'] ) ? $profile['fields'] : array() );
		sort( $available_fields );
		$import_url = add_query_arg(
			array(
				'post_type' => 'ppwp_record',
				'page' => 'pastperfect-import-records',
			),
			admin_url( 'edit.php' )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PastPerfect XML Field Mapping', 'pastperfect-wp' ); ?></h1>
			<p><?php esc_html_e( 'Map incoming XML field names to Dublin Core fields used by the importer. Use this when source XML names do not match expected Dublin Core element names.', 'pastperfect-wp' ); ?></p>
			<p><a href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Return to Import screen', 'pastperfect-wp' ); ?></a></p>

			<?php $this->print_xml_mapping_notice( $notice ); ?>

			<?php if ( '' !== $profile_error ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $profile_error ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-info"><p>
					<?php
					printf(
						/* translators: 1: record element name, 2: record sample size */
						esc_html__( 'Detected record element: %1$s. Sampled %2$d records from the configured XML source.', 'pastperfect-wp' ),
						esc_html( (string) ( $profile['record_element'] ?? '' ) ),
						absint( $profile['records_scanned'] ?? 0 )
					);
					?>
				</p></div>
			<?php endif; ?>

			<form action="" method="post">
				<?php wp_nonce_field( 'ppwp-xml-map-save', 'ppwp-xml-map-save-nonce', false ); ?>
				<h2><?php esc_html_e( 'WordPress Mapping Behavior', 'pastperfect-wp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ppwp-xml-wp-post-title-mode"><?php esc_html_e( 'Post title', 'pastperfect-wp' ); ?></label></th>
						<td>
							<select id="ppwp-xml-wp-post-title-mode" name="ppwp-xml-wp[post_title_mode]">
								<option value="first_title" <?php selected( 'first_title', (string) $wp_behavior['post_title_mode'] ); ?>><?php esc_html_e( 'Use first XML title value', 'pastperfect-wp' ); ?></option>
								<option value="identifier_plus_title" <?php selected( 'identifier_plus_title', (string) $wp_behavior['post_title_mode'] ); ?>><?php esc_html_e( 'Identifier + title', 'pastperfect-wp' ); ?></option>
								<option value="identifier_only" <?php selected( 'identifier_only', (string) $wp_behavior['post_title_mode'] ); ?>><?php esc_html_e( 'Identifier only', 'pastperfect-wp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default is first XML title value.', 'pastperfect-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwp-xml-wp-post-content-field"><?php esc_html_e( 'Post body content', 'pastperfect-wp' ); ?></label></th>
						<td>
							<select id="ppwp-xml-wp-post-content-field" name="ppwp-xml-wp[post_content_field]">
								<option value="description" <?php selected( 'description', (string) $wp_behavior['post_content_field'] ); ?>><?php esc_html_e( 'Description', 'pastperfect-wp' ); ?></option>
								<?php foreach ( $available_fields as $field_name ) : ?>
									<option value="<?php echo esc_attr( $field_name ); ?>" <?php selected( $field_name, (string) $wp_behavior['post_content_field'] ); ?>><?php echo esc_html( $field_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default is description.', 'pastperfect-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwp-xml-wp-type-taxonomy"><?php esc_html_e( 'Map type to taxonomy', 'pastperfect-wp' ); ?></label></th>
						<td>
							<select id="ppwp-xml-wp-type-taxonomy" name="ppwp-xml-wp[type_taxonomy]">
								<option value="ppwp_subject" <?php selected( 'ppwp_subject', (string) $wp_behavior['type_taxonomy'] ); ?>><?php esc_html_e( 'Subject taxonomy (ppwp_subject)', 'pastperfect-wp' ); ?></option>
								<option value="post_tag" <?php selected( 'post_tag', (string) $wp_behavior['type_taxonomy'] ); ?>><?php esc_html_e( 'WordPress tags (post_tag)', 'pastperfect-wp' ); ?></option>
								<option value="" <?php selected( '', (string) $wp_behavior['type_taxonomy'] ); ?>><?php esc_html_e( 'Disable type taxonomy mapping', 'pastperfect-wp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default maps type values into the subject taxonomy for this record type.', 'pastperfect-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwp-xml-wp-thumbnail-mode"><?php esc_html_e( 'Featured image strategy', 'pastperfect-wp' ); ?></label></th>
						<td>
							<select id="ppwp-xml-wp-thumbnail-mode" name="ppwp-xml-wp[thumbnail_mode]">
								<option value="first_if_missing" <?php selected( 'first_if_missing', (string) $wp_behavior['thumbnail_mode'] ); ?>><?php esc_html_e( 'Set first image if no featured image exists', 'pastperfect-wp' ); ?></option>
								<option value="always_replace" <?php selected( 'always_replace', (string) $wp_behavior['thumbnail_mode'] ); ?>><?php esc_html_e( 'Always replace featured image with first image', 'pastperfect-wp' ); ?></option>
								<option value="disabled" <?php selected( 'disabled', (string) $wp_behavior['thumbnail_mode'] ); ?>><?php esc_html_e( 'Do not set featured image automatically', 'pastperfect-wp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Applies when media imports into the WordPress Media Library.', 'pastperfect-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-tag from title/body', 'pastperfect-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ppwp-xml-wp[auto_tag_terms_from_content]" value="1" <?php checked( ! empty( $wp_behavior['auto_tag_terms_from_content'] ) ); ?> />
								<?php esc_html_e( 'Automatically assign existing taxonomy terms when term names are found in title or description text.', 'pastperfect-wp' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Dublin Core Source Mapping', 'pastperfect-wp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( Record::get_dc_elements() as $dc_field ) : ?>
						<?php
						$current_override = isset( $stored_map[ $dc_field ] ) ? (string) $stored_map[ $dc_field ] : '__default__';
						$current_effective = isset( $effective_map[ $dc_field ] ) ? (string) $effective_map[ $dc_field ] : $dc_field;
						?>
						<tr>
							<th scope="row"><label for="ppwp-xml-map-<?php echo esc_attr( $dc_field ); ?>"><?php echo esc_html( ucfirst( $dc_field ) ); ?></label></th>
							<td>
								<select id="ppwp-xml-map-<?php echo esc_attr( $dc_field ); ?>" name="ppwp-xml-map[<?php echo esc_attr( $dc_field ); ?>]">
									<option value="__default__" <?php selected( '__default__', $current_override ); ?>>
										<?php esc_html_e( 'Use default field name (same as Dublin Core field)', 'pastperfect-wp' ); ?>
									</option>
									<option value="__none__" <?php selected( '__none__', $current_override ); ?>>
										<?php esc_html_e( 'Do not import this field', 'pastperfect-wp' ); ?>
									</option>
									<?php foreach ( $available_fields as $field_name ) : ?>
										<option value="<?php echo esc_attr( $field_name ); ?>" <?php selected( $field_name, $current_effective ); ?>><?php echo esc_html( $field_name ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									$field_profile = isset( $profile['fields'][ $current_effective ] ) && is_array( $profile['fields'][ $current_effective ] ) ? $profile['fields'][ $current_effective ] : array();
									if ( ! empty( $field_profile ) ) {
										printf(
											/* translators: 1: usage count, 2: sample value */
											esc_html__( 'Detected in %1$d sampled records. Example: %2$s', 'pastperfect-wp' ),
											absint( $field_profile['count'] ?? 0 ),
											esc_html( (string) ( $field_profile['sample'] ?? '' ) )
										);
									} else {
										echo esc_html__( 'No sampled value available for current mapping.', 'pastperfect-wp' );
									}
									?>
								</p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" name="ppwp-xml-map-save" value="1"><?php esc_html_e( 'Save Mapping', 'pastperfect-wp' ); ?></button>
					<button type="submit" class="button button-secondary" name="ppwp-xml-map-reset" value="1"><?php esc_html_e( 'Reset To Defaults', 'pastperfect-wp' ); ?></button>
				</p>
			</form>

			<?php if ( ! empty( $available_fields ) ) : ?>
				<h2><?php esc_html_e( 'Detected Source Fields', 'pastperfect-wp' ); ?></h2>
				<table class="widefat striped" role="presentation">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Field', 'pastperfect-wp' ); ?></th>
							<th><?php esc_html_e( 'Count In Sample', 'pastperfect-wp' ); ?></th>
							<th><?php esc_html_e( 'Example', 'pastperfect-wp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $available_fields as $field_name ) : ?>
							<?php $meta = isset( $profile['fields'][ $field_name ] ) ? (array) $profile['fields'][ $field_name ] : array(); ?>
							<tr>
								<td><?php echo esc_html( $field_name ); ?></td>
								<td><?php echo esc_html( (string) absint( $meta['count'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $meta['sample'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_sync_settings_input_from_post(): array {
		return array(
			'enabled' => ! empty( $_POST['pastperfect-sync-enabled'] ),
			'recurrence' => isset( $_POST['pastperfect-sync-recurrence'] ) ? wp_unslash( $_POST['pastperfect-sync-recurrence'] ) : 'daily',
			'source' => isset( $_POST['pastperfect-sync-source'] ) ? wp_unslash( $_POST['pastperfect-sync-source'] ) : '',
			'source_provider' => isset( $_POST['pastperfect-sync-source-provider'] ) ? wp_unslash( $_POST['pastperfect-sync-source-provider'] ) : 'xml',
			'increment' => isset( $_POST['pastperfect-sync-increment'] ) ? wp_unslash( $_POST['pastperfect-sync-increment'] ) : 100,
			'import_media' => ! empty( $_POST['pastperfect-import-media'] ),
			'media_provider' => isset( $_POST['pastperfect-media-provider'] ) ? wp_unslash( $_POST['pastperfect-media-provider'] ) : 'wp_media_library',
			'media_source_directory' => isset( $_POST['pastperfect-media-source-directory'] ) ? wp_unslash( $_POST['pastperfect-media-source-directory'] ) : '',
			'media_remote_base_url' => isset( $_POST['pastperfect-media-remote-base-url'] ) ? wp_unslash( $_POST['pastperfect-media-remote-base-url'] ) : '',
			'media_index_refresh_enabled' => ! empty( $_POST['pastperfect-media-index-refresh-enabled'] ),
			'media_index_refresh_recurrence' => isset( $_POST['pastperfect-media-index-refresh-recurrence'] ) ? wp_unslash( $_POST['pastperfect-media-index-refresh-recurrence'] ) : 'daily',
		);
	}

	private static function simulate_import_source( array $settings ) {
		$settings = self::normalize_sync_settings_preview( $settings );
		$source = trim( (string) ( $settings['source'] ?? '' ) );
		$provider = isset( $settings['source_provider'] ) ? sanitize_key( (string) $settings['source_provider'] ) : 'xml';
		if ( '' === $source ) {
			return new \WP_Error( 'pastperfect_sync_missing_source', __( 'No source has been configured for simulation.', 'pastperfect-wp' ) );
		}

		if ( 'xml' !== $provider ) {
			$provider_result = apply_filters( 'ppwp_simulate_import_source', null, $settings, $source );
			if ( is_wp_error( $provider_result ) ) {
				return $provider_result;
			}

			if ( is_array( $provider_result ) ) {
				return $provider_result;
			}
		}

		$local_file = self::resolve_sync_source_to_local_file( $source );
		if ( is_wp_error( $local_file ) ) {
			return $local_file;
		}

		$simulation = ImportSimulator::simulate( $local_file, self::normalize_import_settings( $settings ) );
		if ( ! empty( $simulation['error'] ) ) {
			return new \WP_Error( 'pastperfect_simulation_failed', (string) $simulation['error'] );
		}

		$simulation['source_provider'] = isset( $settings['source_provider'] ) ? (string) $settings['source_provider'] : 'xml';
		$simulation['source'] = $source;

		return $simulation;
	}

	private static function normalize_sync_settings_preview( array $input ): array {
		$current = SyncCoordinator::get_settings();

		$current['enabled'] = ! empty( $input['enabled'] );

		$requested_recurrence = isset( $input['recurrence'] ) ? sanitize_key( (string) $input['recurrence'] ) : 'daily';
		$schedules = wp_get_schedules();
		$current['recurrence'] = isset( $schedules[ $requested_recurrence ] ) ? $requested_recurrence : 'daily';

		$current['source'] = isset( $input['source'] ) ? sanitize_text_field( (string) $input['source'] ) : '';
		$current['source_provider'] = isset( $input['source_provider'] ) ? sanitize_key( (string) $input['source_provider'] ) : 'xml';

		$increment = isset( $input['increment'] ) ? absint( $input['increment'] ) : 10;
		$current['increment'] = max( 1, min( 200, $increment ) );

		$provider = isset( $input['media_provider'] ) ? sanitize_key( (string) $input['media_provider'] ) : 'wp_media_library';
		$allowed_providers = array( 'wp_media_library', 'aws_s3', 'google_cloud_storage', 'google_drive' );
		$current['media_provider'] = in_array( $provider, $allowed_providers, true ) ? $provider : 'wp_media_library';
		$current['media_source_directory'] = isset( $input['media_source_directory'] ) ? sanitize_text_field( (string) $input['media_source_directory'] ) : '';
		$current['media_remote_base_url'] = isset( $input['media_remote_base_url'] ) ? esc_url_raw( (string) $input['media_remote_base_url'] ) : '';
		$current['import_media'] = ! empty( $input['import_media'] );
		$current['media_index_refresh_enabled'] = ! empty( $input['media_index_refresh_enabled'] );

		$requested_index_recurrence = isset( $input['media_index_refresh_recurrence'] ) ? sanitize_key( (string) $input['media_index_refresh_recurrence'] ) : 'daily';
		$current['media_index_refresh_recurrence'] = isset( $schedules[ $requested_index_recurrence ] ) ? $requested_index_recurrence : 'daily';

		return $current;
	}

	private function render_simulation_results( array $simulation ): void {
		$report = isset( $simulation['xml_simulation'] ) && is_array( $simulation['xml_simulation'] )
			? $simulation['xml_simulation']
			: $simulation;
		$provider = isset( $simulation['source_provider'] ) ? (string) $simulation['source_provider'] : 'xml';
		$source = isset( $simulation['source'] ) ? (string) $simulation['source'] : (string) ( $report['xml_path'] ?? '' );
		$totals = isset( $report['totals'] ) && is_array( $report['totals'] ) ? $report['totals'] : array();
		$media = isset( $report['media'] ) && is_array( $report['media'] ) ? $report['media'] : array();
		?>
		<div class="pastperfect-sync-card">
			<h2><?php esc_html_e( 'Import Simulation', 'pastperfect-wp' ); ?></h2>
			<p><?php esc_html_e( 'This is a dry run using the configured source and media settings. No records were created or updated.', 'pastperfect-wp' ); ?></p>
			<table class="widefat striped" role="presentation">
				<tbody>
					<tr><th><?php esc_html_e( 'Source provider', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( $provider ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Source', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( $source ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Record element', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) ( $report['record_element'] ?? '' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Records scanned', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $totals['records'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Would create', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $totals['would_create'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Would update', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $totals['would_update'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Duplicate identifiers', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $totals['duplicate_identifiers'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Missing identifiers', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $totals['missing_identifier'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Parse errors', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $totals['parse_errors'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Media references', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $media['total_references'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Missing media references', 'pastperfect-wp' ); ?></th><td><?php echo esc_html( (string) absint( $media['missing_references'] ?? 0 ) ); ?></td></tr>
				</tbody>
			</table>

			<?php if ( ! empty( $simulation['memo_health']['memo_preflight']['status'] ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: memo status, 2: memo message */
						esc_html__( 'Memo sidecar status: %1$s. %2$s', 'pastperfect-wp' ),
						esc_html( (string) $simulation['memo_health']['memo_preflight']['status'] ),
						esc_html( (string) ( $simulation['memo_health']['memo_preflight']['message'] ?? '' ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $simulation['field_usage_delta'] ) && is_array( $simulation['field_usage_delta'] ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: fields only in DBF, 2: fields missing from DBF */
						esc_html__( 'Field delta. Additional in DBF: %1$s. Missing from DBF: %2$s.', 'pastperfect-wp' ),
						esc_html( implode( ', ', (array) ( $simulation['field_usage_delta']['additional_in_dbf'] ?? array() ) ) ?: 'none' ),
						esc_html( implode( ', ', (array) ( $simulation['field_usage_delta']['missing_from_dbf'] ?? array() ) ) ?: 'none' )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $media['missing_reference_samples'] ) && is_array( $media['missing_reference_samples'] ) ) : ?>
				<p><?php esc_html_e( 'Missing media samples:', 'pastperfect-wp' ); ?></p>
				<pre class="pastperfect-import-results"><?php echo esc_html( implode( "\n", array_slice( $media['missing_reference_samples'], 0, 25 ) ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'pastperfect-dc-metadata',
			__( 'Dublin Core Metadata', 'pastperfect-wp' ),
			array( $this, 'render_meta_box' ),
			'ppwp_record',
			'advanced'
		);
	}

	/**
	 * @param \WP_Post $post Post object.
	 */
	/**
	 * Render Dublin Core metadata meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_meta_box( $post ): void {
		$this->print_dc_metadata_table( $post->ID );
	}

	/**
	 * Print Dublin Core metadata in a table format.
	 */
	private function print_dc_metadata_table( int $post_id ): void {
		echo '<table class="form-table">';
		$record = new Record( $post_id );
		foreach ( Record::get_dc_elements() as $element ) {
			$this->print_dc_element_row( $record, $element );
		}
		echo '</table>';
	}

	/**
	 * Print a single Dublin Core element row.
	 */
	private function print_dc_element_row( Record $record, string $element ): void {
		$all_values = $record->get_dc_metadata( $element, false );
		$values_formatted = $this->format_dc_values( $all_values );
		$values_html = implode( "\n", $values_formatted );
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $element ),
			$values_html  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped in format_dc_values
		);
	}

	/**
	 * Format Dublin Core values for display.
	 *
	 * @param mixed $all_values Values to format.
	 * @return array<int,string>
	 */
	private function format_dc_values( $all_values ): array {
		$values_formatted = array();
		foreach ( (array) $all_values as $key => $value ) {
			if ( is_array( $value ) ) {
				$values_formatted[] = $this->format_dc_array_value( $key, $value );
			} else {
				$values_formatted[] = wpautop( esc_html( (string) $value ) );
			}
		}
		return $values_formatted;
	}

	/**
	 * Format a Dublin Core array value as a definition list.
	 */
	private function format_dc_array_value( $key, array $value ): string {
		$escaped_key = esc_html( (string) $key );
		$escaped_values = implode( "\n", array_map( 'esc_html', $value ) );
		return sprintf(
			'<p><dl><dt>%s</dt><dd>%s</dd></dl></p>',
			$escaped_key,
			$escaped_values
		);
	}

	/**
	 * Build or refresh a run from a source XML file.
	 *
	 * @return array|\WP_Error
	 */
	public static function create_import_run_from_file( string $xml_path, array $settings = array() ) {
		if ( empty( $xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_missing', __( 'Could not read XML source file.', 'pastperfect-wp' ) );
		}

		if ( is_dir( $xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_is_directory', self::build_directory_source_error_message( $xml_path, $settings ) );
		}

		if ( ! is_readable( $xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_missing', __( 'Could not read XML source file.', 'pastperfect-wp' ) );
		}

		$parser_xml_path = self::prepare_xml_source_for_parser( $xml_path );
		if ( is_wp_error( $parser_xml_path ) ) {
			return $parser_xml_path;
		}

		$reader = new \XMLReader();
		if ( ! $reader->open( $parser_xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_open_failed', __( 'Could not open XML source file.', 'pastperfect-wp' ) );
		}

		$record_element = self::find_record_element( $reader );
		if ( ! $record_element ) {
			$reader->close();
			return new \WP_Error( 'pastperfect_no_records', self::build_unsupported_xml_source_error_message( $xml_path, $settings ) );
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
		$reader->close();

		$run_data = array(
			'run' => $run,
			'xml' => $parser_xml_path,
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
	 * Create a parser-safe XML source path by normalizing common PastPerfect export quirks.
	 *
	 * @return string|\WP_Error
	 */
	private static function prepare_xml_source_for_parser( string $xml_path ) {
		$payload = file_get_contents( $xml_path );
		if ( false === $payload ) {
			return new \WP_Error( 'pastperfect_xml_missing', __( 'Could not read XML source file.', 'pastperfect-wp' ) );
		}

		$normalized = self::sanitize_xml_payload( $payload );
		if ( '' === $normalized ) {
			return new \WP_Error( 'pastperfect_xml_missing', __( 'Could not read XML source file.', 'pastperfect-wp' ) );
		}

		if ( $normalized === $payload ) {
			return $xml_path;
		}

		$uploads = wp_get_upload_dir();
		$base_dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base_dir ) {
			return new \WP_Error( 'pastperfect_xml_open_failed', __( 'Could not prepare normalized XML source file.', 'pastperfect-wp' ) );
		}

		$normalized_path = trailingslashit( $base_dir ) . 'pastperfect-sync-source-normalized.xml';
		$written = file_put_contents( $normalized_path, $normalized );
		if ( false === $written ) {
			return new \WP_Error( 'pastperfect_xml_open_failed', __( 'Could not prepare normalized XML source file.', 'pastperfect-wp' ) );
		}

		return $normalized_path;
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
			if ( '' === trim( (string) $parsed['identifier'] ) ) {
				$results[] = array(
					'identifier' => '',
					'status' => 'failed',
				);
				$reader->next( $record_element );
				continue;
			}

			$record = new Record();
			$exists = (bool) $record->get_post_id_by_identifier( $parsed['identifier'] );
			$record->set_up_from_raw_atts( $parsed['atts'] );

			// Determine initial post status based on whether media will be found.
			$import_settings = is_array( $run_data['import_settings'] ?? null ) ? $run_data['import_settings'] : array();
			$post_status = self::will_record_have_media( $parsed['atts'], $import_settings ) ? 'publish' : 'draft';
			if ( ! $exists ) {
				$record->set_initial_post_status( $post_status );
			}

			$saved = $record->save();
			if ( $saved ) {
				self::handle_media_for_record(
					absint( $saved ),
					$parsed['atts'],
					$import_settings
				);

				// If the record was created as draft but now has media, publish it.
				if ( ! $exists && 'draft' === $post_status ) {
					self::maybe_publish_record_if_has_media( absint( $saved ) );
				}
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
		$source = self::normalize_local_source_input( $source );
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

		$candidates = self::build_local_source_path_candidates( $source );
		foreach ( $candidates as $candidate ) {
			if ( is_dir( $candidate ) ) {
				return new \WP_Error( 'pastperfect_sync_source_is_directory', self::build_directory_source_error_message( $candidate ) );
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return new \WP_Error(
			'pastperfect_sync_source_not_readable',
			sprintf(
				/* translators: 1: configured source value, 2: attempted path list. */
				__( 'Configured XML source path is not readable. Input: %1$s. Tried: %2$s', 'pastperfect-wp' ),
				$source,
				implode( ', ', $candidates )
			)
		);
	}

	private static function normalize_local_source_input( string $source ): string {
		$source = trim( $source );

		if ( preg_match( '/^file:\/\//i', $source ) ) {
			$source = (string) wp_parse_url( $source, PHP_URL_PATH );
			$source = rawurldecode( $source );
		}

		if ( strlen( $source ) >= 2 ) {
			$first = $source[0];
			$last = $source[ strlen( $source ) - 1 ];
			if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
				$source = substr( $source, 1, -1 );
			}
		}

		return trim( str_replace( '\\', '/', $source ) );
	}

	/**
	 * @return array<int,string>
	 */
	private static function build_local_source_path_candidates( string $source ): array {
		$candidates = array();

		$candidates[] = $source;

		$abs_path = defined( 'ABSPATH' ) ? wp_normalize_path( (string) ABSPATH ) : '';
		$content_path = defined( 'WP_CONTENT_DIR' ) ? wp_normalize_path( (string) WP_CONTENT_DIR ) : '';

		if ( '' !== $abs_path && ! self::is_absolute_path( $source ) ) {
			$candidates[] = wp_normalize_path( trailingslashit( $abs_path ) . ltrim( $source, '/' ) );
		}

		if ( '' !== $content_path && ! self::is_absolute_path( $source ) ) {
			$candidates[] = wp_normalize_path( trailingslashit( $content_path ) . ltrim( $source, '/' ) );
			$candidates[] = wp_normalize_path( trailingslashit( dirname( $content_path ) ) . ltrim( $source, '/' ) );
		}

		if ( '' !== $abs_path && 0 === strpos( $source, '/app/' ) ) {
			$candidates[] = wp_normalize_path( trailingslashit( $abs_path ) . ltrim( substr( $source, 5 ), '/' ) );
		}

		$resolved = array();
		foreach ( $candidates as $candidate ) {
			$candidate = wp_normalize_path( trim( $candidate ) );
			if ( '' === $candidate ) {
				continue;
			}

			$resolved[] = $candidate;
			$real = realpath( $candidate );
			if ( false !== $real ) {
				$resolved[] = wp_normalize_path( $real );
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	private static function is_absolute_path( string $path ): bool {
		$path = trim( $path );
		return '' !== $path && ( '/' === $path[0] || preg_match( '/^[A-Za-z]:\//', $path ) );
	}

	/**
	 * @param \SimpleXMLElement $node Current XML node.
	 * @return array{identifier:string,atts:array<string,mixed>}
	 */
	private static function build_record_atts_from_node( \SimpleXMLElement $node ): array {
		$atts = array();
		$singular_elements = Record::get_singular_elements();

		foreach ( $node->children() as $field_name => $field_node ) {
			$field_name = (string) $field_name;
			$field_value = (string) $field_node;

			$children = $field_node->children();
			if ( $children && count( $children ) > 0 ) {
				foreach ( $children as $child_key => $child_value ) {
					$atts[ $field_name ][ (string) $child_key ][] = (string) $child_value;
				}
				continue;
			}

			if ( in_array( $field_name, $singular_elements, true ) ) {
				$field_value = trim( $field_value );
				if ( '' === $field_value ) {
					continue;
				}

				if ( ! isset( $atts[ $field_name ] ) || '' === trim( (string) $atts[ $field_name ] ) ) {
					$atts[ $field_name ] = $field_value;
					continue;
				}

				if ( 'title' === $field_name && self::is_generic_title_value( (string) $atts[ $field_name ] ) && ! self::is_generic_title_value( $field_value ) ) {
					$atts[ $field_name ] = $field_value;
				}
			} else {
				$atts[ $field_name ][] = $field_value;
			}
		}

		$atts = self::map_xml_source_atts_to_dc( $atts );
		$identifier = self::extract_identifier_from_mapped_atts( $atts );

		return array(
			'identifier' => $identifier,
			'atts' => $atts,
		);
	}

	/**
	 * @param array<string,mixed> $source_atts
	 * @return array<string,mixed>
	 */
	public static function map_xml_source_atts_to_dc( array $source_atts ): array {
		$map = self::get_xml_field_map();
		$dc_elements = Record::get_dc_elements();
		$dc_lookup = array();
		foreach ( $dc_elements as $dc_field ) {
			$dc_lookup[ strtolower( $dc_field ) ] = $dc_field;
		}

		$source_lookup = array();
		foreach ( $map as $dc_field => $source_field ) {
			$source_field = trim( (string) $source_field );
			if ( '' === $source_field ) {
				continue;
			}

			$source_lookup[ strtolower( $source_field ) ] = $dc_field;
		}

		$mapped = array();
		foreach ( $source_atts as $source_field => $value ) {
			$source_key = strtolower( trim( (string) $source_field ) );
			if ( '' === $source_key ) {
				continue;
			}

			$target = $source_lookup[ $source_key ] ?? '';
			if ( '' === $target ) {
				if ( ! isset( $dc_lookup[ $source_key ] ) ) {
					continue;
				}

				$candidate_dc = (string) $dc_lookup[ $source_key ];
				if ( '' === (string) ( $map[ $candidate_dc ] ?? $candidate_dc ) ) {
					continue;
				}

				$target = $candidate_dc;
			}

			if ( isset( $mapped[ $target ] ) ) {
				$mapped[ $target ] = self::merge_xml_mapped_values( $mapped[ $target ], $value );
			} else {
				$mapped[ $target ] = $value;
			}
		}

		return $mapped;
	}

	public static function extract_identifier_from_mapped_atts( array $atts ): string {
		if ( ! array_key_exists( 'identifier', $atts ) ) {
			return '';
		}

		return self::extract_first_scalar_value( $atts['identifier'] );
	}

	/**
	 * @param mixed $existing
	 * @param mixed $incoming
	 * @return mixed
	 */
	private static function merge_xml_mapped_values( $existing, $incoming ) {
		if ( is_array( $existing ) && is_array( $incoming ) ) {
			return array_merge( $existing, $incoming );
		}

		if ( is_array( $existing ) ) {
			$existing[] = $incoming;
			return $existing;
		}

		if ( is_array( $incoming ) ) {
			array_unshift( $incoming, $existing );
			return $incoming;
		}

		if ( (string) $existing === (string) $incoming ) {
			return $existing;
		}

		return array( $existing, $incoming );
	}

	/**
	 * @param mixed $value
	 */
	private static function extract_first_scalar_value( $value ): string {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$scalar = self::extract_first_scalar_value( $item );
				if ( '' !== $scalar ) {
					return $scalar;
				}
			}

			return '';
		}

		$value = trim( (string) $value );
		return $value;
	}

	private static function is_generic_title_value( string $value ): bool {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = trim( $value );
		$generic = array(
			'postcard',
			'post card',
			'photograph',
			'photo',
			'image',
			'picture',
		);

		return in_array( $value, $generic, true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,string>
	 */
	private static function sanitize_xml_field_map_input( array $input ): array {
		$sanitized = array();
		foreach ( Record::get_dc_elements() as $dc_field ) {
			$raw = isset( $input[ $dc_field ] ) ? (string) $input[ $dc_field ] : '';
			$value = trim( sanitize_text_field( $raw ) );

			if ( '' === $value || '__default__' === $value ) {
				continue;
			}

			if ( '__none__' === $value ) {
				$sanitized[ $dc_field ] = '__none__';
				continue;
			}

			$value = preg_replace( '/[^A-Za-z0-9_:\.-]/', '', $value );
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( 0 === strcasecmp( $value, $dc_field ) ) {
				continue;
			}

			$sanitized[ $dc_field ] = $value;
		}

		return $sanitized;
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_xml_field_map(): array {
		$map = array();
		foreach ( Record::get_dc_elements() as $dc_field ) {
			$map[ $dc_field ] = $dc_field;
		}

		$stored = get_option( self::OPTION_XML_FIELD_MAP, array() );
		if ( ! is_array( $stored ) ) {
			return $map;
		}

		foreach ( $stored as $dc_field => $source_field ) {
			$dc_field = sanitize_key( (string) $dc_field );
			if ( ! array_key_exists( $dc_field, $map ) ) {
				continue;
			}

			$source_field = trim( (string) $source_field );
			if ( '__none__' === $source_field ) {
				$map[ $dc_field ] = '';
				continue;
			}

			if ( '' !== $source_field ) {
				$map[ $dc_field ] = $source_field;
			}
		}

		return $map;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private static function sanitize_xml_wp_behavior_input( array $input ): array {
		$defaults = self::get_default_xml_wp_behavior();
		$behavior = $defaults;

		$post_title_mode = isset( $input['post_title_mode'] ) ? sanitize_key( (string) $input['post_title_mode'] ) : $defaults['post_title_mode'];
		$allowed_title_modes = array( 'first_title', 'identifier_plus_title', 'identifier_only' );
		$behavior['post_title_mode'] = in_array( $post_title_mode, $allowed_title_modes, true ) ? $post_title_mode : $defaults['post_title_mode'];

		$post_content_field = isset( $input['post_content_field'] ) ? sanitize_text_field( (string) $input['post_content_field'] ) : $defaults['post_content_field'];
		$post_content_field = preg_replace( '/[^A-Za-z0-9_:\.-]/', '', (string) $post_content_field );
		if ( ! is_string( $post_content_field ) || '' === $post_content_field ) {
			$post_content_field = $defaults['post_content_field'];
		}
		$behavior['post_content_field'] = $post_content_field;

		$type_taxonomy = isset( $input['type_taxonomy'] ) ? sanitize_key( (string) $input['type_taxonomy'] ) : $defaults['type_taxonomy'];
		$allowed_type_taxonomy = array( 'ppwp_subject', 'post_tag', '' );
		$behavior['type_taxonomy'] = in_array( $type_taxonomy, $allowed_type_taxonomy, true ) ? $type_taxonomy : $defaults['type_taxonomy'];

		$thumbnail_mode = isset( $input['thumbnail_mode'] ) ? sanitize_key( (string) $input['thumbnail_mode'] ) : $defaults['thumbnail_mode'];
		$allowed_thumbnail_modes = array( 'first_if_missing', 'always_replace', 'disabled' );
		$behavior['thumbnail_mode'] = in_array( $thumbnail_mode, $allowed_thumbnail_modes, true ) ? $thumbnail_mode : $defaults['thumbnail_mode'];

		$behavior['auto_tag_terms_from_content'] = ! empty( $input['auto_tag_terms_from_content'] );

		return $behavior;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_default_xml_wp_behavior(): array {
		return array(
			'post_title_mode' => 'first_title',
			'post_content_field' => 'description',
			'type_taxonomy' => 'ppwp_subject',
			'thumbnail_mode' => 'first_if_missing',
			'auto_tag_terms_from_content' => false,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_xml_wp_behavior(): array {
		$defaults = self::get_default_xml_wp_behavior();
		$stored = get_option( self::OPTION_XML_WP_BEHAVIOR, array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return self::sanitize_xml_wp_behavior_input( array_merge( $defaults, $stored ) );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function inspect_xml_field_usage( string $xml_path, int $max_records = 250 ) {
		$parser_xml_path = self::prepare_xml_source_for_parser( $xml_path );
		if ( is_wp_error( $parser_xml_path ) ) {
			return $parser_xml_path;
		}

		$reader = new \XMLReader();
		if ( ! $reader->open( (string) $parser_xml_path ) ) {
			return new \WP_Error( 'pastperfect_xml_open_failed', __( 'Could not open XML source file.', 'pastperfect-wp' ) );
		}

		$record_element = self::find_record_element( $reader );
		if ( '' === $record_element ) {
			$reader->close();
			return new \WP_Error( 'pastperfect_no_records', self::build_unsupported_xml_source_error_message( $xml_path, array( 'source_provider' => 'xml' ) ) );
		}

		$doc = new \DOMDocument();
		$fields = array();
		$records_scanned = 0;

		while ( $reader->read() && $record_element !== $reader->name ) {
			// Advance to first record.
		}

		while ( $record_element === $reader->name && $records_scanned < $max_records ) {
			$expanded = @ $reader->expand();
			if ( ! $expanded ) {
				$reader->next( $record_element );
				continue;
			}

			$imported = $doc->importNode( $expanded, true );
			if ( ! $imported ) {
				$reader->next( $record_element );
				continue;
			}

			$node = simplexml_import_dom( $imported );
			if ( ! $node ) {
				$reader->next( $record_element );
				continue;
			}

			$records_scanned++;
			foreach ( $node->children() as $field_name => $field_node ) {
				$field_name = trim( (string) $field_name );
				if ( '' === $field_name ) {
					continue;
				}

				if ( ! isset( $fields[ $field_name ] ) ) {
					$fields[ $field_name ] = array(
						'count' => 0,
						'sample' => '',
					);
				}

				$fields[ $field_name ]['count']++;
				$sample = trim( (string) $field_node );
				if ( '' !== $sample && '' === $fields[ $field_name ]['sample'] ) {
					$fields[ $field_name ]['sample'] = mb_substr( $sample, 0, 120 );
				}
			}

			$reader->next( $record_element );
		}

		$reader->close();

		ksort( $fields );

		return array(
			'record_element' => $record_element,
			'records_scanned' => $records_scanned,
			'fields' => $fields,
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
	 * Determine if media references will be found for a record.
	 *
	 * Checks both explicit relation references and inferred references from identifier.
	 *
	 * @param array $atts The record attributes from XML.
	 * @param array $settings The import settings.
	 * @return bool
	 */
	private static function will_record_have_media( array $atts, array $settings ): bool {
		$settings = self::normalize_import_settings( $settings );
		if ( empty( $settings['import_media'] ) ) {
			return false;
		}

		$references = self::extract_media_references( $atts['relation'] ?? array() );
		if ( ! empty( $references ) ) {
			return true;
		}

		$identifier_references = self::infer_media_references_from_identifier(
			isset( $atts['identifier'] ) ? (string) $atts['identifier'] : '',
			$settings['media_source_directory']
		);
		if ( ! empty( $identifier_references ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Publish a draft record if it now has associated media.
	 *
	 * This is called after media has been processed for a record to automatically
	 * publish draft records that now have media attachments.
	 *
	 * @param int $post_id The record post ID.
	 */
	private static function maybe_publish_record_if_has_media( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'ppwp_record' !== $post->post_type || 'draft' !== $post->post_status ) {
			return;
		}

		// Check if the post now has media in the relation metadata.
		$relation_values = get_post_meta( $post_id, 'pastperfect_dc_relation', false );
		if ( ! empty( $relation_values ) && is_array( $relation_values ) ) {
			// Check if any values are non-empty.
			$has_media = false;
			foreach ( $relation_values as $value ) {
				if ( ! empty( trim( (string) $value ) ) ) {
					$has_media = true;
					break;
				}
			}

			if ( $has_media ) {
				wp_update_post(
					array(
						'ID' => $post_id,
						'post_status' => 'publish',
					)
				);
			}
		}
	}

	/**
	 * Resolve media references for a record and update Dublin Core relation values.
	 */
	private static function handle_media_for_record( int $post_id, array $atts, array $settings ): void {
		$settings = self::normalize_import_settings( $settings );
		if ( empty( $settings['import_media'] ) ) {
			return;
		}

		$wp_behavior = self::get_xml_wp_behavior();
		$thumbnail_mode = isset( $wp_behavior['thumbnail_mode'] ) ? (string) $wp_behavior['thumbnail_mode'] : 'first_if_missing';

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
		$existing_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		$thumbnail_set = $existing_thumbnail_id > 0;

		foreach ( $references as $reference ) {
			$resolved_url = '';

			if ( 'wp_media_library' === $settings['media_provider'] ) {
				$source_file = self::resolve_local_media_path( $reference, $settings['media_source_directory'] );
				if ( $source_file && ! isset( $used_source_files[ $source_file ] ) ) {
					$used_source_files[ $source_file ] = true;
					$attachment_id = self::find_attachment_id_by_source_file( $source_file );
					$resolved_url = self::import_file_to_media_library( $source_file, $post_id );
					if ( is_wp_error( $resolved_url ) ) {
						$resolved_url = '';
					} else {
						if ( $attachment_id <= 0 ) {
							$attachment_id = self::find_attachment_id_by_source_file( $source_file );
						}

						$can_set_thumbnail = false;
						if ( 'always_replace' === $thumbnail_mode ) {
							$can_set_thumbnail = true;
						} elseif ( 'first_if_missing' === $thumbnail_mode && ! $thumbnail_set ) {
							$can_set_thumbnail = true;
						}

						if ( $can_set_thumbnail && $attachment_id > 0 && self::is_attachment_image( $attachment_id ) ) {
							set_post_thumbnail( $post_id, $attachment_id );
							$thumbnail_set = true;
						}
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

	private static function find_attachment_id_by_source_file( string $source_file ): int {
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

		if ( empty( $existing ) ) {
			return 0;
		}

		return (int) reset( $existing );
	}

	private static function is_attachment_image( int $attachment_id ): bool {
		$mime = (string) get_post_mime_type( $attachment_id );
		return '' !== $mime && 0 === strpos( $mime, 'image/' );
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

			$name = strtolower( (string) $reader->name );
			$local_name = strtolower( (string) $reader->localName );
			if ( in_array( $name, array( 'record', 'dc-record' ), true ) || in_array( $local_name, array( 'record', 'dc-record' ), true ) ) {
				return $reader->name;
			}
		}

		return '';
	}

	private static function build_directory_source_error_message( string $path, array $settings = array() ): string {
		$message = sprintf(
			/* translators: %s: configured path. */
			__( 'The configured source path points to a directory, not a file: %s', 'pastperfect-wp' ),
			$path
		);

		$provider = isset( $settings['source_provider'] ) ? (string) $settings['source_provider'] : 'xml';
		if ( 'dbf' === $provider ) {
			$message .= ' ' . __( 'For the DBF provider, point to the XML analog file or directly to ARCHIVES.DBF.', 'pastperfect-wp' );
		} else {
			$message .= ' ' . __( 'Point to a specific XML export file. If you intended to import from DBF, switch the source provider to DBF first.', 'pastperfect-wp' );
		}

		return $message;
	}

	private static function build_unsupported_xml_source_error_message( string $xml_path, array $settings = array() ): string {
		$extension = strtolower( (string) pathinfo( $xml_path, PATHINFO_EXTENSION ) );
		$provider = isset( $settings['source_provider'] ) ? (string) $settings['source_provider'] : 'xml';

		if ( in_array( $extension, array( 'dbf', 'fpt', 'cdx' ), true ) ) {
			if ( 'dbf' !== $provider ) {
				return __( 'The selected source is a PastPerfect database file, not an XML export. Switch the source provider to DBF or point to an XML export file.', 'pastperfect-wp' );
			}

			return __( 'The selected DBF-related file could not be parsed as XML. Point the DBF provider to the XML analog file or directly to ARCHIVES.DBF.', 'pastperfect-wp' );
		}

		$xml_shape = self::inspect_xml_source_shape( $xml_path );
		if ( ! empty( $xml_shape['parse_errors'] ) ) {
			return sprintf(
				/* translators: 1: parse error summary, 2: source path, 3: source provider key. */
				__( 'The XML file could be opened but is not well-formed. %1$s Source path: %2$s. Source provider: %3$s.', 'pastperfect-wp' ),
				implode( ' ', (array) $xml_shape['parse_errors'] ),
				$xml_path,
				$provider
			);
		}

		if ( ! empty( $xml_shape['root_element'] ) ) {
			$message = sprintf(
				/* translators: 1: root element name, 2: expected record element list. */
				__( 'The source file opened successfully, but its XML structure is not supported. Root element: <%1$s>. Expected files containing <%2$s> elements.', 'pastperfect-wp' ),
				(string) $xml_shape['root_element'],
				'record> or <dc-record'
			);

			$message .= ' ' . sprintf(
				/* translators: 1: source path, 2: source provider key. */
				__( 'Source path: %1$s. Source provider: %2$s.', 'pastperfect-wp' ),
				$xml_path,
				$provider
			);

			if ( ! empty( $xml_shape['element_samples'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: comma-separated element names. */
					__( 'First elements seen: %s.', 'pastperfect-wp' ),
					implode( ', ', array_map( static function ( $name ) {
						return '<' . $name . '>';
					}, $xml_shape['element_samples'] ) )
				);
			}

			if ( ! empty( $xml_shape['local_element_samples'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: comma-separated XML local element names. */
					__( 'First local element names: %s.', 'pastperfect-wp' ),
					implode( ', ', array_map( static function ( $name ) {
						return '<' . $name . '>';
					}, $xml_shape['local_element_samples'] ) )
				);
			}

			if ( 'dbf' !== $provider ) {
				$message .= ' ' . __( 'If this XML uses namespaces or different capitalization, ensure record nodes still have local names record or dc-record.', 'pastperfect-wp' );
				$message .= ' ' . __( 'If you intended to import from PastPerfect DBF data, switch the source provider to DBF.', 'pastperfect-wp' );
			}

			return $message;
		}

		return sprintf(
			/* translators: 1: source path, 2: source provider key. */
			__( 'The source file opened successfully but no supported XML record elements were found. Expected <record> or <dc-record> elements. Source path: %1$s. Source provider: %2$s.', 'pastperfect-wp' ),
			$xml_path,
			$provider
		);
	}

	/**
	 * @return array{root_element:string,element_samples:array<int,string>,local_element_samples:array<int,string>,parse_errors:array<int,string>}
	 */
	private static function inspect_xml_source_shape( string $xml_path ): array {
		$shape = array(
			'root_element' => '',
			'element_samples' => array(),
			'local_element_samples' => array(),
			'parse_errors' => array(),
		);

		$previous_error_mode = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = new \XMLReader();
		if ( ! $reader->open( $xml_path ) ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_error_mode );
			return $shape;
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			if ( '' === $shape['root_element'] ) {
				$shape['root_element'] = $reader->name;
			}

			if ( ! in_array( $reader->name, $shape['element_samples'], true ) ) {
				$shape['element_samples'][] = $reader->name;
			}

			$local_name = (string) $reader->localName;
			if ( '' !== $local_name && ! in_array( $local_name, $shape['local_element_samples'], true ) ) {
				$shape['local_element_samples'][] = $local_name;
			}

			if ( count( $shape['element_samples'] ) >= 5 && count( $shape['local_element_samples'] ) >= 5 ) {
				break;
			}
		}

		$reader->close();

		$libxml_errors = libxml_get_errors();
		if ( ! empty( $libxml_errors ) ) {
			foreach ( array_slice( $libxml_errors, 0, 3 ) as $error ) {
				if ( ! $error instanceof \LibXMLError ) {
					continue;
				}

				$shape['parse_errors'][] = sprintf(
					/* translators: 1: line number, 2: column number, 3: parse message. */
					__( 'Line %1$d, column %2$d: %3$s', 'pastperfect-wp' ),
					absint( $error->line ),
					absint( $error->column ),
					trim( preg_replace( '/\s+/', ' ', (string) $error->message ) )
				);
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_error_mode );

		return $shape;
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
