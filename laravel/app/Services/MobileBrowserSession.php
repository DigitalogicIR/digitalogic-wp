<?php
/**
 * Same-origin PWA transport over existing app sessions, never WordPress cookies.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 browser adapter.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Challenge receipts require current serialized metadata.

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** The browser sees a CSRF generation, never the underlying app bearer. */
final class MobileBrowserSession {

	const ORIGIN = 'https://pwa.digitalogic.ir';
	const COOKIE = '__Host-dgl-pwa';

	/** Explicit rollout gate, independent of browser/panel login configuration. */
	public static function enabled(): bool {
		return in_array( get_option( 'digitalogic_mobile_browser_auth', false ), array( true, 1, '1' ), true )
			&& in_array( get_option( 'digitalogic_mobile_native_auth', false ), array( true, 1, '1' ), true );
	}

	/**
	 * Create a fresh anonymous cookie generation without granting an identity.
	 *
	 * @return array Private, encrypted context.
	 */
	public static function anonymous(): array {
		return array(
			'origin'     => self::ORIGIN,
			'id'         => bin2hex( random_bytes( 32 ) ),
			'csrf'       => bin2hex( random_bytes( 32 ) ),
			'expires_at' => time() + DAY_IN_SECONDS,
		);
	}

	/**
	 * Encode a small purpose-bound cookie, never a JavaScript-accessible bearer.
	 *
	 * @param array $context Server-owned cookie context.
	 * @return string Authenticated ciphertext.
	 */
	public static function encode( array $context ): string {
		return MobileRegistrationStore::seal( wp_json_encode( $context ), 'pwa-cookie-v1' );
	}

	/**
	 * Reject tampering, wrong origin, expiry and malformed stored credentials.
	 *
	 * @param mixed $value Cookie value.
	 * @return array|null Valid private context.
	 */
	public static function decode( $value ): ?array {
		if ( ! is_string( $value ) || strlen( $value ) > 3500 ) {
			return null;
		}
		$plain = MobileRegistrationStore::open( $value, 'pwa-cookie-v1' );
		$data  = $plain ? json_decode( $plain, true ) : null;
		if ( ! is_array( $data ) || ( $data['origin'] ?? null ) !== self::ORIGIN
			|| ! is_string( $data['id'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $data['id'] )
			|| ! is_string( $data['csrf'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $data['csrf'] )
			|| ! is_int( $data['expires_at'] ?? null ) || $data['expires_at'] <= time() || $data['expires_at'] > time() + 31 * DAY_IN_SECONDS ) {
			return null;
		}
		if ( isset( $data['access_token'] ) && ( ! is_string( $data['access_token'] ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $data['access_token'] )
			|| ! is_string( $data['session_id'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $data['session_id'] ) ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Exact origin; a Referer is allowed only on safe reads where browsers omit Origin.
	 *
	 * @param array $server Raw server metadata, validated here.
	 * @return bool Trusted browser origin.
	 */
	public static function trustedOrigin( array $server ): bool {
		$origin = $server['HTTP_ORIGIN'] ?? '';
		if ( '' !== $origin ) {
			return is_string( $origin ) && hash_equals( self::ORIGIN, $origin );
		}
		if ( ! in_array( $server['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return false;
		}
		$ref = $server['HTTP_REFERER'] ?? '';
		$url = is_string( $ref ) ? wp_parse_url( $ref ) : false;
		return is_array( $url ) && ( $url['scheme'] ?? '' ) === 'https' && ( $url['host'] ?? '' ) === 'pwa.digitalogic.ir'
			&& ! isset( $url['user'] ) && ! isset( $url['pass'] ) && ( ! isset( $url['port'] ) || 443 === $url['port'] );
	}

	/**
	 * Validate browser origin, cookie generation and CSRF before forwarding any app authority.
	 *
	 * @param array $server HTTP server metadata.
	 * @param array $cookies Host-only browser cookies.
	 * @param bool  $secure Actual HTTPS transport.
	 * @return array|\WP_Error|null Browser context, definitive rejection, or ordinary native request.
	 */
	public static function inspect( array $server, array $cookies, bool $secure ) {
		$header = $server['HTTP_X_DIGITALOGIC_PWA'] ?? '';
		$origin = $server['HTTP_ORIGIN'] ?? '';
		if ( '' === $header && self::ORIGIN !== $origin ) {
			return null;
		}
		if ( '1' !== $header || ! $secure || ! self::trustedOrigin( $server ) ) {
			return self::error( 'browser_origin_invalid', 403 );
		}
		if ( ! empty( $server['HTTP_AUTHORIZATION'] ) || ! empty( $server['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return self::error( 'browser_credentials_conflict', 403 );
		}
		$network = self::proxyNetwork( $server );
		if ( null === $network ) {
			return self::error( 'browser_proxy_invalid', 403 );
		}
		$path = wp_parse_url( (string) ( $server['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( ! is_string( $path ) || ! str_starts_with( $path, '/app-api/' ) ) {
			return self::error( 'browser_route_invalid', 403 );
		}
		$method    = $server['REQUEST_METHOD'] ?? '';
		$discovery = 'GET' === $method && '/app-api/auth/browser/session' === rtrim( $path, '/' );
		$public    = 'GET' === $method && preg_match( '~\A/app-api/(?:bootstrap|categories|products(?:/[1-9][0-9]{0,9})?|links/resolve|forms)/?\z~D', $path );
		if ( ! self::enabled() && ! $public ) {
			return self::error( 'browser_auth_unavailable', 503 );
		}
		$context = self::decode( $cookies[ self::COOKIE ] ?? null );
		if ( ! $discovery && ! ( $public && ! isset( $cookies[ self::COOKIE ] ) ) ) {
			$csrf = $server['HTTP_X_DIGITALOGIC_CSRF'] ?? '';
			if ( ! $context || ! is_string( $csrf ) || ! hash_equals( $context['csrf'], $csrf ) ) {
				return self::error( 'browser_csrf_invalid', 403 );
			}
		}
		return array(
			'context'       => $context,
			'discovery'     => $discovery,
			'network'       => $network,
			'authorization' => isset( $context['access_token'] ) ? 'Bearer ' . $context['access_token'] : '',
		);
	}

	/**
	 * Only the same-machine PWA proxy may supply its appended client address.
	 *
	 * @param array $server Origin-validated PWA server metadata.
	 * @return string|null Actual peer, or the rightmost Apache-appended peer on loopback.
	 */
	public static function proxyNetwork( array $server ): ?string {
		$peer = $server['REMOTE_ADDR'] ?? '';
		if ( ! is_string( $peer ) || ! filter_var( $peer, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		if ( ! in_array( $peer, array( '127.0.0.1', '::1' ), true ) ) {
			return $peer;
		}
		$forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
		if ( ! is_string( $forwarded ) || strlen( $forwarded ) > 1024 ) {
			return null;
		}
		$chain = explode( ',', $forwarded );
		$last  = trim( end( $chain ) );
		return count( $chain ) <= 20 && filter_var( $last, FILTER_VALIDATE_IP ) ? $last : null;
	}

	/**
	 * Rate scopes use forwarded metadata only after this adapter validated its provenance.
	 *
	 * @param Request $request Internal validated request.
	 * @return string Server-owned network scope.
	 */
	public static function network( Request $request ): string {
		$browser = $request->attributes->get( 'digitalogic_browser' );
		return is_array( $browser ) ? $browser['network'] : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
	}

	/**
	 * Mark only an already validated browser request inside the private HTTP kernel.
	 *
	 * @param Request $request Internal request.
	 * @param array   $browser Validated inspection result.
	 */
	public static function apply( Request $request, array $browser ): void {
		$request->attributes->set( 'digitalogic_browser', $browser );
		$request->headers->set( 'Authorization', $browser['authorization'] );
	}

	/**
	 * Internal attribute cannot be supplied by a client's JSON or headers.
	 *
	 * @param Request $request Internal request.
	 */
	public static function active( Request $request ): bool {
		return is_array( $request->attributes->get( 'digitalogic_browser' ) );
	}

	/**
	 * Store a generation as an encrypted host-only Secure HttpOnly cookie.
	 *
	 * @param Response $response Outgoing response.
	 * @param array    $context Private context.
	 */
	public static function cookie( Response $response, array $context ): void {
		$response->headers->setCookie( new Cookie( self::COOKIE, self::encode( $context ), $context['expires_at'], '/', null, true, true, false, Cookie::SAMESITE_STRICT ) );
		$response->headers->set( 'Cache-Control', 'private, no-store' );
	}

	/**
	 * Remove native credentials before a browser receives an identity/session result.
	 *
	 * @param Request  $request Validated internal browser request.
	 * @param Response $response Adapter response.
	 * @return Response Browser-safe response.
	 */
	public static function finish( Request $request, Response $response ): Response {
		if ( ! self::active( $request ) ) {
			return $response;
		}
		$response->headers->set( 'Cache-Control', 'private, no-store' );
		if ( ! str_contains( (string) $response->headers->get( 'Content-Type' ), 'application/json' ) ) {
			return $response;
		}
		$data = json_decode( $response->getContent(), true );
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$context = $request->attributes->get( 'digitalogic_browser' )['context'];
		if ( isset( $data['access_token'] ) ) {
			$session = is_string( $data['access_token'] ) ? MobileIdentityStore::authenticate( 'Bearer ' . $data['access_token'], false ) : null;
			if ( ! $session || ( $data['session_id'] ?? '' ) !== $session['id'] || ( isset( $data['profile']['id'] ) && (int) $data['profile']['id'] !== (int) $session['user_id'] ) ) {
				return response()->json( array( 'code' => 'browser_session_unavailable' ), 503, array( 'Cache-Control' => 'private, no-store' ) );
			}
			$context = array_merge(
				self::anonymous(),
				array(
					'access_token' => $data['access_token'],
					'session_id'   => $session['id'],
					'expires_at'   => (int) $session['expires_at'],
				)
			);
			unset( $data['access_token'], $data['token_type'] );
			$data['session_type']  = 'browser';
			$data['authenticated'] = true;
			$data['csrf_token']    = $context['csrf'];
			self::cookie( $response, $context );
		} elseif ( str_ends_with( $request->path(), '/auth/logout' ) && ( ! $request->json( 'session_id' ) || $request->json( 'session_id' ) === ( $context['session_id'] ?? null ) ) && ( ! empty( $data['revoked'] ) || 401 === $response->getStatusCode() ) ) {
			$context              = self::anonymous();
			$data['csrf_token']   = $context['csrf'];
			$data['session_type'] = 'browser';
			self::cookie( $response, $context );
		}
		$response->setContent( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return $response;
	}

	/**
	 * Create an origin/cookie-bound nonce before displaying Google's browser button.
	 *
	 * @param array  $context Validated browser generation.
	 * @param string $request_id Stable UUID for challenge retries.
	 * @param string $network Server-selected remote address scope.
	 * @return array|\WP_Error Public challenge or closed failure.
	 */
	public static function googleChallenge( array $context, string $request_id, string $network ) {
		global $wpdb;
		if ( ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $request_id ) ) {
			return self::error( 'browser_google_invalid_request', 400 );
		}
		$key  = '_dgl_pwa_google_' . MobileIdentityStore::hash( $context['id'] . ':' . strtolower( $request_id ) );
		$lock = 'dgl-pwa-google-' . substr( $key, -40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) {
			return self::error( 'browser_google_busy', 409 );
		}
		try {
			$receipt = get_transient( $key );
			if ( is_array( $receipt ) ) {
				return ( $receipt['expires_at_epoch'] ?? 0 ) > time() ? $receipt['public'] : self::error( 'browser_google_expired', 410 );
			}
			if ( ! MobileIdentityStore::admit( 'pwa-google:' . $context['id'], 10, 300 ) || ! MobileIdentityStore::admit( 'pwa-google-ip:' . $network, 20, 300 ) || ! MobileIdentityStore::admit( 'pwa-google-global', 200, 300 ) ) {
				return self::error( 'rate_limited', 429 );
			}
			$proof = array(
				'purpose'    => 'pwa_google_login',
				'context_id' => $context['id'],
				'nonce'      => bin2hex( random_bytes( 32 ) ),
				'created_at' => time(),
				'expires_at' => time() + 300,
			);
			$data  = array(
				'challenge_id' => MobileRegistrationStore::seal( wp_json_encode( $proof ), 'pwa-google-v1' ),
				'nonce'        => $proof['nonce'],
				'expires_at'   => gmdate( 'c', $proof['expires_at'] ),
			);
			if ( ! set_transient(
				$key,
				array(
					'expires_at_epoch' => $proof['expires_at'],
					'public'           => $data,
				),
				600
			) ) {
				return self::error( 'browser_google_unavailable', 503 );
			}
			return $data;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * A signed Google nonce must match this origin's current cookie generation.
	 *
	 * @param array $context Current private context.
	 * @param mixed $challenge Sealed server challenge.
	 * @param array $claims Cryptographically verified Google claims.
	 * @return array|\WP_Error Fresh proof metadata; ordinary Google replay fencing still applies.
	 */
	public static function verifyGoogleChallenge( array $context, $challenge, array $claims ) {
		$plain = is_string( $challenge ) && strlen( $challenge ) <= 3000 ? MobileRegistrationStore::open( $challenge, 'pwa-google-v1' ) : null;
		$proof = $plain ? json_decode( $plain, true ) : null;
		if ( ! is_array( $proof ) || ( $proof['purpose'] ?? null ) !== 'pwa_google_login' || ! is_string( $proof['context_id'] ?? null ) || ! hash_equals( $context['id'], $proof['context_id'] )
			|| ! is_int( $proof['created_at'] ?? null ) || ! is_int( $proof['expires_at'] ?? null ) || $proof['expires_at'] <= time() || $proof['created_at'] > time() || $proof['created_at'] < time() - 300
			|| ! is_string( $claims['nonce'] ?? null ) || ! is_string( $proof['nonce'] ?? null ) || ! hash_equals( $proof['nonce'], $claims['nonce'] ) ) {
			return self::error( 'browser_google_invalid_challenge', 403 );
		}
		return array(
			'verified_at' => $proof['created_at'],
			'created_at'  => $proof['created_at'],
			'expires_at'  => $proof['expires_at'],
		);
	}

	/**
	 * Closed browser transport errors do not disclose private cookie/session contents.
	 *
	 * @param string $code Stable client code.
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'Browser session request could not be completed.', array( 'status' => $status ) );
	}
}
