<?php

namespace PastPerfect\Archive\Endpoints\V1;

use PastPerfect\Archive\Record;

/**
 * REST API endpoint.
 *
 * @since 1.0.0
 */
class Endpoint {
	protected string $namespace = 'pastperfect';
	protected string $api_version = 'v1';

	/**
	 * Hook into WordPress.
	 *
	 * @since 1.0.0
	 */
	public function set_up_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Register route.
	 *
	 * @since 1.0.0
	 */
	public function register_rest_route(): void {
		register_rest_route(
			"{$this->namespace}/{$this->api_version}",
			'/record/(?P<identifier>[^/]+)',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback' => array( $this, 'get_record' ),
				'args' => array(
					'identifier' => array(
						'description' => __( 'Unique PastPerfect identifier.', 'pastperfect-wp' ),
						'type' => 'string',
						'required' => true,
						'sanitize_callback' => array( $this, 'sanitize_identifier' ),
						'validate_callback' => array( $this, 'validate_identifier' ),
					),
				),
			)
		);
	}

	/**
	 * Sanitize route argument.
	 *
	 * @param mixed $identifier Identifier parameter.
	 */
	public function sanitize_identifier( $identifier ): string {
		return sanitize_text_field( rawurldecode( (string) $identifier ) );
	}

	/**
	 * Validate route argument.
	 *
	 * @param mixed $identifier Identifier parameter.
	 */
	public function validate_identifier( $identifier ): bool {
		$identifier = trim( (string) $identifier );
		return '' !== $identifier;
	}

	/**
	 * Handle record requests.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 */
	public function get_record( \WP_REST_Request $request ) {
		$identifier = $request->get_param( 'identifier' );
		$identifier = $this->sanitize_identifier( $identifier );

		if ( ! $this->validate_identifier( $identifier ) ) {
			return new \WP_Error( 'pastperfect_no_identifier', __( 'No identifier provided.', 'pastperfect-wp' ), array( 'status' => 400 ) );
		}

		$r = new Record();
		$record_id = $r->get_post_id_by_identifier( $identifier );

		if ( ! $record_id ) {
			return new \WP_Error( 'pastperfect_no_identifier', __( 'No record found matching that identifier.', 'pastperfect-wp' ), array( 'status' => 404 ) );
		}

		$record = new Record( $record_id );
		return rest_ensure_response( $record->format_for_endpoint( 1 ) );
	}
}
