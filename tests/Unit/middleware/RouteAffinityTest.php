<?php
/**
 * Tests for multisite route-affinity transport behavior.
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Exercises forwarding trust, matching, and response fidelity.
 */
class Route_AffinityTest extends WP_UnitTestCase {

	/**
	 * Number of intercepted loopback requests.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Mock downstream response.
	 *
	 * @var array
	 */
	private $downstream_response;

	/**
	 * Original remote address.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	/**
	 * Install a deterministic affinity map and loopback transport.
	 */
	public function set_up() {
		require_once __DIR__ . '/route-affinity-test-helpers.php';

		parent::set_up();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Preserve exact server state for teardown.
		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
		$this->downstream_response  = $this->http_response( 200, array( 'ok' => true ) );

		add_filter( 'pre_http_request', array( $this, 'intercept_loopback' ), 10, 3 );
	}

	/**
	 * Restore global request state and filters.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_loopback' ), 10 );

		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}

		parent::tear_down();
	}

	/**
	 * An external caller cannot suppress forwarding with the legacy header.
	 */
	public function test_external_forwarded_header_does_not_bypass_affinity() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$request->set_header( 'X-EC-Forwarded', '1' );

		$response = $this->dispatch_affinity( $request );

		$this->assertSame( 1, $this->request_count );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Collection creation and item requests use the same artist affinity.
	 *
	 * @dataProvider artist_route_provider
	 * @param string $method Request method.
	 * @param string $route  Request route.
	 */
	public function test_artist_collection_and_item_routes_forward( $method, $route ) {
		$response = $this->dispatch_affinity( new WP_REST_Request( $method, $route ) );

		$this->assertSame( 1, $this->request_count );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Successful downstream status and headers are preserved.
	 *
	 * @dataProvider success_response_provider
	 * @param int $status Downstream status.
	 */
	public function test_success_response_preserves_status_body_and_headers( $status ) {
		$this->downstream_response = $this->http_response(
			$status,
			array( 'artist_id' => 42 ),
			array( 'X-EC-Result' => 'forwarded' )
		);

		$response = $this->dispatch_affinity( new WP_REST_Request( 'POST', '/extrachill/v1/artists' ) );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( array( 'artist_id' => 42 ), $response->get_data() );
		$this->assertSame( 'forwarded', $response->get_headers()['X-EC-Result'] );
	}

	/**
	 * Error responses retain their full downstream envelope and headers.
	 */
	public function test_error_response_preserves_status_body_and_headers() {
		$body = array(
			'code'    => 'artist_conflict',
			'message' => 'Artist already exists.',
			'data'    => array( 'status' => 409 ),
			'details' => array( 'artist_id' => 42 ),
		);

		$this->downstream_response = $this->http_response( 409, $body, array( 'X-EC-Error' => 'conflict' ) );

		$response = $this->dispatch_affinity( new WP_REST_Request( 'POST', '/extrachill/v1/artists' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $body, $response->get_data() );
		$this->assertSame( 'conflict', $response->get_headers()['X-EC-Error'] );
	}

	/**
	 * A valid signed localhost hop terminates forwarding recursion.
	 */
	public function test_signed_local_reentry_does_not_forward_again() {
		$route     = '/extrachill/v1/artists/42';
		$timestamp = time();
		$payload   = "GET\n{$route}\n{$timestamp}";
		$request   = new WP_REST_Request( 'GET', $route );

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$request->set_header( 'X-EC-Affinity-Timestamp', (string) $timestamp );
		$request->set_header( 'X-EC-Affinity-Signature', hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) );

		$this->assertNull( $this->dispatch_affinity( $request ) );
		$this->assertSame( 0, $this->request_count );

		$request->set_route( '/extrachill/v1/artists/43' );
		$this->assertFalse( extrachill_api_is_route_affinity_reentry( $request ) );
	}

	/**
	 * Artist routes covered by affinity.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function artist_route_provider() {
		return array(
			'collection creation' => array( 'POST', '/extrachill/v1/artists' ),
			'item read'           => array( 'GET', '/extrachill/v1/artists/42' ),
		);
	}

	/**
	 * Successful non-200 statuses.
	 *
	 * @return array<string, array{int}>
	 */
	public function success_response_provider() {
		return array(
			'created'  => array( 201 ),
			'accepted' => array( 202 ),
		);
	}

	/**
	 * Return the configured response instead of making a loopback request.
	 *
	 * @param false|array $pre  Preemptive HTTP response.
	 * @param array       $args HTTP arguments.
	 * @param string      $url  Request URL.
	 * @return false|array
	 */
	public function intercept_loopback( $pre, $args, $url ) {
		if ( false === strpos( $url, '127.0.0.1' ) ) {
			return $pre;
		}

		++$this->request_count;
		return $this->downstream_response;
	}

	/**
	 * Dispatch the middleware directly.
	 *
	 * @param WP_REST_Request $request Request to dispatch.
	 * @return mixed
	 */
	private function dispatch_affinity( WP_REST_Request $request ) {
		return extrachill_api_route_affinity_dispatch( null, rest_get_server(), $request );
	}

	/**
	 * Build a WordPress HTTP API response fixture.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $body    JSON body.
	 * @param array $headers Response headers.
	 * @return array
	 */
	private function http_response( $status, $body, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
