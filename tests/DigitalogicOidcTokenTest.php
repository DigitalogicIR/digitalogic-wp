<?php
/**
 * Test real signatures and identity claim fences with a temporary RSA key.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-oidc-token.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-federated-login.php';

/** A sound identity proof needs both a valid signature and the expected context. */
final class DigitalogicOidcTokenTest extends TestCase {

	/** Verify signatures and reject issuer, audience, nonce and time errors. */
	public function test_an_oidc_token_must_match_the_whole_challenge(): void {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		self::assertInstanceOf( OpenSSLAsymmetricKey::class, $key );
		$rsa      = openssl_pkey_get_details( $key )['rsa'];
		$encode   = static function ( string $value ): string {
			return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWS fixtures.
		};
		$keys     = array(
			array(
				'kid' => 'fixture',
				'kty' => 'RSA',
				'n'   => $encode( $rsa['n'] ),
				'e'   => $encode( $rsa['e'] ),
			),
		);
		$provider = array(
			'issuer'    => 'https://issuer.digitalogic.test',
			'client_id' => 'test-client',
		);
		$now      = time();
		$claims   = array(
			'iss'   => $provider['issuer'],
			'aud'   => $provider['client_id'],
			'nonce' => 'challenge',
			'sub'   => 'account-42',
			'iat'   => $now - 2,
			'exp'   => $now + 180,
		);
		$parts    = new ReflectionMethod( Digitalogic_OIDC_Token::class, 'parts' );
		$verify   = new ReflectionMethod( Digitalogic_OIDC_Token::class, 'verify_parts' );
		$sign     = static function ( array $payload ) use ( $key, $encode ): string {
			$message = $encode( '{"alg":"RS256","kid":"fixture"}' ) . '.' . $encode( wp_json_encode( $payload ) );
			openssl_sign( $message, $signature, $key, OPENSSL_ALGO_SHA256 );
			return $message . '.' . $encode( $signature );
		};
		$jwt      = $sign( $claims );
		$decoded  = $parts->invoke( null, $jwt );
		self::assertSame( 'account-42', $verify->invoke( null, $decoded, $provider, 'challenge', $keys, $now )['sub'] );

		foreach ( array(
			'nonce' => 'previous',
			'iss'   => 'https://untrusted.test',
			'aud'   => array( 'test-client', 'other' ),
			'azp'   => 'other',
			'exp'   => $now - 1,
			'iat'   => $now + 45,
		) as $name => $value ) {
			$bad = $parts->invoke( null, $sign( array_replace( $claims, array( $name => $value ) ) ) );
			self::assertNull( $verify->invoke( null, $bad, $provider, 'challenge', $keys, $now ), $name );
		}
		$decoded[3][0] = chr( ord( $decoded[3][0] ) ^ 1 );
		self::assertNull( $verify->invoke( null, $decoded, $provider, 'challenge', $keys, $now ) );
		self::assertNull( $parts->invoke( null, $encode( '{"alg":"none","kid":"fixture"}' ) . '.payload.signature' ) );
	}

	/** A browser ID token cannot be replayed on the redirect route or another browser. */
	public function test_challenge_must_match_transport_and_signed_browser_binding(): void {
		$nonce = str_repeat( 'a', 64 );
		$key   = 'dg_federation_' . $nonce;
		$state = array(
			'mode'    => 'browser',
			'binding' => 'browser-1',
		);
		set_transient( $key, $state );
		try {
			$record = new ReflectionMethod( Digitalogic_Federated_Login::class, 'record' );
			self::assertSame( $state, $record->invoke( null, $nonce, 'browser', 'browser-1' ) );
			self::assertNull( $record->invoke( null, $nonce, 'browser', 'browser-2' ) );
			self::assertNull( $record->invoke( null, $nonce, 'redirect', 'browser-1' ) );
			self::assertNull( $record->invoke( null, 'bad', 'browser', 'browser-1' ) );
		} finally {
			delete_transient( $key );
		}
	}
}
