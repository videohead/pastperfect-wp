<?php

class ppwp_Tests_SyncCoordinator extends WP_UnitTestCase {
	private $created_files = array();
	private $created_dirs = array();

	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		$this->reset_sync_state();
	}

	public function tear_down() {
		$this->reset_sync_state();

		foreach ( $this->created_files as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				unlink( $file );
			}
		}

		foreach ( $this->created_dirs as $dir ) {
			if ( is_string( $dir ) && is_dir( $dir ) ) {
				@rmdir( $dir );
			}
		}

		$this->created_files = array();
		$this->created_dirs = array();
		parent::tear_down();
	}

	public function test_pre_and_post_sync_creates_record() {
		$identifier = 'sync-pre-post-001';
		$xml_file = $this->create_xml_file(
			array(
				array(
					'identifier' => $identifier,
					'title' => 'Pre/Post Sync Item',
					'description' => 'Created by scheduled sync test.',
				),
			)
		);

		$record = new PastPerfect\Archive\Record();
		$this->assertNull( $record->get_post_id_by_identifier( $identifier ) );

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => $xml_file,
				'increment' => 1,
			)
		);

		$job_state = PastPerfect\Archive\SyncCoordinator::start_job( 'manual', false );
		$this->assertNotWPError( $job_state );
		$this->assertSame( 'running', $job_state['status'] );

		$final_state = $this->run_job_until_finished();
		$this->assertSame( 'completed', $final_state['status'] );
		$this->assertSame( 1, $final_state['counts']['created'] );
		$this->assertSame( 0, $final_state['counts']['updated'] );
		$this->assertSame( 0, $final_state['counts']['failed'] );

		$post_id = $record->get_post_id_by_identifier( $identifier );
		$this->assertNotEmpty( $post_id );
		$this->assertSame( 'archive_item', get_post_type( $post_id ) );
	}

	public function test_post_sync_updates_existing_record() {
		$identifier = 'sync-update-001';
		$first_xml = $this->create_xml_file(
			array(
				array(
					'identifier' => $identifier,
					'title' => 'Original Item',
					'description' => 'Original description.',
				),
			)
		);

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => $first_xml,
				'increment' => 1,
			)
		);

		$first_state = PastPerfect\Archive\SyncCoordinator::start_job( 'manual', false );
		$this->assertNotWPError( $first_state );
		$this->run_job_until_finished();

		$second_xml = $this->create_xml_file(
			array(
				array(
					'identifier' => $identifier,
					'title' => 'Original Item',
					'description' => 'Updated description from sync.',
				),
			)
		);

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => $second_xml,
				'increment' => 1,
			)
		);

		$second_state = PastPerfect\Archive\SyncCoordinator::start_job( 'manual', false );
		$this->assertNotWPError( $second_state );

		$final_state = $this->run_job_until_finished();
		$this->assertSame( 'completed', $final_state['status'] );
		$this->assertSame( 1, $final_state['counts']['updated'] );
		$this->assertSame( 0, $final_state['counts']['failed'] );

		$record = new PastPerfect\Archive\Record();
		$post_id = $record->get_post_id_by_identifier( $identifier );
		$this->assertNotEmpty( $post_id );
		$this->assertSame( 'Updated description from sync.', get_post_field( 'post_content', $post_id ) );
	}

	public function test_enabling_sync_schedules_recurring_event() {
		$xml_file = $this->create_xml_file(
			array(
				array(
					'identifier' => 'sync-schedule-001',
					'title' => 'Schedule Item',
					'description' => 'Schedule test record.',
				),
			)
		);

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => true,
				'recurrence' => 'daily',
				'source' => $xml_file,
				'increment' => 2,
			)
		);

		$next = wp_next_scheduled( PastPerfect\Archive\SyncCoordinator::EVENT_RECURRING_START );
		$this->assertNotFalse( $next );
	}

	public function test_source_provider_defaults_to_xml() {
		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => '',
				'increment' => 10,
			)
		);

		$settings = PastPerfect\Archive\SyncCoordinator::get_settings();
		$this->assertSame( 'xml', $settings['source_provider'] );
	}

	public function test_unknown_source_provider_falls_back_to_xml() {
		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => '/tmp/sample.xml',
				'source_provider' => 'bogus_provider',
				'increment' => 10,
			)
		);

		$settings = PastPerfect\Archive\SyncCoordinator::get_settings();
		$this->assertSame( 'xml', $settings['source_provider'] );
	}

	public function test_media_index_refresh_schedule_disabled_by_default() {
		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => '',
				'increment' => 10,
				'media_index_refresh_enabled' => false,
				'media_index_refresh_recurrence' => 'daily',
			)
		);

		$next = wp_next_scheduled( PastPerfect\Archive\SyncCoordinator::EVENT_MEDIA_INDEX_REFRESH );
		$this->assertFalse( $next );
	}

	public function test_media_index_refresh_schedule_enabled_with_source() {
		$source_dir = $this->create_temp_directory();

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => '',
				'increment' => 10,
				'import_media' => true,
				'media_provider' => 'wp_media_library',
				'media_source_directory' => $source_dir,
				'media_remote_base_url' => '',
				'media_index_refresh_enabled' => true,
				'media_index_refresh_recurrence' => 'daily',
			)
		);

		$next = wp_next_scheduled( PastPerfect\Archive\SyncCoordinator::EVENT_MEDIA_INDEX_REFRESH );
		$this->assertNotFalse( $next );
	}

	public function test_media_library_import_stores_media_without_date_subfolders() {
		$identifier = 'sync-media-001';
		$source_dir = $this->create_temp_directory();
		$media_file = $source_dir . '/asset.txt';
		file_put_contents( $media_file, 'media content for import test' );
		$this->created_files[] = $media_file;

		$xml_file = $this->create_xml_file(
			array(
				array(
					'identifier' => $identifier,
					'title' => 'Media Import Item',
					'description' => 'Media import test.',
					'relation' => array( 'asset.txt' ),
				),
			)
		);

		$previous_setting = get_option( 'uploads_use_yearmonth_folders', 1 );
		update_option( 'uploads_use_yearmonth_folders', 1 );

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => $xml_file,
				'increment' => 1,
				'import_media' => true,
				'media_provider' => 'wp_media_library',
				'media_source_directory' => $source_dir,
				'media_remote_base_url' => '',
			)
		);

		$job_state = PastPerfect\Archive\SyncCoordinator::start_job( 'manual', false );
		$this->assertNotWPError( $job_state );
		$final_state = $this->run_job_until_finished();
		$this->assertSame( 'completed', $final_state['status'] );

		$record = new PastPerfect\Archive\Record();
		$post_id = $record->get_post_id_by_identifier( $identifier );
		$this->assertNotEmpty( $post_id );

		$relation_meta = get_post_meta( $post_id, 'pastperfect_dc_relation', false );
		$this->assertNotEmpty( $relation_meta );

		$upload_dir = wp_get_upload_dir();
		$attachment_ids = get_posts(
			array(
				'post_type' => 'attachment',
				'post_parent' => $post_id,
				'fields' => 'ids',
				'posts_per_page' => 1,
			)
		);

		$this->assertNotEmpty( $attachment_ids );
		$attachment_file = get_attached_file( (int) $attachment_ids[0] );
		$this->assertSame( wp_normalize_path( $upload_dir['basedir'] ), wp_normalize_path( dirname( $attachment_file ) ) );

		update_option( 'uploads_use_yearmonth_folders', $previous_setting );
	}

	public function test_media_is_discovered_from_identifier_filename_pattern() {
		$identifier = '2003.2.2';
		$source_dir = $this->create_temp_directory();

		$media_one = $source_dir . '/200322.jpg';
		$media_two = $source_dir . '/200322-2.jpg';
		file_put_contents( $media_one, 'mock-image-data-1' );
		file_put_contents( $media_two, 'mock-image-data-2' );
		$this->created_files[] = $media_one;
		$this->created_files[] = $media_two;

		$xml_file = $this->create_xml_file(
			array(
				array(
					'identifier' => $identifier,
					'title' => 'Identifier Pattern Media Item',
					'description' => 'No explicit relation media entries; infer from identifier.',
				),
			)
		);

		PastPerfect\Archive\SyncCoordinator::update_settings(
			array(
				'enabled' => false,
				'recurrence' => 'daily',
				'source' => $xml_file,
				'increment' => 1,
				'import_media' => true,
				'media_provider' => 'wp_media_library',
				'media_source_directory' => $source_dir,
				'media_remote_base_url' => '',
			)
		);

		$job_state = PastPerfect\Archive\SyncCoordinator::start_job( 'manual', false );
		$this->assertNotWPError( $job_state );
		$final_state = $this->run_job_until_finished();
		$this->assertSame( 'completed', $final_state['status'] );

		$record = new PastPerfect\Archive\Record();
		$post_id = $record->get_post_id_by_identifier( $identifier );
		$this->assertNotEmpty( $post_id );

		$attachment_ids = get_posts(
			array(
				'post_type' => 'attachment',
				'post_parent' => $post_id,
				'fields' => 'ids',
				'posts_per_page' => 10,
			)
		);

		$this->assertCount( 2, $attachment_ids );
	}

	private function run_job_until_finished() {
		$max_loops = 20;
		$state = get_option( PastPerfect\Archive\SyncCoordinator::OPTION_JOB_STATE, array() );

		for ( $i = 0; $i < $max_loops; $i++ ) {
			if ( ! is_array( $state ) || ( $state['status'] ?? '' ) !== 'running' ) {
				break;
			}

			PastPerfect\Archive\SyncCoordinator::process_job();
			$state = get_option( PastPerfect\Archive\SyncCoordinator::OPTION_JOB_STATE, array() );
		}

		if ( ( $state['status'] ?? '' ) === 'running' ) {
			$this->fail( 'Sync job did not finish within loop limit.' );
		}

		return $state;
	}

	private function reset_sync_state() {
		wp_clear_scheduled_hook( PastPerfect\Archive\SyncCoordinator::EVENT_RECURRING_START );
		wp_clear_scheduled_hook( PastPerfect\Archive\SyncCoordinator::EVENT_PROCESS_CHUNK );
		wp_clear_scheduled_hook( PastPerfect\Archive\SyncCoordinator::EVENT_MEDIA_INDEX_REFRESH );
		delete_option( PastPerfect\Archive\SyncCoordinator::OPTION_SETTINGS );
		delete_option( PastPerfect\Archive\SyncCoordinator::OPTION_JOB_STATE );
		delete_transient( PastPerfect\Archive\SyncCoordinator::LOCK_KEY );
	}

	/**
	 * @param array $records List of associative arrays keyed by DC field name.
	 */
	private function create_xml_file( array $records ) {
		$tmp = tempnam( sys_get_temp_dir(), 'ppwp-sync-' );
		$this->created_files[] = $tmp;

		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n";
		foreach ( $records as $record ) {
			$xml .= "\t<record>\n";
			foreach ( $record as $key => $value ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $item ) {
						$xml .= sprintf( "\t\t<%s>%s</%s>\n", esc_xml( $key ), esc_xml( $item ), esc_xml( $key ) );
					}
				} else {
					$xml .= sprintf( "\t\t<%s>%s</%s>\n", esc_xml( $key ), esc_xml( $value ), esc_xml( $key ) );
				}
			}
			$xml .= "\t</record>\n";
		}
		$xml .= "</records>\n";

		file_put_contents( $tmp, $xml );
		return $tmp;
	}

	private function create_temp_directory() {
		$dir = sys_get_temp_dir() . '/ppwp-media-' . wp_rand( 1000, 9999 ) . '-' . time();
		wp_mkdir_p( $dir );
		$this->created_dirs[] = $dir;

		return $dir;
	}
}
