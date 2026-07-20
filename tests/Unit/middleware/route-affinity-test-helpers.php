<?php
/**
 * Network helper fallbacks for route-affinity tests.
 *
 * @package ExtraChill\API\Tests
 */

if ( ! function_exists( 'ec_get_route_site_affinity' ) ) {
	/**
	 * Test fallback for the Network-owned affinity resolver.
	 *
	 * @param string $route REST route.
	 * @return string|null
	 */
	function ec_get_route_site_affinity( $route ) {
		foreach ( apply_filters( 'ec_route_site_affinity_map', array() ) as $prefix => $site_key ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return $site_key;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	/**
	 * Keep the test target distinct from the current site.
	 *
	 * @return int
	 */
	function ec_get_blog_id() {
		return get_current_blog_id() + 1;
	}
}

if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
	/**
	 * Test fallback matching the Network helper's HTTP response contract.
	 *
	 * @param string $site_key Target site key.
	 * @param string $method   HTTP method.
	 * @param string $path     Namespace-relative path.
	 * @param array  $args     Request arguments.
	 * @return array|WP_Error
	 */
	function ec_cross_site_rest_request( $site_key, $method, $path, $args = array() ) {
		$response = wp_remote_request(
			'https://127.0.0.1/wp-json/extrachill/v1' . $path,
			array(
				'method'  => $method,
				'headers' => $args['headers'] ?? array(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 400 ) {
			return new WP_Error( $body['code'] ?? 'ec_cross_site_error', $body['message'] ?? 'Cross-site request failed', array( 'status' => $status ) );
		}

		return $body;
	}
}
