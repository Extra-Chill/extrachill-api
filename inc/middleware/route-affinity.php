<?php
/**
 * Route Affinity Middleware
 *
 * Intercepts REST API requests and forwards them to the correct subsite
 * when the current site doesn't match the route's home site.
 *
 * This makes the REST API universally accessible — any route can be called
 * from any site in the network. The middleware transparently proxies the
 * request to the correct subsite via internal HTTP (localhost).
 *
 * Requires: ec_cross_site_rest_request() from extrachill-multisite.
 *
 * @package ExtraChillAPI
 * @since 0.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply artist-site affinity to both collection and item routes.
 *
 * @param array $affinity_map Route prefix to site key map.
 * @return array
 */
function extrachill_api_add_artist_route_affinity( $affinity_map ) {
	unset( $affinity_map['/extrachill/v1/artists/'] );
	$affinity_map['/extrachill/v1/artists'] = 'artist';

	return $affinity_map;
}
add_filter( 'ec_route_site_affinity_map', 'extrachill_api_add_artist_route_affinity' );

/**
 * Check whether a request is a trusted route-affinity re-entry.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return bool
 */
function extrachill_api_is_route_affinity_reentry( WP_REST_Request $request ) {
	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! in_array( $remote_addr, array( '127.0.0.1', '::1' ), true ) ) {
		return false;
	}

	$timestamp = (int) $request->get_header( 'X-EC-Affinity-Timestamp' );
	$signature = $request->get_header( 'X-EC-Affinity-Signature' );
	if ( ! $timestamp || ! $signature || abs( time() - $timestamp ) > 300 ) {
		return false;
	}

	$payload  = strtoupper( $request->get_method() ) . "\n" . $request->get_route() . "\n" . $timestamp;
	$expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

	return hash_equals( $expected, $signature );
}

/**
 * Check if a REST request should be forwarded to another subsite.
 *
 * Hooked into `rest_pre_dispatch` — if the route belongs to a different site,
 * forwards the request via internal HTTP and returns the response directly.
 *
 * @param mixed           $result  Response to replace the requested version with. Default null.
 * @param WP_REST_Server  $server  REST server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Forwarded response or null to continue normal dispatch.
 */
function extrachill_api_route_affinity_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	// Only a signed localhost request can suppress forwarding on re-entry.
	if ( extrachill_api_is_route_affinity_reentry( $request ) ) {
		return $result;
	}

	// Only handle extrachill/v1 routes.
	$route = $request->get_route();
	if ( ! str_starts_with( $route, '/extrachill/v1/' ) ) {
		return $result;
	}

	// Check if the multisite helper is available.
	if ( ! function_exists( 'ec_get_route_site_affinity' ) || ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return $result;
	}

	// Determine which site this route belongs to.
	$target_site = ec_get_route_site_affinity( $route );

	if ( ! $target_site ) {
		return $result; // No affinity — handle normally on current site.
	}

	// Check if we're already on the correct site.
	if ( function_exists( 'ec_get_blog_id' ) ) {
		$target_blog_id  = ec_get_blog_id( $target_site );
		$current_blog_id = get_current_blog_id();

		if ( $target_blog_id && (int) $current_blog_id === (int) $target_blog_id ) {
			return $result; // Already on the right site — handle normally.
		}
	}

	// Forward the request to the correct subsite.
	$method = $request->get_method();

	// Build the REST path (everything after /extrachill/v1).
	$path = substr( $route, strlen( '/extrachill/v1' ) );

	// Build request args.
	$timestamp = time();
	$payload   = strtoupper( $method ) . "\n" . $route . "\n" . $timestamp;
	$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	$args      = array(
		'headers' => array(
			'X-EC-Affinity-Timestamp' => (string) $timestamp,
			'X-EC-Affinity-Signature' => $signature,
		),
	);

	// Forward query parameters.
	$query_params = $request->get_query_params();
	if ( ! empty( $query_params ) ) {
		$args['query'] = $query_params;
	}

	// Forward body for write methods.
	if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		$body = $request->get_json_params();
		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		} else {
			// Fall back to body params (form-encoded).
			$body_params = $request->get_body_params();
			if ( ! empty( $body_params ) ) {
				$args['body'] = $body_params;
			}
		}
	}

	// Forward the current user context.
	$current_user_id = get_current_user_id();
	if ( $current_user_id > 0 ) {
		$args['user_id'] = $current_user_id;
	}

	// Force HTTP loopback for affinity forwarding.
	//
	// A route only has site affinity because its handler depends on the
	// target site's plugin stack — e.g. /events/* handlers call abilities
	// registered by extrachill-events, which is active ONLY on the events
	// site. The default in-process cross-site path (switch_to_blog() +
	// rest_do_request()) swaps DB/options context but does NOT bootstrap the
	// target site's per-site plugins, so those abilities are never registered
	// in the source process. The forwarded request then runs the handler with
	// the ability missing → WP_Abilities_Registry logs a "not found" notice on
	// every call and the route returns a 500.
	//
	// HTTP loopback spins up a fresh PHP-FPM worker that bootstraps the target
	// site's full plugin stack, so the per-site ability is registered and the
	// handler resolves correctly. This is precisely the documented use case for
	// the loopback path. See Extra-Chill/extrachill-events#141.
	$force_http_loopback     = static function () {
		return true;
	};
	$forwarded_http_response = null;
	$capture_http_response   = static function ( $http_response, $http_args ) use ( &$forwarded_http_response, $signature ) {
		$headers = $http_args['headers'] ?? array();
		if ( ( $headers['X-EC-Affinity-Signature'] ?? '' ) === $signature && false !== $http_response ) {
			$forwarded_http_response = $http_response;
		}

		return $http_response;
	};
	add_filter( 'ec_cross_site_use_http_loopback', $force_http_loopback, 10, 0 );
	add_filter( 'pre_http_request', $capture_http_response, PHP_INT_MAX, 2 );
	add_filter( 'http_response', $capture_http_response, PHP_INT_MAX, 2 );

	try {
		$response = ec_cross_site_rest_request( $target_site, $method, $path, $args );
	} finally {
		remove_filter( 'ec_cross_site_use_http_loopback', $force_http_loopback, 10 );
		remove_filter( 'pre_http_request', $capture_http_response, PHP_INT_MAX );
		remove_filter( 'http_response', $capture_http_response, PHP_INT_MAX );
	}

	if ( is_array( $forwarded_http_response ) ) {
		$status  = wp_remote_retrieve_response_code( $forwarded_http_response );
		$headers = wp_remote_retrieve_headers( $forwarded_http_response );
		$body    = wp_remote_retrieve_body( $forwarded_http_response );
		$data    = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$data = $body;
		}

		if ( $headers instanceof Traversable ) {
			$headers = iterator_to_array( $headers );
		}

		return new WP_REST_Response( $data, $status, is_array( $headers ) ? $headers : array() );
	}

	if ( is_wp_error( $response ) ) {
		$status = $response->get_error_data()['status'] ?? 500;
		return new WP_REST_Response(
			array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	// Return the forwarded response.
	return new WP_REST_Response( $response, 200 );
}
add_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10, 3 );
