<?php

class ppwp_Tests_Endpoints extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function test_routes_include_permission_callback() {
		$server = rest_get_server();
		$routes = $server->get_routes();

		$v1_route = '/pastperfect/v1/record/(?P<identifier>[^/]+)';
		$v2_route = '/pastperfect/v2/record/(?P<identifier>[^/]+)';

		$this->assertArrayHasKey( $v1_route, $routes );
		$this->assertArrayHasKey( $v2_route, $routes );

		$this->assertArrayHasKey( 'permission_callback', $routes[ $v1_route ][0] );
		$this->assertArrayHasKey( 'permission_callback', $routes[ $v2_route ][0] );

		$this->assertTrue( is_callable( $routes[ $v1_route ][0]['permission_callback'] ) );
		$this->assertTrue( is_callable( $routes[ $v2_route ][0]['permission_callback'] ) );
	}

	public function test_invalid_identifier_returns_400() {
		$request = new WP_REST_Request( 'GET', '/pastperfect/v2/record/%20%20' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pastperfect_no_identifier', $response->get_data()['code'] );
	}

	public function test_unknown_identifier_returns_404() {
		$request = new WP_REST_Request( 'GET', '/pastperfect/v2/record/unknown-identifier-001' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pastperfect_no_identifier', $response->get_data()['code'] );
	}

	public function test_known_identifier_returns_200_with_payload_v1_and_v2() {
		$identifier = 'api-known-001';
		$this->create_record( $identifier );

		$v1 = new WP_REST_Request( 'GET', '/pastperfect/v1/record/' . $identifier );
		$v1_response = rest_get_server()->dispatch( $v1 );
		$v1_data = $v1_response->get_data();

		$this->assertSame( 200, $v1_response->get_status() );
		$this->assertArrayHasKey( 'identifier', $v1_data );
		$this->assertArrayHasKey( 'title', $v1_data );
		$this->assertSame( $identifier, $v1_data['identifier'] );
		$this->assertSame( 'API Record Title', $v1_data['title'] );

		$v2 = new WP_REST_Request( 'GET', '/pastperfect/v2/record/' . $identifier );
		$v2_response = rest_get_server()->dispatch( $v2 );
		$v2_data = $v2_response->get_data();

		$this->assertSame( 200, $v2_response->get_status() );
		$this->assertArrayHasKey( 'identifier', $v2_data );
		$this->assertArrayHasKey( 'title', $v2_data );
		$this->assertSame( $identifier, $v2_data['identifier'] );
		$this->assertSame( 'API Record Title', $v2_data['title'] );
	}

	private function create_record( $identifier ) {
		$record = new PastPerfect\Archive\Record();
		$record->set_up_from_raw_atts(
			array(
				'identifier' => $identifier,
				'title' => 'API Record Title',
				'description' => 'API integration test record.',
			)
		);

		$post_id = $record->save();
		$this->assertNotEmpty( $post_id );

		return $post_id;
	}
}
