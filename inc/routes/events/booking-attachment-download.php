<?php
/**
 * Protected booking attachment download transport.
 *
 * Events owns authorization, handoffs, private bytes, policy, and audit. This
 * route only adapts those contracts to a bounded HTTP byte response.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Native resource streaming and unhooked spool deletion are required so private
// bytes never enter WP_Filesystem buffers or path-observing deletion hooks.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.unlink_unlink

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_booking_attachment_download_route' );
add_filter( 'rest_post_dispatch', 'extrachill_api_protect_booking_attachment_download_response', 10, 3 );
add_filter( 'rest_pre_serve_request', 'extrachill_api_serve_private_stream', 10, 4 );

/** Register the authenticated Events-owned attachment download route. */
function extrachill_api_register_booking_attachment_download_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/bookings/(?P<booking_id>\d+)/attachments/(?P<attachment_id>\d+)/download',
		array(
			'methods'             => 'GET',
			'callback'            => 'extrachill_api_download_booking_attachment',
			'permission_callback' => 'extrachill_api_booking_attachment_download_permission',
			'args'                => array(
				'booking_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'attachment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		)
	);
}

/** Require an authenticated caller without disclosing attachment existence. */
function extrachill_api_booking_attachment_download_permission() {
	return is_user_logged_in()
		? true
		: new WP_Error( 'booking_attachment_download_unavailable', 'The requested attachment is unavailable.', array( 'status' => 401 ) );
}

/**
 * Issue and immediately consume an Events-owned one-time handoff.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_download_booking_attachment( WP_REST_Request $request ) {
	if ( 'GET' !== $request->get_method() ) {
		return extrachill_api_booking_attachment_download_error( 405 );
	}
	if ( null !== $request->get_param( '_envelope' ) ) {
		return extrachill_api_booking_attachment_download_error( 400 );
	}

	$rate_limit = extrachill_api_check_booking_attachment_download_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return extrachill_api_booking_attachment_download_error( 503 );
	}

	$ability = wp_get_ability( 'extrachill/download-booking-attachment' );
	if ( ! $ability ) {
		return extrachill_api_booking_attachment_download_error( 503 );
	}

	$input   = array(
		'booking_id'    => (int) $request->get_param( 'booking_id' ),
		'attachment_id' => (int) $request->get_param( 'attachment_id' ),
	);
	$allowed = $ability->check_permissions( $input );
	if ( true !== $allowed ) {
		return extrachill_api_booking_attachment_download_error( 404 );
	}

	$descriptor = $ability->execute( $input );
	if ( is_wp_error( $descriptor ) || ! is_array( $descriptor ) ) {
		return extrachill_api_booking_attachment_download_error( extrachill_api_booking_attachment_error_status( $descriptor ) );
	}

	$token = $descriptor['stream_token'] ?? null;
	if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$service_class = '\\ExtraChillEvents\\Core\\BookingAttachmentService';
	if ( ! class_exists( $service_class ) ) {
		return extrachill_api_booking_attachment_download_error( 503 );
	}

	$service = new $service_class();
	$stream  = $service->open_download_stream(
		$input['booking_id'],
		$input['attachment_id'],
		$token,
		get_current_user_id()
	);
	if ( is_wp_error( $stream ) || ! is_resource( $stream ) ) {
		if ( is_resource( $stream ) ) {
			fclose( $stream );
		}
		return extrachill_api_booking_attachment_download_error( extrachill_api_booking_attachment_error_status( $stream ) );
	}

	return extrachill_api_create_private_stream_response(
		$stream,
		(string) ( $descriptor['filename'] ?? '' ),
		(string) ( $descriptor['mime_type'] ?? '' ),
		$request
	);
}

/**
 * Return a stable public status without forwarding domain error details.
 *
 * @param mixed $error Domain result.
 * @return int
 */
function extrachill_api_booking_attachment_error_status( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return 502;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	if ( in_array( $status, array( 401, 403, 404, 409, 410 ), true ) ) {
		return 404;
	}

	return in_array( $status, array( 429, 502, 503 ), true ) ? $status : 502;
}

/** Build a generic, non-cacheable download error. */
function extrachill_api_booking_attachment_download_error( $status ) {
	$response = new WP_Error(
		'booking_attachment_download_unavailable',
		'The requested attachment is unavailable.',
		array( 'status' => (int) $status )
	);

	return $response;
}

/** Apply a fixed-window per-user cap before Events issues a handoff. */
function extrachill_api_check_booking_attachment_download_rate_limit() {
	$limit = (int) apply_filters( 'extrachill_api_booking_attachment_download_rate_limit', 30 );
	if ( $limit < 1 ) {
		return true;
	}

	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return extrachill_api_booking_attachment_download_error( 401 );
	}

	$key   = 'ec_api_booking_dl_' . substr( hash_hmac( 'sha256', (string) $user_id, wp_salt( 'nonce' ) ), 0, 32 );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return extrachill_api_booking_attachment_download_error( 429 );
	}
	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

	return true;
}

/** Return the transport byte ceiling, bounded by Events' hard policy ceiling. */
function extrachill_api_booking_attachment_max_bytes() {
	$maximum = (int) apply_filters( 'extrachill_api_booking_attachment_max_bytes', 20 * MB_IN_BYTES );

	return max( 1, min( 20 * MB_IN_BYTES, $maximum ) );
}

/**
 * Validate metadata and register a local Events stream for manual REST serving.
 *
 * @param resource        $stream   Opened Events-owned stream.
 * @param string          $filename Events-provided filename.
 * @param string          $mime     Events-provided MIME type.
 * @param WP_REST_Request $request  REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_create_private_stream_response( $stream, $filename, $mime, WP_REST_Request $request ) {
	$stat = fstat( $stream );
	$size = is_array( $stat ) ? (int) ( $stat['size'] ?? -1 ) : -1;
	if ( $size < 0 || $size > extrachill_api_booking_attachment_max_bytes() ) {
		fclose( $stream );
		return extrachill_api_booking_attachment_download_error( 413 );
	}

	$range = extrachill_api_booking_attachment_range( $request->get_header( 'Range' ), $size );
	if ( is_wp_error( $range ) ) {
		fclose( $stream );
		return $range;
	}
	if ( 0 !== $range['offset'] && 0 !== fseek( $stream, $range['offset'] ) ) {
		fclose( $stream );
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$headers = extrachill_api_private_stream_headers( $filename, $mime, $range['length'] );
	if ( 206 === $range['status'] ) {
		$headers['Content-Range'] = sprintf( 'bytes %d-%d/%d', $range['offset'], $range['offset'] + $range['length'] - 1, $size );
	}

	return extrachill_api_register_private_stream( $stream, $range['length'], $range['status'], $headers );
}

/**
 * Parse one bounded HTTP byte range.
 *
 * @param string $header Range header.
 * @param int    $size   Full stream size.
 * @return array|WP_Error
 */
function extrachill_api_booking_attachment_range( $header, $size ) {
	if ( '' === $header || null === $header ) {
		return array(
			'offset' => 0,
			'length' => $size,
			'status' => 200,
		);
	}

	if ( $size < 1 || 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
		return new WP_Error( 'booking_attachment_range_unsatisfiable', 'The requested byte range is unavailable.', array( 'status' => 416 ) );
	}

	if ( '' === $match[1] ) {
		$suffix = (int) $match[2];
		if ( $suffix < 1 ) {
			return new WP_Error( 'booking_attachment_range_unsatisfiable', 'The requested byte range is unavailable.', array( 'status' => 416 ) );
		}
		$length = min( $suffix, $size );
		$offset = $size - $length;
	} else {
		$offset = (int) $match[1];
		$end    = '' === $match[2] ? $size - 1 : min( (int) $match[2], $size - 1 );
		if ( $offset >= $size || $end < $offset ) {
			return new WP_Error( 'booking_attachment_range_unsatisfiable', 'The requested byte range is unavailable.', array( 'status' => 416 ) );
		}
		$length = $end - $offset + 1;
	}

	return array(
		'offset' => $offset,
		'length' => $length,
		'status' => 206,
	);
}

/** Build headers that keep private bytes out of browsers and intermediary caches. */
function extrachill_api_private_stream_headers( $filename, $mime, $length ) {
	$safe_filename = sanitize_file_name( wp_basename( $filename ) );
	if ( '' === $safe_filename ) {
		$safe_filename = 'attachment.bin';
	}
	$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $safe_filename );
	$fallback = '' === $fallback ? 'attachment.bin' : $fallback;
	$mime     = 1 === preg_match( '#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $mime ) ? strtolower( $mime ) : 'application/octet-stream';

	return array(
		'Accept-Ranges'             => 'bytes',
		'Cache-Control'             => 'private, no-store, no-cache, must-revalidate, max-age=0',
		'Content-Disposition'       => 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $safe_filename ),
		'Content-Length'            => (string) $length,
		'Content-Type'              => $mime,
		'Expires'                   => '0',
		'Pragma'                    => 'no-cache',
		'Vary'                      => 'Authorization, Cookie',
		'X-Content-Type-Options'    => 'nosniff',
		'X-EC-Download-Correlation' => wp_generate_uuid4(),
		'X-Robots-Tag'              => 'noindex, nofollow, noarchive',
	);
}

/** Keep every success and failure for this private route out of caches. */
function extrachill_api_protect_booking_attachment_download_response( $response, $server, $request ) {
	unset( $server );
	if ( ! extrachill_api_is_booking_attachment_download_route( $request->get_route() ) ) {
		return $response;
	}

	$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Expires', '0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'Vary', 'Authorization, Cookie' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );

	return $response;
}

/**
 * Register an exact stream response without placing its resource in REST data.
 *
 * @param resource    $stream       Open stream.
 * @param int         $length       Bytes to emit.
 * @param int         $status       HTTP status.
 * @param array       $headers      Safe response headers.
 * @param string|null $cleanup_path Optional affinity spool to unlink.
 * @return WP_REST_Response
 */
function extrachill_api_register_private_stream( $stream, $length, $status, array $headers, $cleanup_path = null ) {
	$response = new WP_REST_Response( null, $status, $headers );
	$key      = spl_object_id( $response );
	$GLOBALS['extrachill_api_private_streams'][ $key ] = array(
		'stream'       => $stream,
		'length'       => (int) $length,
		'cleanup_path' => is_string( $cleanup_path ) ? $cleanup_path : null,
	);
	extrachill_api_register_private_stream_shutdown_cleanup();

	return $response;
}

/** Register one request-level safety net for aborted/private stream responses. */
function extrachill_api_register_private_stream_shutdown_cleanup() {
	if ( ! empty( $GLOBALS['extrachill_api_private_stream_cleanup_registered'] ) ) {
		return;
	}
	$GLOBALS['extrachill_api_private_stream_cleanup_registered'] = true;
	register_shutdown_function( 'extrachill_api_cleanup_private_streams' );
}

/** Close every pending resource and unlink every private affinity spool. */
function extrachill_api_cleanup_private_streams() {
	$streams = is_array( $GLOBALS['extrachill_api_private_streams'] ?? null ) ? $GLOBALS['extrachill_api_private_streams'] : array();
	foreach ( $streams as $entry ) {
		if ( is_resource( $entry['stream'] ?? null ) ) {
			fclose( $entry['stream'] );
		}
		$path = $entry['cleanup_path'] ?? null;
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			unlink( $path );
		}
	}
	$GLOBALS['extrachill_api_private_streams'] = array();
}

/**
 * Emit a registered private stream after WordPress has sent its safe headers.
 *
 * @param bool             $served  Whether already served.
 * @param WP_HTTP_Response $result  REST response.
 * @param WP_REST_Request  $request REST request.
 * @param WP_REST_Server   $server  REST server.
 * @return bool
 */
function extrachill_api_serve_private_stream( $served, $result, $request, $server ) {
	unset( $request, $server );
	if ( $served || ! is_object( $result ) ) {
		return $served;
	}

	$key = spl_object_id( $result );
	if ( ! isset( $GLOBALS['extrachill_api_private_streams'][ $key ] ) ) {
		return $served;
	}

	$entry = $GLOBALS['extrachill_api_private_streams'][ $key ];
	unset( $GLOBALS['extrachill_api_private_streams'][ $key ] );
	$stream    = $entry['stream'];
	$remaining = max( 0, (int) $entry['length'] );
	try {
		while ( $remaining > 0 && is_resource( $stream ) && ! feof( $stream ) ) {
			$chunk = fread( $stream, min( 64 * KB_IN_BYTES, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified private binary transport.
			$remaining -= strlen( $chunk );
			if ( connection_aborted() ) {
				break;
			}
		}
	} finally {
		if ( is_resource( $stream ) ) {
			fclose( $stream );
		}
		$path = $entry['cleanup_path'] ?? null;
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			unlink( $path );
		}
	}

	return true;
}

/** Return whether a route is the protected binary transport. */
function extrachill_api_is_booking_attachment_download_route( $route ) {
	return 1 === preg_match( '#^/extrachill/v1/events/bookings/\d+/attachments/\d+/download$#', (string) $route );
}

/**
 * Convert a bounded affinity spool into the same manual stream response.
 *
 * @param array  $http_response Raw WordPress HTTP response.
 * @param string $path          Private temporary spool path.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_private_stream_from_affinity_response( array $http_response, $path ) {
	$status  = (int) wp_remote_retrieve_response_code( $http_response );
	$headers = wp_remote_retrieve_headers( $http_response );
	$headers = $headers instanceof Traversable ? iterator_to_array( $headers ) : (array) $headers;
	if ( ! in_array( $status, array( 200, 206 ), true ) ) {
		extrachill_api_discard_private_affinity_spool( $path );
		return extrachill_api_booking_attachment_download_error( extrachill_api_booking_attachment_proxy_status( $status ) );
	}

	$size = is_file( $path ) ? filesize( $path ) : false;
	if ( false === $size || $size > extrachill_api_booking_attachment_max_bytes() ) {
		extrachill_api_discard_private_affinity_spool( $path );
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$declared = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : ( isset( $headers['Content-Length'] ) ? (int) $headers['Content-Length'] : -1 );
	if ( $declared !== (int) $size ) {
		extrachill_api_discard_private_affinity_spool( $path );
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$disposition = (string) ( $headers['content-disposition'] ?? ( $headers['Content-Disposition'] ?? '' ) );
	$filename    = extrachill_api_filename_from_content_disposition( $disposition );
	$mime        = (string) ( $headers['content-type'] ?? ( $headers['Content-Type'] ?? '' ) );
	$safe        = extrachill_api_private_stream_headers( $filename, $mime, $size );
	$correlation = (string) ( $headers['x-ec-download-correlation'] ?? ( $headers['X-EC-Download-Correlation'] ?? '' ) );
	if ( 1 === preg_match( '/^[a-f0-9-]{36}$/i', $correlation ) ) {
		$safe['X-EC-Download-Correlation'] = $correlation;
	}
	if ( 206 === $status ) {
		$content_range = (string) ( $headers['content-range'] ?? ( $headers['Content-Range'] ?? '' ) );
		if ( 1 !== preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', $content_range, $range ) || (int) $range[2] < (int) $range[1] || (int) $range[2] - (int) $range[1] + 1 !== (int) $size || (int) $range[3] > extrachill_api_booking_attachment_max_bytes() ) {
			extrachill_api_discard_private_affinity_spool( $path );
			return extrachill_api_booking_attachment_download_error( 502 );
		}
		$safe['Content-Range'] = $content_range;
	}

	$stream = fopen( $path, 'rb' );
	if ( false === $stream ) {
		extrachill_api_discard_private_affinity_spool( $path );
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	return extrachill_api_register_private_stream( $stream, $size, $status, $safe, $path );
}

/** Extract and sanitize only a presentation filename from Content-Disposition. */
function extrachill_api_filename_from_content_disposition( $header ) {
	$filename = '';
	if ( 1 === preg_match( "/filename\*=UTF-8''([^;]+)/i", $header, $match ) ) {
		$filename = rawurldecode( $match[1] );
	} elseif ( 1 === preg_match( '/filename="?([^";]+)"?/i', $header, $match ) ) {
		$filename = $match[1];
	}

	return sanitize_file_name( wp_basename( $filename ) );
}

/** Map a downstream private transport status without exposing its response. */
function extrachill_api_booking_attachment_proxy_status( $status ) {
	if ( in_array( (int) $status, array( 401, 403, 404, 409, 410 ), true ) ) {
		return 404;
	}

	return in_array( (int) $status, array( 413, 416, 429, 502, 503 ), true ) ? (int) $status : 502;
}

/** Close and unlink an affinity spool on every failure path. */
function extrachill_api_discard_private_affinity_spool( $path ) {
	if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
		unlink( $path );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.unlink_unlink
