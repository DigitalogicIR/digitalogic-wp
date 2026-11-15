<?php
/**
 * Explicitly consented, authenticated native client reporting.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 controller contract.

use Digitalogic\Laravel\Services\MobileDeviceReport;
use Digitalogic\Laravel\Services\MobileIdentityStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Optional reports never authorize remote device actions. */
final class MobileDeviceReportController {
	/**
	 * Record one bounded report only after native session and consent validation.
	 *
	 * @param Request $request Native authenticated request.
	 * @return Response Acceptance or explicit bounded error.
	 */
	public function submit( Request $request ): Response {
		$session = MobileIdentityController::enabled() ? MobileIdentityStore::authenticate( $request->headers->get( 'Authorization', '' ) ) : null;
		if ( ! $session ) {
			$result = new \WP_Error( 'unauthorized', 'دوباره وارد حساب شوید.', array( 'status' => 401 ) );
		} else {
			$result = MobileDeviceReport::submit( $session, $request->json()->all() );
		}
		$status = is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 503 ) : 202;
		$data   = is_wp_error( $result ) ? array(
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		) : $result;
		return response()->json(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}
