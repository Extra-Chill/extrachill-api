<?php
/**
 * Tests for protected public booking inquiry admission.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the facade without duplicating Events domain behavior. */
class Booking_InquiriesTest extends WP_UnitTestCase {

	/** @var array */
	private $ability_inputs = array();

	/** @var array */
	private $temporary_files = array();

	public function set_up() {
		parent::set_up();
		$this->ability_inputs  = array();
		$this->temporary_files = array();
		if ( isset( wp_get_abilities()[ EXTRACHILL_API_BOOKING_ABILITY ] ) ) {
			wp_unregister_ability( EXTRACHILL_API_BOOKING_ABILITY );
		}
		$this->register_booking_ability();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
	}

	public function tear_down() {
		remove_all_filters( 'extrachill_bypass_turnstile_verification' );
		remove_all_filters( 'extrachill_api_booking_inquiry_rate_limit' );
		remove_all_filters( 'extrachill_api_allow_test_booking_file' );
		foreach ( $this->temporary_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		if ( isset( wp_get_abilities()[ EXTRACHILL_API_BOOKING_ABILITY ] ) ) {
			wp_unregister_ability( EXTRACHILL_API_BOOKING_ABILITY );
		}
		if ( isset( wp_get_ability_categories()['test'] ) ) {
			wp_unregister_ability_category( 'test' );
		}
		parent::tear_down();
	}

	public function test_route_is_registered_for_anonymous_callers() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries', $routes );
		$this->assertSame( 'extrachill_api_booking_inquiry_permission', $routes['/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries'][0]['permission_callback'] );
	}

	public function test_route_affinity_uses_events_segment_boundary() {
		$map = extrachill_api_add_booking_route_affinity( array() );

		$this->assertSame( 'events', $map['/extrachill/v1/venues/'] );
		$this->assertStringStartsWith( '/extrachill/v1/venues/', '/extrachill/v1/venues/42/booking-inquiries' );
		$this->assertStringStartsNotWith( '/extrachill/v1/venues/', '/extrachill/v1/venuesfoo/42/booking-inquiries' );
	}

	public function test_anonymous_submission_invokes_hidden_ability() {
		$request = $this->valid_request();
		$result  = extrachill_api_handle_booking_inquiry( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 42, $this->ability_inputs[0]['venue_term_id'] );
		$this->assertArrayNotHasKey( 'user_id', $this->ability_inputs[0] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_submitted_identity_is_never_forwarded() {
		$request = $this->valid_request();
		$request->set_param( 'user_id', 999 );
		$request->set_param( 'submitter_user_id', 998 );
		extrachill_api_handle_booking_inquiry( $request );

		$this->assertArrayNotHasKey( 'user_id', $this->ability_inputs[0] );
		$this->assertArrayNotHasKey( 'submitter_user_id', $this->ability_inputs[0] );
	}

	public function test_authenticated_identity_remains_request_identity() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		extrachill_api_handle_booking_inquiry( $this->valid_request() );

		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertArrayNotHasKey( 'user_id', $this->ability_inputs[0] );
	}

	public function test_missing_turnstile_primitive_fails_before_ability_execution() {
		$request = $this->valid_request();
		$request->set_param( 'turnstile_response', '' );
		$result = extrachill_api_booking_inquiry_permission( $request );

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'booking_security_unavailable', 'turnstile_missing_token' ) );
		$this->assertEmpty( $this->ability_inputs );
	}

	public function test_rate_limit_returns_stable_429() {
		$request = $this->valid_request();
		$scope   = 'booking-test-' . wp_generate_uuid4();
		$this->assertTrue( extrachill_api_check_public_write_rate_limit( $request, $scope, 1 ) );
		$result = extrachill_api_check_public_write_rate_limit( $request, $scope, 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'public_write_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( '60', $result->get_error_data()['headers']['Retry-After'] );
	}

	public function test_unsigned_remote_address_is_not_trusted() {
		$request = $this->valid_request();
		$request->set_param( '_ec_affinity_remote_addr', '203.0.113.99' );

		$this->assertNotSame( '203.0.113.99', $_SERVER['REMOTE_ADDR'] );
		$this->assertNotSame( '1', $request->get_header( 'X-EC-Affinity-Verified' ) );
	}

	public function test_duplicate_retry_preserves_exact_ability_input() {
		$request = $this->valid_request();
		$first   = extrachill_api_handle_booking_inquiry( $request );
		$second  = extrachill_api_handle_booking_inquiry( $request );

		$this->assertSame( $first->get_data(), $second->get_data() );
		$this->assertSame( $this->ability_inputs[0], $this->ability_inputs[1] );
	}

	public function test_multipart_normalization_passes_only_events_admission_fields() {
		$request = $this->valid_request();
		$file    = $this->uploaded_file( 'press.txt', 'press notes' );
		$request->set_file_params( array( 'attachments' => $file ) );
		$request->set_param( 'attachment_purposes', array( 'press_release' ) );

		$files = extrachill_api_normalize_booking_files( $request );
		$input = extrachill_api_booking_ability_input( $request, $files );

		$this->assertCount( 1, $files );
		$this->assertSame( 'press_release', $files[0]['purpose'] );
		$this->assertSame(
			array( 'name', 'tmp_name', 'error', 'size', 'purpose' ),
			array_keys( $input['attachments'][0] )
		);
		$this->assertSame( $file['tmp_name'], $input['attachments'][0]['tmp_name'] );
		$this->assertArrayNotHasKey( '_attachment_admission', $input['intake'] );
	}

	public function test_multipart_upload_and_purpose_failures_are_stable() {
		$request = $this->valid_request();
		$file    = $this->uploaded_file( 'press.txt', 'press notes' );
		$file['error'] = UPLOAD_ERR_PARTIAL;
		$request->set_file_params( array( 'attachments' => $file ) );
		$request->set_param( 'attachment_purposes', array( 'press_release' ) );
		$result = extrachill_api_normalize_booking_files( $request );
		$this->assertSame( 'booking_attachment_upload_failed', $result->get_error_code() );

		$file['error'] = UPLOAD_ERR_OK;
		$request->set_file_params( array( 'attachments' => $file ) );
		$request->set_param( 'attachment_purposes', array() );
		$result = extrachill_api_normalize_booking_files( $request );
		$this->assertSame( 'booking_attachment_purpose_mismatch', $result->get_error_code() );
	}

	public function test_verified_multipart_rejects_changed_bytes() {
		$request = $this->valid_request();
		$file    = $this->uploaded_file( 'press.txt', 'changed bytes' );
		$request->set_file_params( array( 'attachments' => $file ) );
		$request->set_param( 'attachment_purposes', array( 'press_release' ) );
		$request->set_param( EXTRACHILL_API_BOOKING_FILES, wp_json_encode( array( array( 'name' => 'press.txt', 'size' => 1, 'purpose' => 'press_release', 'hash' => str_repeat( 'a', 64 ) ) ) ) );
		$request->set_header( 'X-EC-Affinity-Verified', '1' );

		$result = extrachill_api_normalize_booking_files( $request );
		$this->assertSame( 'booking_attachment_transport_invalid', $result->get_error_code() );
	}

	public function test_route_fields_cover_backing_ability_schema() {
		$args    = extrachill_api_booking_inquiry_args();
		$ability = wp_get_abilities()[ EXTRACHILL_API_BOOKING_ABILITY ];
		$schema  = $ability->get_input_schema();
		$mapped  = array_diff( array_keys( $args ), array( 'venue', 'turnstile_response', 'attachment_purposes' ) );
		$mapped[] = 'venue_term_id';
		$mapped[] = 'attachments';

		$this->assertEmpty( array_diff( $schema['required'], $mapped ) );
		$this->assertEmpty( array_diff( $mapped, array_keys( $schema['properties'] ) ) );
	}

	public function test_hidden_ability_cannot_run_through_generic_rest() {
		$controller = new WP_REST_Abilities_V1_Run_Controller();
		$request    = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . EXTRACHILL_API_BOOKING_ABILITY . '/run' );
		$request->set_param( 'name', EXTRACHILL_API_BOOKING_ABILITY );
		$result = $controller->check_ability_permissions( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_ability_not_found', $result->get_error_code() );
	}

	public function test_domain_errors_map_to_stable_public_statuses() {
		$conflict = extrachill_api_booking_public_error( new WP_Error( 'booking_idempotency_conflict', 'Conflict.', array( 'status' => 409 ) ) );
		$internal = extrachill_api_booking_public_error( new WP_Error( 'booking_read_failed', 'Database details.' ) );

		$this->assertSame( 409, $conflict->get_error_data()['status'] );
		$this->assertSame( 'booking_idempotency_conflict', $conflict->get_error_code() );
		$this->assertSame( 503, $internal->get_error_data()['status'] );
		$this->assertSame( 'booking_inquiry_unavailable', $internal->get_error_code() );
		$this->assertStringNotContainsString( 'Database', $internal->get_error_message() );
	}

	public function test_attachment_admission_does_not_construct_events_domain_services() {
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/routes/events/booking-inquiries.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$this->assertStringNotContainsString( 'ExtraChillEvents\\Core', $source );
		$this->assertStringNotContainsString( 'storage_reference', $source );
		$this->assertStringNotContainsString( '_attachment_admission', $source );
	}

	private function register_booking_ability() {
		$test = $this;
		$register_category = static function () {
			wp_register_ability_category(
				'test',
				array(
					'label'       => 'Test',
					'description' => 'Test-only ability contracts.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $register_category );
		do_action( 'wp_abilities_api_categories_init' );
		remove_action( 'wp_abilities_api_categories_init', $register_category );

		$register = static function () use ( $test ) {
			wp_register_ability(
				EXTRACHILL_API_BOOKING_ABILITY,
				array(
					'label'               => 'Test booking inquiry',
					'description'         => 'Test hidden public booking inquiry contract.',
					'category'            => 'test',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'idempotency_key'     => array( 'type' => 'string' ),
							'venue_term_id'       => array( 'type' => 'integer' ),
							'artist_term_id'      => array( 'type' => array( 'integer', 'null' ) ),
							'artist_profile_id'   => array( 'type' => array( 'integer', 'null' ) ),
							'artist_name'         => array( 'type' => 'string' ),
							'contact_name'        => array( 'type' => array( 'string', 'null' ) ),
							'contact_email'       => array( 'type' => array( 'string', 'null' ) ),
							'contact_phone'       => array( 'type' => array( 'string', 'null' ) ),
							'requested_space_key' => array( 'type' => array( 'string', 'null' ) ),
							'requested_start_at'  => array( 'type' => array( 'string', 'null' ) ),
							'requested_end_at'    => array( 'type' => array( 'string', 'null' ) ),
							'intake'              => array( 'type' => 'object' ),
							'attachments'         => array( 'type' => 'array' ),
						),
						'required'             => array( 'idempotency_key', 'venue_term_id', 'intake' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static function ( $input ) use ( $test ) {
						$test->ability_inputs[] = $input;
						return array(
							'public_id'     => '11111111-1111-4111-8111-111111111111',
							'venue_term_id' => $input['venue_term_id'],
							'submitted_at'  => '2026-07-24 20:00:00',
						);
					},
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
					),
				)
			);
		};
		add_action( 'wp_abilities_api_init', $register );
		do_action( 'wp_abilities_api_init' );
		remove_action( 'wp_abilities_api_init', $register );
	}

	private function valid_request() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/42/booking-inquiries' );
		$request->set_param( 'venue', 42 );
		$request->set_param( 'idempotency_key', 'request-123' );
		$request->set_param( 'artist_name', 'Test Artist' );
		$request->set_param( 'contact_email', 'artist@example.com' );
		$request->set_param( 'intake', array( 'message' => 'Booking request.' ) );
		$request->set_param( 'turnstile_response', 'test-token' );
		return $request;
	}

	private function uploaded_file( $name, $contents ) {
		$file = tempnam( sys_get_temp_dir(), 'ec-booking-test-' );
		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->temporary_files[] = $file;
		add_filter( 'extrachill_api_allow_test_booking_file', '__return_true' );
		return array(
			'name'     => $name,
			'type'     => 'text/plain',
			'tmp_name' => $file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $file ),
		);
	}
}
