<?php
/**
 * Shared fixed-window admission for public REST writes.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply the existing per-client transient limiter to one public-write scope.
 *
 * @param WP_REST_Request $request REST request.
 * @param string          $scope   Stable limiter scope.
 * @param int             $limit   Maximum writes per minute.
 * @return true|WP_Error
 */
function extrachill_api_check_public_write_rate_limit( WP_REST_Request $request, $scope, $limit ) {
	$limit = (int) $limit;
	if ( $limit < 1 ) {
		return true;
	}

	$client = '';
	if ( '1' === $request->get_header( 'X-EC-Affinity-Verified' ) ) {
		$client = sanitize_text_field( (string) $request->get_param( '_ec_affinity_client' ) );
	}
	if ( '' === $client ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		$client = hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	$key   = 'ec_api_write_' . substr( hash( 'sha256', sanitize_key( $scope ) . ':' . $client ), 0, 32 );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return new WP_Error(
			'public_write_rate_limited',
			__( 'Too many requests. Please try again later.', 'extrachill-api' ),
			array( 'status' => 429 )
		);
	}

	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

	return true;
}
