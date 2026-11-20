<?php
/**
 * Authenticated history and reduced account invalidations for native and PWA clients.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 controller contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Per-session connection locks are live concurrency metadata.

use Digitalogic\Laravel\Services\MobileAccountEvents;
use Digitalogic\Laravel\Services\MobileIdentityStore;
use Digitalogic\Laravel\Services\MobileRecentViews;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** No event grants account authority or carries private commerce content. */
final class MobileActivityController {

	/**
	 * Read or incrementally merge the current account's view history.
	 *
	 * @param Request $request Current authenticated request.
	 * @return Response Canonical snapshot or bounded rejection.
	 */
	public function history( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->json( $this->error( 'unauthorized', 401 ) );
		}
		if ( ! MobileRecentViews::enabled() ) {
			return $this->json( $this->error( 'history_unavailable', 503 ) );
		}
		$authority = static fn() => MobileIdentityStore::sessionIsCurrent( $session['id'], (int) $session['user_id'] );
		if ( $request->isMethod( 'POST' ) ) {
			$input = $request->json()->all();
			$views = MobileRecentViews::normalize( $input['views'] ?? null, time() );
			if ( array_keys( $input ) !== array( 'views' ) || null === $views ) {
				return $this->json( $this->error( 'invalid_history', 400 ) );
			}
			if ( ! MobileIdentityStore::admit( 'history:' . $session['id'], 60, 60 ) ) {
				return $this->json( $this->error( 'history_rate_limited', 429 ) );
			}
			$result = MobileRecentViews::sync( (int) $session['user_id'], $views, $authority );
			return $this->json( $authority() ? $result : $this->error( 'unauthorized', 401 ) );
		}
		try {
			$result = MobileRecentViews::snapshot( MobileRecentViews::read( (int) $session['user_id'] ) );
			return $this->json( $authority() ? $result : $this->error( 'unauthorized', 401 ) );
		} catch ( \Throwable $error ) {
			return $this->json( $this->error( 'history_unavailable', 503 ) );
		}
	}

	/**
	 * One bounded authenticated poll per session, reusable by PWA and native fallback.
	 *
	 * @param Request $request Current request with nonsecret cursor.
	 * @return Response Reduced events or private error.
	 */
	public function events( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->json( $this->error( 'unauthorized', 401 ) );
		}
		if ( ! MobileAccountEvents::enabled() ) {
			return $this->json( $this->error( 'account_events_unavailable', 503 ) );
		}
		$cursor = $request->query( 'cursor', '0' );
		$wait   = $request->query( 'wait', '20' );
		if ( array_diff( array_keys( $request->query() ), array( 'cursor', 'wait' ) ) || ! is_scalar( $cursor ) || ! preg_match( '/\A[0-9]{1,15}\z/D', (string) $cursor ) || ! is_scalar( $wait ) || ! preg_match( '/\A(?:[0-9]|1[0-9]|20)\z/D', (string) $wait ) ) {
			return $this->json( $this->error( 'invalid_event_cursor', 400 ) );
		}
		if ( ! MobileIdentityStore::admit( 'events:' . $session['id'], 90, 60 ) ) {
			return $this->json( $this->error( 'account_events_rate_limited', 429 ) );
		}
		global $wpdb;
		$lock = 'dgl-account-poll-' . substr( hash( 'sha256', $session['id'] ), 0, 40 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return $this->json( $this->error( 'account_events_busy', 429 ) );
		}
		try {
			return $this->json( MobileAccountEvents::poll( (int) $cursor, (int) $wait, static fn() => MobileIdentityStore::sessionIsCurrent( $session['id'], (int) $session['user_id'] ), (int) $session['user_id'] ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Existing cookie adapter supplies its bearer internally; clients cannot choose an owner.
	 *
	 * @param Request $request Current request.
	 * @return array|null Verified current session.
	 */
	private function session( Request $request ): ?array {
		return MobileIdentityController::enabled() ? MobileIdentityStore::authenticate( $request->headers->get( 'Authorization', '' ) ) : null;
	}

	/**
	 * Bounded failure with no private data.
	 *
	 * @param string $code Failure code.
	 * @param int    $status HTTP status.
	 * @return \WP_Error Error.
	 */
	private function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 401 === $status ? 'دوباره وارد حساب شوید.' : 'دریافت اطلاعات انجام نشد؛ دوباره تلاش کنید.', array( 'status' => $status ) );
	}

	/**
	 * All account state, including errors, must bypass shared HTTP caches.
	 *
	 * @param array|\WP_Error $result Wire payload or error.
	 * @return Response JSON response.
	 */
	private function json( $result ): Response {
		$status = is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 503 ) : 200;
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
				'Retry-After'            => '2',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}
