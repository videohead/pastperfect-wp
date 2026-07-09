<?php

class ppwp_Tests_MediaIndex extends WP_UnitTestCase {
	private $created_files = array();
	private $created_dirs = array();

	public function set_up() {
		parent::set_up();
		PastPerfect\Archive\MediaIndex::activate();
	}

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

	public function test_index_and_lookup_for_pp5_share_roots() {
		$root = $this->create_temp_dir( 'pp5_share' );
		$images = $this->create_temp_dir( 'Images', $root );
		$multimedia = $this->create_temp_dir( 'Multimedia', $root );

		$image = $images . '/200322.jpg';
		$audio = $multimedia . '/200322-2.mp3';
		file_put_contents( $image, 'image-data' );
		file_put_contents( $audio, 'audio-data' );
		$this->created_files[] = $image;
		$this->created_files[] = $audio;

		$result = PastPerfect\Archive\MediaIndex::index_source(
			$root,
			array(
				'rebuild' => true,
				'prune' => true,
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 2, $result['scanned'] );
		$this->assertTrue( PastPerfect\Archive\MediaIndex::has_index_for_source( $root ) );

		$resolved = PastPerfect\Archive\MediaIndex::find_match_for_reference( 'Images/200322.jpg', $root );
		$this->assertSame( wp_normalize_path( $image ), wp_normalize_path( $resolved ) );

		$identifier_matches = PastPerfect\Archive\MediaIndex::find_matches_for_identifier( '2003.2.2', $root );
		$this->assertCount( 2, $identifier_matches );
	}

	public function test_prune_removes_missing_file_entries() {
		$root = $this->create_temp_dir( 'pp5_share' );
		$images = $this->create_temp_dir( 'Images', $root );

		$file_one = $images . '/200322.jpg';
		$file_two = $images . '/200322-2.jpg';
		file_put_contents( $file_one, 'image-1' );
		file_put_contents( $file_two, 'image-2' );
		$this->created_files[] = $file_one;
		$this->created_files[] = $file_two;

		PastPerfect\Archive\MediaIndex::index_source( $root, array( 'rebuild' => true, 'prune' => true ) );
		$this->assertCount( 2, PastPerfect\Archive\MediaIndex::find_matches_for_identifier( '2003.2.2', $root ) );

		unlink( $file_two );
		$this->created_files = array_values( array_filter( $this->created_files, static function( $file ) use ( $file_two ) {
			return $file !== $file_two;
		} ) );

		PastPerfect\Archive\MediaIndex::index_source( $root, array( 'prune' => true ) );
		$this->assertCount( 1, PastPerfect\Archive\MediaIndex::find_matches_for_identifier( '2003.2.2', $root ) );
	}

	private function create_temp_dir( $name, $parent = null ) {
		$base = is_string( $parent ) && '' !== $parent ? $parent : sys_get_temp_dir();
		$dir = trailingslashit( $base ) . $name . '-' . wp_rand( 1000, 9999 ) . '-' . time();
		wp_mkdir_p( $dir );
		$this->created_dirs[] = $dir;

		return $dir;
	}
}