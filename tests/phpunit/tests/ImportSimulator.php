<?php

class ppwp_Tests_ImportSimulator extends WP_UnitTestCase {
	private $created_files = array();
	private $created_dirs = array();

	public function tear_down() {
		foreach ( $this->created_files as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				unlink( $file );
			}
		}

		foreach ( array_reverse( $this->created_dirs ) as $dir ) {
			if ( is_string( $dir ) && is_dir( $dir ) ) {
				@rmdir( $dir );
			}
		}

		$this->created_files = array();
		$this->created_dirs = array();
		parent::tear_down();
	}

	public function test_simulate_reports_expected_totals() {
		libxml_use_internal_errors( true );
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<metadata>\n";
		$xml .= "<dc-record><identifier>sim-1</identifier><title>One</title><relation>photo.jpg</relation></dc-record>\n";
		$xml .= "<dc-record><identifier>sim-1</identifier><title>Duplicate</title></dc-record>\n";
		$xml .= "<dc-record><title>No Identifier</title></dc-record>\n";
		$xml .= "</metadata>\n";

		$file = tempnam( sys_get_temp_dir(), 'ppwp-sim-' );
		$this->created_files[] = $file;
		file_put_contents( $file, $xml );

		$result = PastPerfect\Archive\ImportSimulator::simulate(
			$file,
			array(
				'import_media' => true,
				'media_provider' => 'wp_media_library',
				'media_source_directory' => sys_get_temp_dir(),
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'dc-record', $result['record_element'] );
		$this->assertSame( 3, $result['totals']['records'] );
		$this->assertSame( 1, $result['totals']['missing_identifier'] );
		$this->assertSame( 1, $result['totals']['duplicate_identifiers'] );
		$this->assertSame( 1, $result['totals']['would_create'] );
		$this->assertSame( 1, $result['totals']['would_update'] );
		$this->assertSame( 1, $result['media']['total_references'] );
	}

	public function test_simulate_discovers_identifier_media_in_pp5_share_layout() {
		$pp5_root = $this->create_temp_directory( 'pp5_share' );
		$images = $this->create_temp_directory( 'Images', $pp5_root );
		$multimedia = $this->create_temp_directory( 'Multimedia', $pp5_root );

		$image_file = $images . '/200322.jpg';
		$audio_file = $multimedia . '/200322-2.mp3';
		file_put_contents( $image_file, 'image' );
		file_put_contents( $audio_file, 'audio' );
		$this->created_files[] = $image_file;
		$this->created_files[] = $audio_file;

		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<metadata>\n";
		$xml .= "<dc-record><identifier>2003.2.2</identifier><title>Sample</title></dc-record>\n";
		$xml .= "</metadata>\n";

		$file = tempnam( sys_get_temp_dir(), 'ppwp-sim-' );
		$this->created_files[] = $file;
		file_put_contents( $file, $xml );

		$result = PastPerfect\Archive\ImportSimulator::simulate(
			$file,
			array(
				'import_media' => true,
				'media_provider' => 'wp_media_library',
				'media_source_directory' => $pp5_root,
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 2, $result['media']['total_references'] );
		$this->assertSame( 2, $result['media']['resolvable_references'] );
		$this->assertSame( 0, $result['media']['missing_references'] );
	}

	private function create_temp_directory( $name, $parent = null ) {
		$base = is_string( $parent ) && '' !== $parent ? $parent : sys_get_temp_dir();
		$dir = trailingslashit( $base ) . $name . '-' . wp_rand( 1000, 9999 ) . '-' . time();
		wp_mkdir_p( $dir );
		$this->created_dirs[] = $dir;

		return $dir;
	}
}
