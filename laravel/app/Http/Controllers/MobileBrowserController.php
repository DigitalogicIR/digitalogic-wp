<?php
/**
 * Browser-only session discovery and pre-login Google nonce.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 browser adapter.

use Digitalogic\Laravel\Services\MobileBrowserSession as Browser;
use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Never creates a WordPress auth cookie or exposes an app bearer. */
final class MobileBrowserController {

	/**
	 * Restore only the browser's app session, including its current CSRF generation.
	 *
	 * @param Request $request Validated PWA request.
	 */
	public function session( Request $request ): Response {
		if ( ! Browser::active( $request ) || ! Browser::enabled() ) {
			return response()->json( array( 'code' => 'browser_auth_unavailable' ), 403 );
		}
		$browser        = $request->attributes->get( 'digitalogic_browser' );
		$context        = $browser['context'];
		$session        = Identity::authenticate( $browser['authorization'], false );
		$replace_cookie = ! $context || ( isset( $context['access_token'] ) && ! $session );
		if ( $replace_cookie ) {
			$context = Browser::anonymous();
		}
		$data = array(
			'authenticated' => null !== $session,
			'session_type'  => 'browser',
			'csrf_token'    => $context['csrf'],
		);
		if ( $session ) {
			$data['session_id'] = $session['id'];
			$data['expires_at'] = gmdate( 'c', (int) $session['expires_at'] );
			$data['profile']    = ( new MobileIdentityController() )->profileData( (int) $session['user_id'] );
		}
		$response = response()->json( $data, 200, array( 'Cache-Control' => 'private, no-store' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		// A delayed read must not overwrite a newer login's rotated cookie generation.
		if ( $replace_cookie ) {
			Browser::cookie( $response, $context );
		}
		return $response;
	}

	/**
	 * Prepare one browser-origin nonce before GIS returns a signed identity token.
	 *
	 * @param Request $request Validated PWA request with generation-bound CSRF.
	 */
	public function googleChallenge( Request $request ): Response {
		$context = $request->attributes->get( 'digitalogic_browser' )['context'] ?? null;
		if ( ! Browser::active( $request ) || ! $context || ! Browser::enabled() || ! MobileIdentityController::googleEnabled() ) {
			return response()->json( array( 'code' => 'browser_google_unavailable' ), 403 );
		}
		$input = $request->json()->all();
		if ( array_diff( array_keys( $input ), array( 'request_id', 'flow' ) ) || ! is_string( $input['request_id'] ?? null ) || ! is_string( $input['flow'] ?? 'id_token' ) ) {
			return response()->json( array( 'code' => 'browser_google_invalid_request' ), 400 );
		}
		$result = Browser::googleChallenge( $context, $input['request_id'], Browser::network( $request ), $input['flow'] ?? 'id_token' );
		$status = is_wp_error( $result ) ? (int) $result->get_error_data()['status'] : 200;
		return response()->json(
			is_wp_error( $result ) ? array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			) : $result,
			$status,
			array( 'Cache-Control' => 'private, no-store' )
		);
	}
}
