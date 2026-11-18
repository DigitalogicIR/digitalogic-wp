<?php
/**
 * Browser-managed sign-in and external OIDC clients for the branded login.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Issue short-lived, browser-bound challenges and create native WordPress sessions. */
final class Digitalogic_Federated_Login {

	const ROUTE                   = 'digitalogic/v1/federated-login';
	private const CALLBACK_COOKIE = 'digitalogic_federation_return';
	private const TTL             = 600;

	/**
	 * Return destination for the authenticated browser's success step.
	 *
	 * @var string
	 */
	private static string $resume = '';

	/** Register the relying-party routes and disable the obsolete Google prompt on this login. */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
		add_action( 'login_init', array( self::class, 'disable_legacy_prompt' ), 1 );
		add_action( 'digitalogic_expire_federation_claim', array( self::class, 'expire_claim' ) );
	}

	/** Register the same-origin endpoints and the top-level authorization code return. */
	public static function register(): void {
		foreach ( array( 'prepare', 'complete', 'callback' ) as $action ) {
			register_rest_route(
				'digitalogic/v1',
				'/federated-login/' . $action,
				array(
					'methods'             => 'callback' === $action ? 'GET' : 'POST',
					'callback'            => array( self::class, $action ),
					'permission_callback' => 'callback' === $action ? '__return_true' : array( self::class, 'same_origin' ),
				)
			);
		}
	}

	/** Keep the ordinary OAuth button while preventing a second Google SDK initialization. */
	public static function disable_legacy_prompt(): void {
		if ( isset( self::providers()['google'] ) ) {
			add_filter( 'option_digits_option_social_settings', array( self::class, 'without_legacy_prompt' ), 20 );
		}
	}

	/**
	 * Suppress only the vendor's automatic prompt for this request.
	 *
	 * @param mixed $settings Social provider settings.
	 */
	public static function without_legacy_prompt( $settings ) {
		if ( is_array( $settings['google'] ?? null ) ) {
			$settings['google']['oneTapSignIn'] = false;
		}
		return $settings;
	}

	/** Load trusted provider definitions. Only explicitly provisioned providers are offered. */
	private static function providers(): array {
		$providers = array();
		$google    = get_option( 'digits_option_social_settings', array() )['google'] ?? array();
		$client_id = defined( 'DIGITALOGIC_GIS_CLIENT_ID' ) ? DIGITALOGIC_GIS_CLIENT_ID : ( $google['clientID'] ?? '' );
		if ( is_string( $client_id ) && preg_match( '/\A[0-9]+-[a-zA-Z0-9_-]+\.apps\.googleusercontent\.com\z/', $client_id )
			&& ( defined( 'DIGITALOGIC_GIS_CLIENT_ID' ) || ! empty( $google['enable'] ) ) ) {
			$providers['google'] = array(
				'type'      => 'google',
				'label'     => 'Google',
				'issuer'    => 'https://accounts.google.com',
				'jwks_uri'  => 'https://www.googleapis.com/oauth2/v3/certs',
				'client_id' => $client_id,
			);
		}
		foreach ( (array) get_option( 'digitalogic_oidc_providers', array() ) as $key => $provider ) {
			if ( ! is_string( $key ) || 'google' === $key || ! preg_match( '/\A[a-z][a-z0-9_-]{0,31}\z/', $key )
				|| ! is_array( $provider ) || ! is_string( $provider['client_id'] ?? null ) || '' === $provider['client_id']
				|| ! self::https_url( $provider['issuer'] ?? '' ) || ! self::https_url( $provider['jwks_uri'] ?? '' ) ) {
				continue;
			}
			$provider['type']  = 'oidc';
			$providers[ $key ] = $provider;
		}
		return $providers;
	}

	/**
	 * Validate a configured endpoint. The request URL never comes from a sign-in form or token.
	 *
	 * @param mixed $value Endpoint URL.
	 */
	private static function https_url( $value ): bool {
		$url = is_string( $value ) ? wp_parse_url( $value ) : false;
		return is_array( $url ) && ! empty( $url['host'] ) && 'https' === ( $url['scheme'] ?? '' )
			&& ! isset( $url['user'] ) && ! isset( $url['pass'] ) && ! isset( $url['fragment'] );
	}

	/** Return only public configuration to the login script. */
	public static function client_config(): array {
		$client = array();
		foreach ( self::providers() as $key => $provider ) {
			$google   = 'google' === $provider['type'];
			$redirect = self::https_url( $provider['authorization_endpoint'] ?? '' ) && self::https_url( $provider['token_endpoint'] ?? '' );
			$fedcm    = ! $google && self::https_url( $provider['fedcm_config_url'] ?? '' ) ? $provider['fedcm_config_url'] : '';
			if ( $google || $redirect || $fedcm ) {
				$client[] = array(
					'key'      => $key,
					'label'    => sanitize_text_field( (string) ( $provider['label'] ?? $key ) ),
					'clientId' => $provider['client_id'],
					'fedcm'    => $fedcm,
					'redirect' => $redirect,
					'oneTap'   => $google && (bool) apply_filters( 'digitalogic_google_one_tap', true ),
				);
			}
		}
		return array(
			'prepare'   => rest_url( self::ROUTE . '/prepare' ),
			'complete'  => rest_url( self::ROUTE . '/complete' ),
			'providers' => $client,
			'passkeys'  => (bool) apply_filters( 'digitalogic_conditional_passkeys', true ),
			'resume'    => self::$resume,
		);
	}

	/** Keep the success card available only to the authenticated browser returning from OIDC. */
	public static function resume_after_redirect(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, bound to the authenticated account and returning browser.
		$receipt = is_string( $_GET['federation_resume'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['federation_resume'] ) ) : '';
		if ( ! is_user_logged_in() || ! preg_match( '/\A[a-f0-9]{64}\z/', $receipt ) ) {
			return false;
		}
		$record = get_transient( 'dg_federation_resume_' . $receipt );
		if ( ! is_array( $record ) || get_current_user_id() !== $record['user'] || ! hash_equals( $record['binding'], self::binding( true ) ) ) {
			return false;
		}
		self::$resume = $record['return'];
		return true;
	}

	/**
	 * Restrict browser credential exchanges to the canonical sign-in origin and binding.
	 *
	 * @param WP_REST_Request $request Credential exchange request.
	 */
	public static function same_origin( WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		$site   = $request->get_header( 'sec-fetch-site' );
		return is_ssl() && is_string( $origin ) && hash_equals( Digitalogic_Auth_Realtime::origin(), $origin )
			&& ( ! $site || 'same-origin' === $site ) && '' !== self::binding();
	}

	/**
	 * Return the browser binding for the relevant transport.
	 *
	 * @param bool $callback Read the Lax cookie for a top-level OAuth return.
	 */
	private static function binding( bool $callback = false ): string {
		$name = $callback ? self::CALLBACK_COOKIE : Digitalogic_Auth_Realtime::COOKIE;
		return Digitalogic_Auth_Realtime::binding( self::cookie( $name ) );
	}

	/**
	 * Read an opaque signed cookie.
	 *
	 * @param string $name Cookie name.
	 */
	private static function cookie( string $name ): string {
		return is_string( $_COOKIE[ $name ] ?? null ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	/**
	 * Create a nonce and optional PKCE authorization request for one trusted provider.
	 *
	 * @param WP_REST_Request $request Login context submitted by the browser.
	 */
	public static function prepare( WP_REST_Request $request ) {
		$key      = $request->get_param( 'provider' );
		$provider = is_string( $key ) ? ( self::providers()[ $key ] ?? null ) : null;
		$mode     = $request->get_param( 'mode' );
		$redirect = 'redirect' === $mode;
		if ( ! $provider || ! in_array( $mode, array( 'browser', 'redirect' ), true ) || wp_validate_auth_cookie( '', 'logged_in' )
			|| ( $redirect && ( ! self::https_url( $provider['authorization_endpoint'] ?? '' ) || ! self::https_url( $provider['token_endpoint'] ?? '' ) ) )
			|| ( ! $redirect && 'google' !== $provider['type'] && ! self::https_url( $provider['fedcm_config_url'] ?? '' ) ) ) {
			return self::failure();
		}
		$binding   = self::binding();
		$rate_key  = 'dg_federation_rate_' . hash( 'sha256', $binding . ':' . (string) floor( time() / 60 ) );
		$issuances = (int) get_transient( $rate_key );
		if ( $issuances >= 20 ) {
			return self::failure( 'federation_rate_limit', 429 );
		}
		set_transient( $rate_key, $issuances + 1, 60 );

		$nonce  = bin2hex( random_bytes( 32 ) );
		$record = array(
			'provider' => $key,
			'issuer'   => $provider['issuer'],
			'audience' => $provider['client_id'],
			'binding'  => $binding,
			'mode'     => $mode,
			'return'   => Digitalogic_Call_Verification::login_destination( $request->get_param( 'redirect_to' ) ),
			'flow'     => Digitalogic_Auth_Realtime::flow( $request->get_param( 'flow' ) ),
			'remember' => true === $request->get_param( 'remember' ),
			'verifier' => $redirect ? bin2hex( random_bytes( 32 ) ) : '',
		);
		if ( ! set_transient( 'dg_federation_' . $nonce, $record, self::TTL ) ) {
			return self::failure( 'federation_unavailable', 503 );
		}
		if ( ! $redirect ) {
			return self::reply(
				array(
					'nonce'      => $nonce,
					'expires_in' => self::TTL,
				)
			);
		}
		setcookie(
			self::CALLBACK_COOKIE,
			self::cookie( Digitalogic_Auth_Realtime::COOKIE ),
			array(
				'expires'  => time() + self::TTL,
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// PKCE requires URL-safe Base64 without padding.
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $record['verifier'], true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 encoding.
		$query     = array(
			'response_type'         => 'code',
			'client_id'             => $provider['client_id'],
			'redirect_uri'          => rest_url( self::ROUTE . '/callback' ),
			'scope'                 => 'openid profile email',
			'state'                 => $nonce,
			'nonce'                 => $nonce,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		return self::reply( array( 'authorization_url' => add_query_arg( $query, $provider['authorization_endpoint'] ) ) );
	}

	/**
	 * Finish a browser-managed sign-in with an actual signed credential.
	 *
	 * @param WP_REST_Request $request Same-origin credential and its challenge.
	 */
	public static function complete( WP_REST_Request $request ) {
		$nonce  = $request->get_param( 'nonce' );
		$record = self::record( $nonce, 'browser', self::binding() );
		$token  = $request->get_param( 'credential' );
		if ( ! $record || ! is_string( $token ) ) {
			return self::failure( 'federation_expired', 403 );
		}
		$record['remember'] = true === $request->get_param( 'remember' );
		$result             = self::sign_in( $nonce, $record, $token );
		return is_wp_error( $result ) ? $result : self::reply( array( 'redirect' => $result ) );
	}

	/**
	 * Exchange a code returned by an explicitly configured external OIDC issuer.
	 *
	 * @param WP_REST_Request $request Top-level OAuth return.
	 */
	public static function callback( WP_REST_Request $request ) {
		$nonce    = $request->get_param( 'state' );
		$record   = is_ssl() ? self::record( $nonce, 'redirect', self::binding( true ) ) : null;
		$provider = $record ? ( self::providers()[ $record['provider'] ] ?? null ) : null;
		$code     = $request->get_param( 'code' );
		$issuer   = $request->get_param( 'iss' );
		$fallback = add_query_arg( 'federation_error', '1', Digitalogic_Plugin_Auth_Routes::canonical_login_url() );
		if ( ! $record || ! $provider || ! self::https_url( $provider['token_endpoint'] ?? '' )
			|| $record['issuer'] !== $provider['issuer'] || $record['audience'] !== $provider['client_id']
			|| ! is_string( $code ) || '' === $code || strlen( $code ) > 4096 || ( null !== $issuer && $issuer !== $provider['issuer'] )
			|| ! self::claim( $nonce, 'exchange' ) ) {
			return self::redirect( $fallback );
		}
		$body    = array(
			'grant_type'    => 'authorization_code',
			'client_id'     => $provider['client_id'],
			'code_verifier' => $record['verifier'],
			'redirect_uri'  => rest_url( self::ROUTE . '/callback' ),
			'code'          => $code,
		);
		$headers = array();
		$secrets = defined( 'DIGITALOGIC_OIDC_CLIENT_SECRETS' ) ? (array) DIGITALOGIC_OIDC_CLIENT_SECRETS : array();
		$secret  = $secrets[ $record['provider'] ] ?? '';
		if ( is_string( $secret ) && '' !== $secret ) {
			if ( 'client_secret_post' === ( $provider['token_endpoint_auth_method'] ?? '' ) ) {
				$body['client_secret'] = $secret;
			} else {
				$headers['Authorization'] = 'Basic ' . base64_encode( rawurlencode( $provider['client_id'] ) . ':' . rawurlencode( $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth client authentication.
			}
		}
		$response = wp_safe_remote_post(
			$provider['token_endpoint'],
			array(
				'body'                => $body,
				'headers'             => $headers,
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 65536,
			)
		);
		$data     = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response )
			? json_decode( wp_remote_retrieve_body( $response ), true, 16 ) : null;
		$result   = is_string( $data['id_token'] ?? null ) ? self::sign_in( $nonce, $record, $data['id_token'] ) : self::failure();
		if ( is_wp_error( $result ) ) {
			return self::redirect( add_query_arg( 'redirect_to', $record['return'], $fallback ) );
		}
		$resume  = array(
			'user'    => get_current_user_id(),
			'binding' => $record['binding'],
			'return'  => $result,
		);
		$receipt = bin2hex( random_bytes( 32 ) );
		if ( ! set_transient( 'dg_federation_resume_' . $receipt, $resume, 90 ) ) {
			return self::redirect( $result );
		}
		return self::redirect( add_query_arg( 'federation_resume', $receipt, Digitalogic_Plugin_Auth_Routes::canonical_login_url() ) );
	}

	/**
	 * Read a live challenge that belongs to the same browser and transport.
	 *
	 * @param mixed  $nonce   Challenge identifier.
	 * @param string $mode    Expected transport.
	 * @param string $binding Verified signed browser binding.
	 */
	private static function record( $nonce, string $mode, string $binding ): ?array {
		if ( ! is_string( $nonce ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $nonce ) || '' === $binding ) {
			return null;
		}
		$record = get_transient( 'dg_federation_' . $nonce );
		return is_array( $record ) && $mode === $record['mode'] && hash_equals( $record['binding'], $binding ) ? $record : null;
	}

	/**
	 * Atomically reserve one exchange or session claim across PHP workers.
	 *
	 * @param string $nonce Verified challenge.
	 * @param string $purpose Exchange or completion.
	 */
	private static function claim( string $nonce, string $purpose ): bool {
		$key = 'dg_federation_used_' . hash( 'sha256', $purpose . ':' . $nonce );
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, 1, 'digitalogic_federation', self::TTL );
		}
		if ( ! add_option( $key, 1, '', false ) ) {
			return false;
		}
		wp_schedule_single_event( time() + self::TTL + 60, 'digitalogic_expire_federation_claim', array( $key ) );
		return true;
	}

	/**
	 * Remove an expired SQL replay marker where no persistent cache is installed.
	 *
	 * @param string $key Replay-marker option key.
	 */
	public static function expire_claim( string $key ): void {
		if ( preg_match( '/\Adg_federation_used_[a-f0-9]{64}\z/', $key ) ) {
			delete_option( $key );
		}
	}

	/**
	 * Create a WordPress session only for the exact, already associated issuer subject.
	 *
	 * @param string $nonce  Browser challenge.
	 * @param array  $record Bound request context.
	 * @param string $token  ID token.
	 */
	private static function sign_in( string $nonce, array $record, string $token ) {
		if ( wp_validate_auth_cookie( '', 'logged_in' ) ) {
			return self::failure();
		}
		$provider = self::providers()[ $record['provider'] ] ?? null;
		$claims   = $provider && $record['audience'] === $provider['client_id'] && $record['issuer'] === $provider['issuer']
			? Digitalogic_OIDC_Token::verify( $token, $provider, $nonce ) : null;
		if ( ! $claims ) {
			return self::failure();
		}
		$user = self::account( $provider, $claims['sub'] );
		if ( ! $user ) {
			return self::failure( 'federation_account_unlinked', 409 );
		}
		if ( 'google' === $provider['type'] && $claims['iat'] < (int) get_user_meta( $user->ID, '_digitalogic_google_binding_not_before', true ) ) {
			return self::failure();
		}
		$approved = Digitalogic_Call_Verification::instance()->authenticate_verified_user( $user );
		if ( ! $approved instanceof WP_User || $user->ID !== $approved->ID || ! self::claim( $nonce, 'session' ) ) {
			return self::failure();
		}
		delete_transient( 'dg_federation_' . $nonce );
		Digitalogic_Auth_Realtime::correlate_rest( $record['flow'] );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $record['remember'], true );
		do_action( 'wp_login', $user->user_login, $user );
		return $record['return'];
	}

	/**
	 * Resolve a provisioned issuer subject independently of the email claim.
	 *
	 * @param array  $provider Trusted provider.
	 * @param string $subject  Verified subject.
	 */
	private static function account( array $provider, string $subject ): ?WP_User {
		$id = (int) ( $provider['accounts'][ $subject ] ?? 0 );
		if ( 'google' === $provider['type'] && ctype_digit( $subject ) ) {
			$matches = get_users(
				array(
					'meta_key'    => 'auth_google_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reuse the existing Digits association by indexed key.
					'meta_value'  => $subject, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Check the exact issued subject.
					'fields'      => 'ID',
					'number'      => 2,
					'count_total' => false,
				)
			);
			if ( count( $matches ) > 1 ) {
				return null;
			}
			$id = (int) ( $matches[0] ?? 0 );
		}
		$id   = (int) apply_filters( 'digitalogic_federated_user_id', $id, $provider['issuer'], $subject );
		$user = $id > 0 ? get_user_by( 'id', $id ) : false;
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Return a private response.
	 *
	 * @param array $data Public result.
	 */
	private static function reply( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Redirect to a validated same-origin destination.
	 *
	 * @param string $destination Return URL.
	 */
	private static function redirect( string $destination ): WP_REST_Response {
		$response = self::reply( array() );
		$response->set_status( 303 );
		$response->header( 'Location', $destination );
		return $response;
	}

	/**
	 * Return a public denial without provider or account details.
	 *
	 * @param string $code   Application error code.
	 * @param int    $status HTTP status.
	 */
	private static function failure( string $code = 'federation_denied', int $status = 401 ): WP_Error {
		return new WP_Error( $code, __( 'Browser sign-in could not be completed.', 'digitalogic' ), array( 'status' => $status ) );
	}
}
