<?php
/**
 * Google GIS popup code exchange for the isolated PWA cookie session.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 adapter contracts.

/** A separate code contract never relaxes the ID-token nonce verifier. */
final class MobileBrowserGoogleCode {

	/** Provider credentials stay in the canonical Digits server option. */
	private static function secret(): string {
		$config = get_option( 'digits_option_social_settings', array() );
		$secret = $config['google']['clientSecretID'] ?? null;
		return is_string( $secret ) && strlen( $secret ) >= 10 && strlen( $secret ) <= 512 && ! preg_match( '/[\x00-\x20\x7f]/', $secret ) ? $secret : '';
	}

	/** Public capability discloses only whether the existing provider is ready. */
	public static function configured(): bool {
		return MobileBrowserSession::enabled() && MobileGoogleIdentity::configured() && '' !== self::secret();
	}

	/**
	 * Validate browser state before any provider request or one-use reservation.
	 *
	 * @param array $context Gateway-validated cookie generation.
	 * @param array $input Bounded JSON request.
	 * @return array|\WP_Error Original fresh proof metadata or a closed failure.
	 */
	public static function proof( array $context, array $input ) {
		if ( array_diff( array_keys( $input ), array( 'code', 'challenge_id', 'state', 'device_name' ) )
			|| ! is_string( $input['code'] ?? null ) || strlen( $input['code'] ) < 8 || strlen( $input['code'] ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $input['code'] )
			|| ! is_string( $input['challenge_id'] ?? null ) || strlen( $input['challenge_id'] ) > 3000
			|| ! is_string( $input['state'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $input['state'] )
			|| ( isset( $input['device_name'] ) && ( ! is_string( $input['device_name'] ) || strlen( $input['device_name'] ) > 160 ) ) ) {
			return self::error( 'browser_google_invalid_request', 400 );
		}
		$plain = MobileRegistrationStore::open( $input['challenge_id'], 'pwa-google-code-v1' );
		$proof = $plain ? json_decode( $plain, true ) : null;
		if ( ! is_array( $proof ) || ( $proof['purpose'] ?? null ) !== 'pwa_google_code' || ( $proof['origin'] ?? null ) !== MobileBrowserSession::ORIGIN
			|| ( $proof['audience'] ?? null ) !== MobileGoogleIdentity::audience()
			|| ! is_string( $context['id'] ?? null ) || ! is_string( $proof['context_id'] ?? null ) || ! hash_equals( $context['id'], $proof['context_id'] )
			|| ! is_string( $proof['nonce'] ?? null ) || ! hash_equals( $proof['nonce'], $input['state'] )
			|| ! is_int( $proof['created_at'] ?? null ) || ! is_int( $proof['expires_at'] ?? null ) || $proof['expires_at'] <= time()
			|| $proof['created_at'] > time() || $proof['created_at'] < time() - 300 || $proof['expires_at'] !== $proof['created_at'] + 300 ) {
			return self::error( 'browser_google_invalid_challenge', 403 );
		}
		return $proof;
	}

	/**
	 * Consume once before the external exchange, which must never be auto-replayed.
	 *
	 * @param array  $context Gateway-validated cookie generation.
	 * @param array  $input Exact user-gesture code response.
	 * @param string $network Gateway-selected network scope, never a client body value.
	 * @return array|\WP_Error Verified claims/token/freshness for the canonical identity resolver.
	 */
	public static function exchange( array $context, array $input, string $network ) {
		if ( ! self::configured() ) {
			return self::error( 'browser_google_code_unavailable', 503 );
		}
		$proof = self::proof( $context, $input );
		if ( is_wp_error( $proof ) ) {
			return $proof;
		}
		if ( ! MobileIdentityStore::admit( 'google-global', 120, 60 ) || ! MobileIdentityStore::admit( 'google-ip:' . $network, 20, 60 ) ) {
			return self::error( 'rate_limited', 429 );
		}
		// Persist only a digest and bounded expiry through the existing unique SQL receipt.
		// An ambiguous provider timeout consumes this attempt; restart with a new popup.
		if ( ! MobileIdentityStore::claimGoogleToken( 'pwa-code:' . $input['challenge_id'], $proof['expires_at'] + 300 ) ) {
			return self::error( 'browser_google_code_used', 409 );
		}
		try {
			$response = wp_safe_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'limit_response_size' => 16384,
					'headers'             => array( 'Accept' => 'application/json' ),
					'body'                => array(
						'code'          => $input['code'],
						'client_id'     => MobileGoogleIdentity::audience(),
						'client_secret' => self::secret(),
						'redirect_uri'  => MobileBrowserSession::ORIGIN,
						'grant_type'    => 'authorization_code',
					),
				)
			);
		} catch ( \Throwable $error ) {
			return self::error( 'browser_google_exchange_uncertain', 503 );
		}
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 500 ) {
			return self::error( 'browser_google_exchange_uncertain', 503 );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::error( 'browser_google_code_rejected', 409 );
		}
		$body = wp_remote_retrieve_body( $response );
		$data = is_string( $body ) && strlen( $body ) <= 16384 ? json_decode( $body, true ) : null;
		if ( ! is_array( $data ) || ! is_string( $data['id_token'] ?? null ) ) {
			return self::error( 'browser_google_code_rejected', 409 );
		}
		try {
			// This response came from our one-use code exchange, not a caller-supplied ID token.
			$claims = MobileGoogleIdentity::verify( $data['id_token'], MobileGoogleIdentity::keys(), $proof['audience'], $proof['audience'] );
		} catch ( \Throwable $error ) {
			return self::error( 'browser_google_code_rejected', 409 );
		}
		if ( $claims['iat'] < $proof['created_at'] - 30 || $proof['expires_at'] <= time() ) {
			return self::error( 'browser_google_code_rejected', 409 );
		}
		return array(
			'claims' => $claims,
			'token'  => $data['id_token'],
			'fresh'  => array(
				'created_at'  => $proof['created_at'],
				'verified_at' => $proof['created_at'],
				'expires_at'  => $proof['expires_at'],
			),
		);
	}

	/**
	 * Return a bounded failure without provider response bodies or credentials.
	 *
	 * @param string $code Stable public code.
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'ورود گوگل کامل نشد؛ دوباره از دکمه گوگل آغاز کنید.', array( 'status' => $status ) );
	}
}
