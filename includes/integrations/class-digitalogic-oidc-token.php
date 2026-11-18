<?php
/**
 * Verification of nonce-bound OIDC ID tokens against a trusted provider.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Validate signed identity assertions without trusting key URLs from the token. */
final class Digitalogic_OIDC_Token {

	/**
	 * Read only the configured issuer's keys and verify the ID token.
	 *
	 * @param string $token    Signed ID token.
	 * @param array  $provider Trusted issuer, client ID, and JWKS endpoint.
	 * @param string $nonce    Nonce from the original browser challenge.
	 */
	public static function verify( string $token, array $provider, string $nonce ): ?array {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return null;
		}
		$parts = self::parts( $token );
		if ( null === $parts ) {
			return null;
		}
		$url   = $provider['jwks_uri'];
		$cache = 'dg_oidc_jwks_' . hash( 'sha256', $url );
		$keys  = get_transient( $cache );
		if ( ! is_array( $keys ) ) {
			$response = self::fetch_keys( $url );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$document = json_decode( wp_remote_retrieve_body( $response ), true, 12 );
			$keys     = $document['keys'] ?? null;
			if ( ! is_array( $keys ) || count( $keys ) > 32 ) {
				return null;
			}
			$policy = (string) wp_remote_retrieve_header( $response, 'cache-control' );
			if ( ! preg_match( '/\b(?:no-store|no-cache)\b/i', $policy ) ) {
				$max_age = preg_match( '/\bmax-age=(\d+)\b/i', $policy, $age ) ? min( 3600, (int) $age[1] ) : 300;
				if ( $max_age > 0 ) {
					set_transient( $cache, $keys, $max_age );
				}
			}
		}
		return self::verify_parts( $parts, $provider, $nonce, $keys, time() );
	}

	/**
	 * Fetch public keys over HTTPS, bypassing the WordPress-wide proxy for Google's fixed key URL.
	 *
	 * @param string $url Trusted provider's JWKS URL.
	 */
	private static function fetch_keys( string $url ) {
		if ( 'https://www.googleapis.com/oauth2/v3/certs' !== $url || ! function_exists( 'curl_init' ) ) {
			return wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'limit_response_size' => 65536,
				)
			);
		}

		// Keep this override scoped to Google's public signing keys.
		$handle = curl_init( $url );
		if ( false === $handle ) {
			return new WP_Error( 'dg_jwks_transport' );
		}
		$headers = array();
		$body    = '';
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_PROXY          => defined( 'DIGITALOGIC_GOOGLE_JWKS_PROXY' ) ? DIGITALOGIC_GOOGLE_JWKS_PROXY : '',
				CURLOPT_NOPROXY        => '',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 6,
				CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$headers ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- cURL callback signature.
					if ( 0 === stripos( $line, 'Cache-Control:' ) ) {
						$headers['cache-control'] = trim( substr( $line, 14 ) );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $curl, string $chunk ) use ( &$body ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- cURL callback signature.
					if ( strlen( $body ) + strlen( $chunk ) > 65536 ) {
						return 0;
					}
					$body .= $chunk;
					return strlen( $chunk );
				},
			)
		);
		if ( false === curl_exec( $handle ) ) {
			return new WP_Error( 'dg_jwks_transport' );
		}
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array( 'code' => curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) ),
		);
	}

	/**
	 * Verify with a supplied key set, including clock and audience checks.
	 *
	 * @param array  $parts    Parsed JWT segments.
	 * @param array  $provider Trusted issuer and client ID.
	 * @param string $nonce    Expected nonce.
	 * @param array  $keys     Trusted public JWKs.
	 * @param int    $now      Current Unix time.
	 */
	private static function verify_parts( array $parts, array $provider, string $nonce, array $keys, int $now ): ?array {
		list( $header, $claims, $message, $signature ) = $parts;

		$client   = $provider['client_id'];
		$issuers  = array( $provider['issuer'] );
		$audience = is_string( $claims['aud'] ?? null ) ? array( $claims['aud'] ) : ( $claims['aud'] ?? null );
		$subject  = $claims['sub'] ?? null;
		if ( 'google' === ( $provider['type'] ?? '' ) ) {
			$issuers[] = 'accounts.google.com';
		}
		if ( ! in_array( $claims['iss'] ?? null, $issuers, true )
			|| ! is_string( $subject ) || ! preg_match( '/\A[\x21-\x7e]{1,255}\z/', $subject )
			|| ! is_array( $audience ) || ! in_array( $client, $audience, true )
			|| ( ( count( $audience ) > 1 || isset( $claims['azp'] ) ) && ( $claims['azp'] ?? null ) !== $client )
			|| ! is_int( $claims['exp'] ?? null ) || $claims['exp'] <= $now
			|| ! is_int( $claims['iat'] ?? null ) || $claims['iat'] > $now + 30 || $claims['iat'] >= $claims['exp']
			|| ( isset( $claims['nbf'] ) && ( ! is_int( $claims['nbf'] ) || $claims['nbf'] > $now + 30 ) )
			|| '' === $nonce || ! is_string( $claims['nonce'] ?? null ) || ! hash_equals( $nonce, $claims['nonce'] )
		) {
			return null;
		}
		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) || ( $key['kid'] ?? null ) !== $header['kid'] ) {
				continue;
			}
			$pem = self::rsa_public_key( $key );
			if ( $pem && 1 === openssl_verify( $message, $signature, $pem, OPENSSL_ALGO_SHA256 ) ) {
				return $claims;
			}
		}
		return null;
	}

	/**
	 * Read a compact RS256 JWS and reject unrecognized critical extensions.
	 *
	 * @param string $token Signed ID token.
	 */
	private static function parts( string $token ): ?array {
		if ( strlen( $token ) > 12288 || ! preg_match( '/\A([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]+)\z/', $token, $parts ) ) {
			return null;
		}
		$header = json_decode( self::decode( $parts[1] ), true, 8 );
		$claims = json_decode( self::decode( $parts[2] ), true, 16 );
		$kid    = $header['kid'] ?? null;
		if ( ! is_array( $claims ) || 'RS256' !== ( $header['alg'] ?? null )
			|| ! is_string( $kid ) || strlen( $kid ) > 192 || '' === $kid || isset( $header['crit'] ) || isset( $header['b64'] )
		) {
			return null;
		}
		return array( $header, $claims, $parts[1] . '.' . $parts[2], self::decode( $parts[3] ) );
	}

	/**
	 * Encode a public RSA JWK as SubjectPublicKeyInfo for OpenSSL.
	 *
	 * @param array $key Matching trusted JWK.
	 */
	private static function rsa_public_key( array $key ): string {
		if ( 'RSA' !== ( $key['kty'] ?? '' ) || 'sig' !== ( $key['use'] ?? 'sig' ) || 'RS256' !== ( $key['alg'] ?? 'RS256' )
			|| ! is_string( $key['n'] ?? null ) || ! is_string( $key['e'] ?? null ) ) {
			return '';
		}
		$modulus  = self::decode( $key['n'] );
		$exponent = self::decode( $key['e'] );
		if ( strlen( $modulus ) < 256 || strlen( $modulus ) > 1024 || '' === $exponent || strlen( $exponent ) > 4 ) {
			return '';
		}
		if ( "\0" === $modulus[0] || "\0" === $exponent[0] ) {
			return '';
		}
		$integer = static function ( string $bytes ): string {
			return self::der( 2, ord( $bytes[0] ) >= 128 ? "\0" . $bytes : $bytes );
		};
		$rsa     = self::der( 0x30, $integer( $modulus ) . $integer( $exponent ) );
		// rsaEncryption OID and NULL parameters, followed by the public key BIT STRING.
		$spki = self::der( 0x30, hex2bin( '300d06092a864886f70d0101010500' ) . self::der( 3, "\0" . $rsa ) );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard PEM-armored public key.
	}

	/**
	 * Encode a short ASN.1 DER type-length-value.
	 *
	 * @param int    $tag   ASN.1 tag.
	 * @param string $value Binary value.
	 */
	private static function der( int $tag, string $value ): string {
		$length = strlen( $value );
		$bytes  = ltrim( pack( 'N', $length ), "\0" );
		return chr( $tag ) . ( $length < 128 ? chr( $length ) : chr( 0x80 | strlen( $bytes ) ) . $bytes ) . $value;
	}

	/**
	 * Decode an unpadded Base64URL value.
	 *
	 * @param string $value Encoded value.
	 */
	private static function decode( string $value ): string {
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]+\z/', $value ) ) {
			return '';
		}
		$bytes = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT Base64URL encoding.
		return is_string( $bytes ) ? $bytes : '';
	}
}
