<?php
/**
 * Private realtime state for the branded WordPress login.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Share private login events between WordPress requests and the socket service. */
final class Digitalogic_Auth_Realtime {

	const COOKIE = 'digitalogic_auth_binding';
	const ROUTE  = '/digitalogic/v1/auth-events';
	const STEPS  = array( 'login', 'password', 'contact', 'recover', 'signup', 'code', 'call', 'call-code' );
	/** @var array|null Session issued during the current HTTP request. */
	private static $issued_session;
	/** @var string Correlation ID supplied by the verified PBX consume request. */
	private static $rest_flow = '';

	/** Wire the private HTTP fallback and observe native session issuance. */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve' ), 10, 3 );
		add_action( 'set_logged_in_cookie', array( self::class, 'capture_session' ), 10, 6 );
		add_action( 'wp_login', array( self::class, 'authenticated' ), 10, 2 );
	}

	/** Create the login-only binding before WordPress sends the login page. */
	public static function bind_browser(): void {
		if ( ! is_ssl() || headers_sent() || self::binding( self::http_cookie() ) ) {
			return;
		}
		$identity = bin2hex( random_bytes( 24 ) );
		$value    = $identity . '.' . ( time() + 2 * HOUR_IN_SECONDS );
		setcookie(
			self::COOKIE,
			$value . '.' . hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) ),
			array(
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	/** Read the HttpOnly login binding from the incoming HTTP request. */
	private static function http_cookie(): string {
		return is_string( $_COOKIE[ self::COOKIE ] ?? null ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
	}

	/**
	 * Validate the integrity and expiry of a binding from either transport.
	 *
	 * @param string $cookie Signed browser cookie.
	 */
	public static function binding( string $cookie ): string {
		if ( ! preg_match( '/\A([a-f0-9]{48})\.([0-9]{10})\.([a-f0-9]{64})\z/', $cookie, $parts ) || (int) $parts[2] <= time() ) {
			return '';
		}
		return hash_equals( hash_hmac( 'sha256', $parts[1] . '.' . $parts[2], wp_salt( 'nonce' ) ), $parts[3] ) ? $parts[1] : '';
	}

	/** Canonical origin shared by the WebSocket upgrade and HTTP stream. */
	public static function origin(): string {
		$parts = wp_parse_url( home_url() );
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( empty( $parts['port'] ) ? '' : ':' . $parts['port'] );
	}

	/**
	 * Accept only random v4 IDs scoped to the browser binding.
	 *
	 * @param mixed $value Browser flow candidate.
	 */
	public static function flow( $value ): string {
		return is_string( $value ) && wp_is_uuid( $value, 4 ) ? strtolower( $value ) : '';
	}

	/**
	 * Resolve one private flow within its browser, never by ID alone.
	 *
	 * @param string $binding Verified browser binding.
	 * @param string $flow    Browser flow ID.
	 */
	public static function key( string $binding, string $flow ): string {
		return $binding && $flow ? 'dg_auth_' . hash_hmac( 'sha256', $flow, $binding ) : '';
	}

	/**
	 * Read a fresh state from workers outside the WebSocket process.
	 *
	 * @param string $key Storage key.
	 */
	private static function read( string $key ): array {
		if ( ! $key ) {
			return array();
		}
		if ( wp_using_ext_object_cache() ) {
			$state = wp_cache_get( $key, 'transient', true );
			return is_array( $state ) ? $state : array();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			wp_cache_delete( '_transient_' . $key, 'options' );
			wp_cache_delete( '_transient_timeout_' . $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$state = get_transient( $key );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Return an audience-safe state, without the account or session identifiers.
	 *
	 * @param string $key Storage key.
	 */
	public static function snapshot( string $key ): array {
		$state     = self::read( $key );
		$confirmed = self::read( $key . '_session' );
		return array(
			'step'          => $confirmed ? 'success' : ( $state['step'] ?? 'login' ),
			'authenticated' => (bool) $confirmed,
			'revision'      => $confirmed['revision'] ?? $state['revision'] ?? '',
		);
	}

	/**
	 * Track only allowlisted steps. A client cannot report authentication success.
	 *
	 * @param string $key  Storage key.
	 * @param mixed  $step Reported step.
	 */
	public static function update( string $key, $step ): void {
		if ( ! $key || ! in_array( $step, self::STEPS, true ) ) {
			return;
		}
		$previous = self::read( $key );
		if ( ( $previous['step'] ?? null ) === $step || self::read( $key . '_session' ) ) {
			return;
		}
		$state = array(
			'step'     => $step,
			'revision' => self::revision(),
		);
		set_transient( $key, $state, 30 * MINUTE_IN_SECONDS );
		do_action( 'digitalogic_auth_realtime_event', $key, $state );
	}

	/**
	 * Set correlation for the already verified PBX consume request.
	 *
	 * @param mixed $flow Browser flow candidate.
	 */
	public static function correlate_rest( $flow ): void {
		self::$rest_flow = self::flow( $flow );
	}

	/**
	 * Capture the issued session token as a fingerprint for the subsequent wp_login hook.
	 *
	 * @param string $cookie     Generated cookie.
	 * @param int    $expire     Browser expiration.
	 * @param int    $expiration Session expiration.
	 * @param int    $user       Authenticated account ID.
	 * @param string $scheme     Cookie scheme.
	 * @param string $token      WordPress session token.
	 */
	public static function capture_session( $cookie, $expire, $expiration, $user, $scheme, $token ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Native hook signature.
		self::$issued_session = array(
			'user'    => (int) $user,
			'session' => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
		);
	}

	/**
	 * Record the exact issued session only after WordPress confirms login.
	 *
	 * @param string  $name Authenticated login name.
	 * @param WP_User $user Authenticated account.
	 */
	public static function authenticated( $name, WP_User $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Native hook signature.
		$issued               = self::$issued_session;
		self::$issued_session = null;
		if ( ! $issued || $issued['user'] !== (int) $user->ID ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Correlation only, after native authentication.
		$value = isset( $_POST['dg_auth_flow'] ) ? sanitize_text_field( wp_unslash( $_POST['dg_auth_flow'] ) ) : '';
		$flow  = self::$rest_flow ? self::$rest_flow : self::flow( $value );
		$key   = self::key( self::binding( self::http_cookie() ), $flow );
		if ( ! $key ) {
			return;
		}
		$event = array_merge(
			$issued,
			array(
				'step'     => 'success',
				'revision' => self::revision(),
			)
		);
		set_transient( $key . '_session', $event, 5 * MINUTE_IN_SECONDS );
		do_action( 'digitalogic_auth_realtime_event', $key, $event );
	}

	/** Register the SSE endpoint, with a same-origin HTTP stage fallback. */
	public static function register(): void {
		register_rest_route(
			'digitalogic/v1',
			'/auth-events',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( self::class, 'response' ),
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
	}

	/**
	 * The stream is private to its binding and refuses cross-site reads or writes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function allowed( WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		$site   = $request->get_header( 'sec-fetch-site' );
		return is_ssl() && (bool) self::binding( self::http_cookie() ) && ( ! $site || 'same-origin' === $site )
			&& ( $origin ? hash_equals( self::origin(), $origin ) : 'GET' === $request->get_method() )
			&& (bool) self::flow( $request->get_param( 'flow' ) );
	}

	/**
	 * Read or update a snapshot, optionally including PBX status for this tab.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function response( WP_REST_Request $request ) {
		$key  = self::key( self::binding( self::http_cookie() ), self::flow( $request->get_param( 'flow' ) ) );
		$post = 'POST' === $request->get_method();
		if ( $post ) {
			self::update( $key, $request->get_param( 'step' ) );
		}
		$data = self::snapshot( $key );
		$call = $request->get_param( 'call' );
		if ( ! $post && is_string( $call ) && $call && class_exists( Digitalogic_Call_Verification::class ) ) {
			$binding = is_string( $_COOKIE['digitalogic_call_binding'] ?? null ) ? sanitize_text_field( wp_unslash( $_COOKIE['digitalogic_call_binding'] ) ) : '';
			$result  = Digitalogic_Call_Verification::instance()->socket_challenge_status(
				array(
					'challenge_id' => $call,
					'csrf_token'   => $request->get_header( 'x-digitalogic-csrf' ),
				),
				self::origin(),
				$binding
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$data['call'] = $result->get_data();
		}
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Send a bounded SSE snapshot and let EventSource reconnect with a fresh request.
	 *
	 * @param bool             $served  Whether a previous filter handled the response.
	 * @param WP_HTTP_Response $result  Dispatch result.
	 * @param WP_REST_Request  $request Incoming request.
	 */
	public static function serve( $served, WP_HTTP_Response $result, WP_REST_Request $request ): bool {
		if ( $served || self::ROUTE !== $request->get_route() || 200 !== $result->get_status()
			|| 'GET' !== $request->get_method() || ! str_contains( (string) $request->get_header( 'accept' ), 'text/event-stream' ) ) {
			return (bool) $served;
		}
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'Content-Encoding: identity' );
		echo "retry: 4000\nevent: snapshot\ndata: " . wp_json_encode( $result->get_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON event payload.
		return true;
	}

	/** An opaque version for deduplicating snapshots. */
	private static function revision(): string {
		return bin2hex( random_bytes( 8 ) );
	}
}
