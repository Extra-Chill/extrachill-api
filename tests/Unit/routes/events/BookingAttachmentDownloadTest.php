<?php
/**
 * Protected booking attachment download transport tests.
 *
 * @package ExtraChill\API\Tests
 */

namespace ExtraChillEvents\Core {
	/** Controlled Events service contract used by the API transport tests. */
	class BookingAttachmentService {
		/** @var array<string, bool> */
		public static $consumed = array();

		/** Consume one test handoff and return private bytes. */
		public function open_download_stream( int $booking_id, int $attachment_id, string $token, int $actor_id ) {
			unset( $attachment_id, $actor_id );
			if ( in_array( $booking_id, array( 4, 5, 6 ), true ) || isset( self::$consumed[ $token ] ) ) {
				return new \WP_Error( 'private_stream_unavailable', 'Internal reference /private/object/leak', array( 'status' => 403 ) );
			}
			self::$consumed[ $token ] = true;
			$stream                    = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'private booking bytes' );
			rewind( $stream );
			return $stream;
		}
	}
}

namespace {
	/** Exercises the real REST adapter around controlled Events contracts. */
	class Booking_Attachment_DownloadTest extends WP_UnitTestCase {

		/** @var string[] */
		private $registered_abilities = array();

		/** @var array<int, array<string, int>> */
		private $audit = array();

		/** Register a controlled hidden Events ability. */
		public function set_up() {
			parent::set_up();
			\ExtraChillEvents\Core\BookingAttachmentService::$consumed = array();
			$GLOBALS['extrachill_api_private_streams']                    = array();
			wp_set_current_user( 0 );

			$categories = WP_Ability_Categories_Registry::get_instance();
			if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
				$categories->register(
					'extrachill-api-tests',
					array(
						'label'       => 'Extra Chill API Tests',
						'description' => 'Controlled private transport contracts.',
					)
				);
			}

			$this->register_ability();
			do_action( 'rest_api_init' );
		}

		/** Remove controlled state and any unserved streams. */
		public function tear_down() {
			extrachill_api_cleanup_private_streams();
			foreach ( $this->registered_abilities as $name ) {
				wp_unregister_ability( $name );
			}
			wp_set_current_user( 0 );
			parent::tear_down();
		}

		/** Anonymous requests fail before Events is called and remain non-cacheable. */
		public function test_anonymous_request_fails_closed_without_audit() {
			$request  = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$response = rest_do_request( $request );
			$response = extrachill_api_protect_booking_attachment_download_response( $response, rest_get_server(), $request );

			$this->assertSame( 401, $response->get_status() );
			$this->assertSame( 'booking_attachment_download_unavailable', $response->get_data()['code'] );
			$this->assertStringContainsString( 'no-store', $this->response_header( $response, 'cache-control' ) );
			$this->assertSame( array(), $this->audit );
		}

		/** Cross-venue and revoked callers receive the same generic response. */
		public function test_cross_venue_and_revoked_access_are_indistinguishable() {
			wp_set_current_user( self::factory()->user->create() );
			foreach ( array( 2, 3 ) as $booking_id ) {
				$response = $this->dispatch( $booking_id, 1 );
				$this->assertSame( 404, $response->get_status() );
				$this->assertSame( 'booking_attachment_download_unavailable', $response->get_data()['code'] );
				$this->assertStringNotContainsString( 'venue', wp_json_encode( $response->get_data() ) );
			}
			$this->assertSame( array(), $this->audit );
		}

		/** Expired, replayed, and tampered handoffs fail after Events reauthorization. */
		public function test_expired_replayed_and_tampered_handoffs_fail_generically() {
			wp_set_current_user( self::factory()->user->create() );
			foreach ( array( 4, 5, 6 ) as $booking_id ) {
				$response = $this->dispatch( $booking_id, 1 );
				$this->assertSame( 404, $response->get_status() );
				$this->assertStringNotContainsString( 'private', wp_json_encode( $response->get_data() ) );
			}

			$first = $this->dispatch( 7, 1 );
			$this->assertSame( 200, $first->get_status() );
			$this->serve( $first );
			$replay = $this->dispatch( 7, 1 );
			$this->assertSame( 404, $replay->get_status() );
		}

		/** Success exposes only safe headers and manually serves the private bytes. */
		public function test_success_is_no_store_auditable_and_reference_free() {
			$user_id = self::factory()->user->create();
			wp_set_current_user( $user_id );
			$response = $this->dispatch( 1, 9 );
			$headers  = $response->get_headers();

			$this->assertSame( 200, $response->get_status() );
			$this->assertNull( $response->get_data() );
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
			$this->assertSame( 'application/pdf', $headers['Content-Type'] );
			$this->assertStringContainsString( 'safe-rider.pdf', $headers['Content-Disposition'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/i', $headers['X-EC-Download-Correlation'] );
			$this->assertSame( array( array( 'booking_id' => 1, 'attachment_id' => 9, 'actor_id' => $user_id ) ), $this->audit );
			$this->assertSame( 'private booking bytes', $this->serve( $response ) );
			$this->assertSame( array(), $GLOBALS['extrachill_api_private_streams'] );
		}

		/** Filename and MIME metadata are defensively normalized for HTTP headers. */
		public function test_filename_injection_and_invalid_mime_are_neutralized() {
			$stream = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'x' );
			rewind( $stream );
			$request  = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$response = extrachill_api_create_private_stream_response( $stream, "../bad\r\nX-Leak: yes.pdf", "text/plain\r\nX-Leak: yes", $request );
			$headers  = $response->get_headers();

			$this->assertStringNotContainsString( "\r", $headers['Content-Disposition'] );
			$this->assertStringNotContainsString( "\n", $headers['Content-Disposition'] );
			$this->assertStringNotContainsString( '..', $headers['Content-Disposition'] );
			$this->assertSame( 'application/octet-stream', $headers['Content-Type'] );
			$this->serve( $response );
		}

		/** Single ranges are bounded while multiple and unsatisfiable ranges fail. */
		public function test_range_and_size_limits_are_enforced() {
			$this->assertSame( array( 'offset' => 2, 'length' => 4, 'status' => 206 ), extrachill_api_booking_attachment_range( 'bytes=2-5', 10 ) );
			$this->assertSame( array( 'offset' => 7, 'length' => 3, 'status' => 206 ), extrachill_api_booking_attachment_range( 'bytes=-3', 10 ) );
			$this->assertWPError( extrachill_api_booking_attachment_range( 'bytes=0-1,4-5', 10 ) );
			$this->assertWPError( extrachill_api_booking_attachment_range( 'bytes=20-', 10 ) );

			add_filter( 'extrachill_api_booking_attachment_max_bytes', array( $this, 'one_byte_limit' ) );
			$stream = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'too large' );
			rewind( $stream );
			$result = extrachill_api_create_private_stream_response( $stream, 'file.txt', 'text/plain', new WP_REST_Request( 'GET', '/' ) );
			remove_filter( 'extrachill_api_booking_attachment_max_bytes', array( $this, 'one_byte_limit' ) );
			$this->assertWPError( $result );
			$this->assertSame( 413, $result->get_error_data()['status'] );
		}

		/** Cleanup removes a partially served affinity spool and closes its stream. */
		public function test_manual_stream_cleanup_unlinks_affinity_spool() {
			$path = wp_tempnam( 'booking-download-test' );
			file_put_contents( $path, 'abcdef' );
			chmod( $path, 0600 );
			$stream   = fopen( $path, 'rb' );
			$response = extrachill_api_register_private_stream( $stream, 3, 200, extrachill_api_private_stream_headers( 'file.txt', 'text/plain', 3 ), $path );

			$this->assertSame( 'abc', $this->serve( $response ) );
			$this->assertFileDoesNotExist( $path );
			$this->assertFalse( is_resource( $stream ) );
		}

		/** Rate limiting occurs before a second handoff can be issued. */
		public function test_rate_limit_precedes_handoff_issuance() {
			wp_set_current_user( self::factory()->user->create() );
			add_filter( 'extrachill_api_booking_attachment_download_rate_limit', array( $this, 'one_byte_limit' ) );
			$first = $this->dispatch( 8, 1 );
			$this->assertSame( 200, $first->get_status() );
			$this->serve( $first );
			$second = $this->dispatch( 9, 1 );
			remove_filter( 'extrachill_api_booking_attachment_download_rate_limit', array( $this, 'one_byte_limit' ) );

			$this->assertSame( 429, $second->get_status() );
			$this->assertCount( 1, $this->audit );
		}

		/** Affinity spools preserve private bytes without trusting unsafe headers. */
		public function test_affinity_spool_is_bounded_sanitized_and_cleaned() {
			$path = wp_tempnam( 'booking-affinity-test' );
			file_put_contents( $path, 'part' );
			chmod( $path, 0600 );
			$response = extrachill_api_private_stream_from_affinity_response(
				array(
					'headers'  => array(
						'Content-Length'      => '4',
						'Content-Type'        => 'text/plain',
						'Content-Disposition' => 'attachment; filename="../rider.txt"',
						'Content-Range'       => 'bytes 2-5/10',
					),
					'body'     => '',
					'response' => array( 'code' => 206 ),
				),
				$path
			);

			$this->assertSame( 206, $response->get_status() );
			$this->assertStringNotContainsString( '..', $response->get_headers()['Content-Disposition'] );
			$this->assertSame( 'part', $this->serve( $response ) );
			$this->assertFileDoesNotExist( $path );

			$bad_path = wp_tempnam( 'booking-affinity-test' );
			file_put_contents( $bad_path, 'part' );
			$bad = extrachill_api_private_stream_from_affinity_response(
				array(
					'headers'  => array(
						'Content-Length' => '4',
						'Content-Range'  => 'bytes 2-8/10',
					),
					'body'     => '',
					'response' => array( 'code' => 206 ),
				),
				$bad_path
			);
			$this->assertWPError( $bad );
			$this->assertFileDoesNotExist( $bad_path );
		}

		/** HEAD and REST envelope requests cannot consume a private handoff. */
		public function test_head_and_envelope_requests_do_not_reach_events() {
			wp_set_current_user( self::factory()->user->create() );
			$head = new WP_REST_Request( 'HEAD', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$this->assertSame( 405, rest_do_request( $head )->get_status() );

			$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$request->set_query_params( array( '_envelope' => '1' ) );
			$this->assertSame( 400, rest_do_request( $request )->get_status() );
			$this->assertSame( array(), $this->audit );
		}

		/** Return a one-byte transport limit. */
		public function one_byte_limit() {
			return 1;
		}

		/** Register the hidden Events descriptor contract. */
		private function register_ability() {
			$name    = 'extrachill/download-booking-attachment';
			$ability = WP_Abilities_Registry::get_instance()->register(
				$name,
				array(
					'label'               => 'Download booking attachment',
					'description'         => 'Controlled Events handoff contract.',
					'category'            => 'extrachill-api-tests',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'booking_id'    => array( 'type' => 'integer' ),
							'attachment_id' => array( 'type' => 'integer' ),
						),
						'required'             => array( 'booking_id', 'attachment_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => function ( array $input ) {
						return in_array( $input['booking_id'], array( 2, 3 ), true )
							? new WP_Error( 'venue_action_forbidden', 'Internal venue policy.', array( 'status' => 403 ) )
							: true;
					},
					'execute_callback'    => function ( array $input ) {
						$this->audit[] = array(
							'booking_id'    => $input['booking_id'],
							'attachment_id' => $input['attachment_id'],
							'actor_id'      => get_current_user_id(),
						);
						return array(
							'stream_token' => str_repeat( dechex( min( 15, $input['booking_id'] ) ), 64 ),
							'expires_at'   => gmdate( 'c', time() + 300 ),
							'filename'     => 'safe-rider.pdf',
							'mime_type'    => 'application/pdf',
						);
					},
				)
			);
			$this->assertInstanceOf( WP_Ability::class, $ability );
			$this->registered_abilities[] = $name;
		}

		/** Dispatch one request through the real REST server. */
		private function dispatch( $booking_id, $attachment_id ) {
			return rest_do_request(
				new WP_REST_Request(
					'GET',
					sprintf( '/extrachill/v1/events/bookings/%d/attachments/%d/download', $booking_id, $attachment_id )
				)
			);
		}

		/** Capture manual byte serving for one response. */
		private function serve( WP_REST_Response $response ) {
			ob_start();
			$served = extrachill_api_serve_private_stream( false, $response, new WP_REST_Request(), rest_get_server() );
			$bytes  = ob_get_clean();
			$this->assertTrue( $served );
			return $bytes;
		}

		/** Return a response header without relying on retained key casing. */
		private function response_header( WP_REST_Response $response, $name ) {
			foreach ( $response->get_headers() as $key => $value ) {
				if ( strtolower( $key ) === strtolower( $name ) ) {
					return (string) $value;
				}
			}
			return '';
		}
	}
}
