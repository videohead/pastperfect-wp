<?php

class ppwp_Tests_Record extends WP_UnitTestCase {
	protected $data = array(
		'contributor' => array( 'Robert Sember' ),
		'coverage' => 'Brooklyn, New York, N.Y.',
		'creator' => array( 'Melinda Broman' ),
		'date' => '1992/06/20',
		'description' => 'Oral history interview metadata sample.',
		'format' => 'Sound recording',
		'identifier' => '1993.001.01',
		'language' => 'English',
		'publisher' => 'Brooklyn Historical Society',
		'relation' => array( 'http://example.com/findingaid.xml', 'http://example.com/asset.jpg' ),
		'rights' => 'Rights statement sample.',
		'source' => 'AIDS/Brooklyn Oral History Project',
		'subject' => array( 'Audio', 'AIDS (Disease)', 'Hemophilia' ),
		'title' => 'Oral History',
		'type' => 'Sound',
	);

	public function test_set_up_from_raw_atts() {
		$record = new PastPerfect\Archive\Record();
		$this->assertTrue( $record->set_up_from_raw_atts( $this->data ) );

		foreach ( $this->data as $k => $v ) {
			$this->assertSame( $v, $record->get_dc_metadata( $k, false ) );
		}
	}

	public function test_save_should_return_post_id() {
		$record = new PastPerfect\Archive\Record();
		$record->set_up_from_raw_atts( $this->data );

		$found = $record->save();
		$this->assertTrue( is_int( $found ) );
	}

	public function test_save_should_not_create_new_object_for_same_identifier() {
		$identifier = 'foo';

		$r1 = new PastPerfect\Archive\Record();
		$r1->set_dc_metadata( 'identifier', array( $identifier ) );
		$p1 = $r1->save();

		$r2 = new PastPerfect\Archive\Record();
		$r2->set_dc_metadata( 'identifier', array( $identifier ) );
		$p2 = $r2->save();

		$this->assertSame( $p1, $p2 );
	}

	public function test_save_should_create_post_title() {
		$record = new PastPerfect\Archive\Record();
		$record->set_up_from_raw_atts( $this->data );
		$post_id = $record->save();
		$post = get_post( $post_id );

		$expected = '1993.001.01 - Oral History';
		$this->assertSame( $expected, $post->post_title );
	}

	public function test_save_should_create_post_content() {
		$record = new PastPerfect\Archive\Record();
		$record->set_up_from_raw_atts( $this->data );
		$post_id = $record->save();
		$post = get_post( $post_id );

		$this->assertSame( $this->data['description'], $post->post_content );
	}

	public function test_save_should_create_subject_terms() {
		$record = new PastPerfect\Archive\Record();
		$record->set_up_from_raw_atts( $this->data );
		$post_id = $record->save();

		$found = wp_get_object_terms( $post_id, 'archive_subject' );
		$names = array();
		foreach ( $found as $f ) {
			$names[] = $f->name;
		}

		$this->assertEqualSets( $names, $this->data['subject'] );
	}

	public function test_save_should_store_dc_metadata() {
		$record = new PastPerfect\Archive\Record();
		$record->set_up_from_raw_atts( $this->data );
		$post_id = $record->save();

		$r2 = new PastPerfect\Archive\Record( $post_id );

		foreach ( $this->data as $k => $v ) {
			if ( is_array( $v ) ) {
				$this->assertEqualSets( $v, $r2->get_dc_metadata( $k, false ) );
			} else {
				$this->assertSame( $v, $r2->get_dc_metadata( $k, false ) );
			}
		}
	}

	public function test_get_post_id_by_identifier() {
		$identifier = 'foo';

		$record = new PastPerfect\Archive\Record();
		$record->set_dc_metadata( 'identifier', 'foo' );
		$post_id = $record->save();

		$r = new PastPerfect\Archive\Record();
		$found = $r->get_post_id_by_identifier( $identifier );
		$this->assertSame( $post_id, $found );
	}
}
