<?php
/**
 * PWA authorization-code exchange boundaries with fake HTTP and signed fixture JWTs.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.Files.FileName, Universal.Files.SeparateFunctionsFromOO -- PHPUnit file includes one isolated WordPress HTTP fixture.

use Digitalogic\Laravel\Services\MobileBrowserGoogleCode as GoogleCode;
use Digitalogic\Laravel\Services\MobileBrowserSession as Browser;
use Digitalogic\Laravel\Services\MobileGoogleIdentity as GoogleIdentity;
use Digitalogic\Laravel\Services\MobileRegistrationStore as Sealing;
use Digitalogic\Vendor\Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MobileBrowserSessionTest.php';

if ( ! function_exists( 'wp_safe_remote_post' ) ) {

	/**
	 * Delegate to the existing in-memory WordPress HTTP fixture, never the network.
	 *
	 * @param  string $url  Exact URL.
	 * @param  array  $args Request arguments.
	 * @return array|WP_Error Fixture response.
	 */
	function wp_safe_remote_post( $url, $args = array() ) {
		$args['reject_unsafe_urls'] = true;
		return wp_remote_post( $url, $args );
	}
}

/**
 * No provider, account, session or OTP is created by these tests.
 */
final class MobileBrowserGoogleCodeTest extends TestCase {


	private const AUDIENCE = '123456789-browserfixture.apps.googleusercontent.com';
	private const SECRET   = 'fixture-only-not-a-live-client-secret';
	private const UUID     = '079fdcbe-c45c-4aef-9b0b-587b8c6f68a0';
	/**
	 * Saved test-global state.
	 *
	 * @var array
	 */
	private array $saved = array();
	/**
	 * Anonymous isolated browser generation.
	 *
	 * @var array
	 */
	private array $context;
	/**
	 * In-memory SQL/rate fixture.
	 *
	 * @var MobileBrowserSessionFixtureDatabase
	 */
	private MobileBrowserSessionFixtureDatabase $database;
	/**
	 * Ephemeral fixture-only RSA key, never written or logged.
	 *
	 * @var string
	 */
	private static string $private_key = '';
	/**
	 * Fixture public keys.
	 *
	 * @var array
	 */
	private static array $jwks;
	/**
	 * Unique deterministic request counter, avoiding the shared fixed UUID stub.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Generate local fixture keys with an explicit portable OpenSSL configuration.
	 */
	public static function setUpBeforeClass(): void {
		$config = tempnam( sys_get_temp_dir(), 'dgl-code-openssl-' );
     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary public OpenSSL test configuration only.
		file_put_contents( $config, "[req]\ndistinguished_name=req_distinguished_name\n[req_distinguished_name]\n" );
		try {
			$key = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
					'config'           => $config,
				)
			);
			openssl_pkey_export( $key, self::$private_key, null, array( 'config' => $config ) );
		} finally {
         // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the created fixture configuration.
			unlink( $config );
		}
		$details = openssl_pkey_get_details( $key );
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard public JWK encoding.
		$encode     = static fn( $value )=>rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
		self::$jwks = array(
			'keys' => array(
				array(
					'kty' => 'RSA',
					'alg' => 'RS256',
					'use' => 'sig',
					'kid' => 'browser-code-fixture',
					'n'   => $encode( $details['rsa']['n'] ),
					'e'   => $encode( $details['rsa']['e'] ),
				),
			),
		);
		GoogleIdentity::loadLibrary();
	}

	/**
	 * Isolate every SQL, option, transient and HTTP fixture.
	 */
	protected function setUp(): void {
		foreach ( array( 'wpdb', 'digitalogic_test_options', 'digitalogic_test_option_cache', 'digitalogic_test_transients', 'digitalogic_test_transient_set_callback', 'digitalogic_test_remote_posts', 'digitalogic_test_remote_post_results' ) as $key ) {
			$this->saved[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$this->database                                     = new MobileBrowserSessionFixtureDatabase();
		$GLOBALS['wpdb']                                    = $this->database;
		$GLOBALS['digitalogic_test_options']                = array(
			'digitalogic_mobile_browser_auth'          => true,
			'digitalogic_mobile_native_auth'           => true,
			'digitalogic_mobile_identity_schema'       => '2',
			'digits_option_social_settings'            => array(
				'google' => array(
					'clientID'       => self::AUDIENCE,
					'clientSecretID' => self::SECRET,
				),
			),
			'digitalogic_mobile_google_android_client' => '123456789-androidfixture.apps.googleusercontent.com',
		);
		$GLOBALS['digitalogic_test_option_cache']           = array();
		$GLOBALS['digitalogic_test_transients']             = array();
		$GLOBALS['digitalogic_test_transient_set_callback'] = null;
		$GLOBALS['digitalogic_test_remote_posts']           = array();
		$GLOBALS['digitalogic_test_remote_post_results']    = array();
		set_transient( 'digitalogic_mobile_google_jwks', self::$jwks, 600 );
		$this->context = Browser::anonymous();
	}

	/**
	 * Restore shared test state and prove every advisory lock was released.
	 */
	protected function tearDown(): void {
		self::assertSame( $this->database->acquired, $this->database->released );
		foreach ( $this->saved as $key => $value ) {
			$GLOBALS[ $key ] = $value;
		}
	}

	/**
	 * Create the real sealed challenge and corresponding fake provider code input.
	 *
	 * @return array Exact request shape.
	 */
	private function input(): array {
		$challenge = Browser::googleChallenge( $this->context, $this->request_id(), '192.0.2.61', 'code' );
		self::assertIsArray( $challenge );
		return array(
			'code'         => 'fixture-authorization-code',
			'challenge_id' => $challenge['challenge_id'],
			'state'        => $challenge['nonce'],
			'device_name'  => 'Fixture browser',
		);
	}

	/**
	 * Return a unique deterministic fixture UUID.
	 *
	 * @return string Request UUID.
	 */
	private function request_id(): string {
		return sprintf( 'a79fdcbe-c45c-4aef-9b0b-%012d', ++$this->sequence );
	}

	/**
	 * Sign actual JWT claims with the ephemeral fixture key.
	 *
	 * @param  array $changes Claim overrides.
	 * @return string Signed fixture JWT.
	 */
	private function token( array $changes = array() ): string {
		$claims = array_replace(
			array(
				'iss'            => 'https://accounts.google.com',
				'aud'            => self::AUDIENCE,
				'azp'            => self::AUDIENCE,
				'sub'            => '123456789012345678901',
				'email'          => 'fixture@example.test',
				'email_verified' => true,
				'iat'            => time(),
				'exp'            => time() + 300,
			),
			$changes
		);
		return JWT::encode( $claims, self::$private_key, 'RS256', 'browser-code-fixture' );
	}

	/**
	 * Assert the stable closed error contract.
	 *
	 * @param string $code   Expected error code.
	 * @param int    $status Expected HTTP status.
	 * @param mixed  $result Actual result.
	 */
	private function failure( string $code, int $status, $result ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		self::assertSame( $status, $result->get_error_data()['status'] );
		self::assertStringNotContainsString( self::SECRET, $result->get_error_message() );
	}

	/**
	 * Only the real configured server secret enables the code flow.
	 */
	public function test_configuration_and_missing_secret_fail_before_exchange(): void {
		self::assertTrue( GoogleCode::configured() );
		$input = $this->input();
		foreach ( array( null, '', 'short', 'contains whitespace', array() ) as $secret ) {
			$GLOBALS['digitalogic_test_options']['digits_option_social_settings']['google']['clientSecretID'] = $secret;
			self::assertFalse( GoogleCode::configured() );
			$this->failure( 'browser_google_code_unavailable', 503, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
		}
		self::assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
	}

	/**
	 * Same UUID replays one challenge; different flow cannot repurpose it.
	 */
	public function test_code_challenge_is_bound_and_legacy_id_token_flow_unchanged(): void {
		$code = Browser::googleChallenge( $this->context, self::UUID, '192.0.2.61', 'code' );
		self::assertSame( 'code', $code['flow'] );
		self::assertSame( $code, Browser::googleChallenge( $this->context, self::UUID, '192.0.2.61', 'code' ) );
		$this->failure( 'browser_google_challenge_conflict', 409, Browser::googleChallenge( $this->context, self::UUID, '192.0.2.61' ) );
		$this->failure( 'browser_google_invalid_challenge', 403, Browser::verifyGoogleChallenge( $this->context, $code['challenge_id'], array( 'nonce' => $code['nonce'] ) ) );
		$legacy = Browser::googleChallenge( $this->context, $this->request_id(), '192.0.2.61' );
		self::assertArrayNotHasKey( 'flow', $legacy );
		self::assertIsArray( Browser::verifyGoogleChallenge( $this->context, $legacy['challenge_id'], array( 'nonce' => $legacy['nonce'] ) ) );
		$input                 = $this->input();
		$input['challenge_id'] = $legacy['challenge_id'];
		$input['state']        = $legacy['nonce'];
		$this->failure( 'browser_google_invalid_challenge', 403, GoogleCode::proof( $this->context, $input ) );
	}

	/**
	 * Audience, origin, purpose, generation, state and timestamps are all authoritative.
	 */
	public function test_wrong_proof_bindings_fail_before_provider_or_reservation(): void {
		$input = $this->input();
		$proof = GoogleCode::proof( $this->context, $input );
		self::assertIsArray( $proof );
		foreach ( array(
			array( 'purpose' => 'pwa_google_login' ),
			array( 'origin' => 'https://other.invalid' ),
			array( 'audience' => 'different' ),
			array( 'context_id' => str_repeat( '0', 64 ) ),
			array( 'nonce' => str_repeat( '0', 64 ) ),
			array( 'created_at' => time() + 30 ),
			array(
				'created_at' => time() - 301,
				'expires_at' => time() - 1,
			),
			array( 'expires_at' => $proof['expires_at'] + 1 ),
		) as $change ) {
			$invalid                 = $input;
			$invalid['challenge_id'] = Sealing::seal( wp_json_encode( array_replace( $proof, $change ) ), 'pwa-google-code-v1' );
			$this->failure( 'browser_google_invalid_challenge', 403, GoogleCode::exchange( $this->context, $invalid, '192.0.2.61' ) );
		}
		$this->failure( 'browser_google_invalid_challenge', 403, GoogleCode::exchange( Browser::anonymous(), $input, '192.0.2.61' ) );
		self::assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
		self::assertSame( array(), array_filter( array_keys( $GLOBALS['digitalogic_test_options'] ), static fn( $name )=>str_starts_with( $name, '_dgl_mobile_google_used_' ) ) );
	}

	/**
	 * Client redirects and malformed codes never reach the provider.
	 */
	public function test_invalid_input_is_rejected_before_provider(): void {
		$input = $this->input();
		foreach ( array( array( 'redirect_uri' => 'https://other.invalid' ), array( 'code' => 'short' ), array( 'code' => "contains\nnewline" ), array( 'code' => str_repeat( 'a', 4097 ) ), array( 'state' => 'bad' ), array( 'challenge_id' => array() ), array( 'device_name' => array() ) ) as $change ) {
			$this->failure( 'browser_google_invalid_request', 400, GoogleCode::exchange( $this->context, array_replace( $input, $change ), '192.0.2.61' ) );
		}
		self::assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
	}

	/**
	 * The server exchanges once at its fixed origin and verifies the signed token.
	 */
	public function test_success_uses_fixed_provider_contract_and_claims_once(): void {
		$input = $this->input();
		$token = $this->token();
		$GLOBALS['digitalogic_test_remote_post_results'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'id_token' => $token ) ),
		);
		$result = GoogleCode::exchange( $this->context, $input, '192.0.2.61' );
		self::assertIsArray( $result );
		self::assertSame( $token, $result['token'] );
		self::assertSame( self::AUDIENCE, $result['claims']['aud'] );
		self::assertSame( GoogleCode::proof( $this->context, $input )['created_at'], $result['fresh']['verified_at'] );
		self::assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
		$request = $GLOBALS['digitalogic_test_remote_posts'][0];
		self::assertSame( 'https://oauth2.googleapis.com/token', $request['url'] );
		self::assertSame( 0, $request['args']['redirection'] );
		self::assertSame( 8, $request['args']['timeout'] );
		self::assertSame( 16384, $request['args']['limit_response_size'] );
		self::assertSame(
			array(
				'code'          => $input['code'],
				'client_id'     => self::AUDIENCE,
				'client_secret' => self::SECRET,
				'redirect_uri'  => Browser::ORIGIN,
				'grant_type'    => 'authorization_code',
			),
			$request['args']['body']
		);
		$this->failure( 'browser_google_code_used', 409, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
		self::assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
	}

	/**
	 * A timeout is uncertain and permanently consumes that attempt without retries.
	 */
	public function test_timeout_exception_and_server_error_are_consumed_once(): void {
		foreach ( array( new WP_Error( 'http_request_failed', 'fixture timeout' ), new RuntimeException( 'fixture timeout' ), array( 'response' => array( 'code' => 503 ) ) ) as $response ) {
			$input = $this->input();
			$GLOBALS['digitalogic_test_remote_post_results'][] = $response;
			$before = count( $GLOBALS['digitalogic_test_remote_posts'] );
			$this->failure( 'browser_google_exchange_uncertain', 503, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
			$this->failure( 'browser_google_code_used', 409, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
			self::assertCount( $before + 1, $GLOBALS['digitalogic_test_remote_posts'] );
		}
	}

	/**
	 * Provider rejection, malformed data and invalid signed identities fail closed.
	 */
	public function test_rejected_provider_responses_and_claims_never_issue_identity(): void {
		$responses = array(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => 'private provider detail',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => str_repeat( 'x', 16385 ),
			),
		);
		foreach ( array( array( 'aud' => 'other' ), array( 'azp' => '123456789-androidfixture.apps.googleusercontent.com' ), array( 'iss' => 'https://other.invalid' ), array( 'email_verified' => false ), array( 'iat' => time() - 120 ), array( 'exp' => time() - 1 ) ) as $change ) {
			$responses[] = array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id_token' => $this->token( $change ) ) ),
			);
		}
		foreach ( $responses as $response ) {
			$input = $this->input();
			$GLOBALS['digitalogic_test_remote_post_results'][] = $response;
			$this->failure( 'browser_google_code_rejected', 409, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
			$this->failure( 'browser_google_code_used', 409, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
		}
		self::assertCount( count( $responses ), $GLOBALS['digitalogic_test_remote_posts'] );
	}

	/**
	 * Rate refusal does not consume an unattempted code.
	 */
	public function test_rate_limit_precedes_one_use_reservation(): void {
		$input                       = $this->input();
		$this->database->rateAllowed = false;
		$this->failure( 'rate_limited', 429, GoogleCode::exchange( $this->context, $input, '192.0.2.61' ) );
		self::assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
		self::assertSame( array(), array_filter( array_keys( $GLOBALS['digitalogic_test_options'] ), static fn( $name )=>str_starts_with( $name, '_dgl_mobile_google_used_' ) ) );
	}
}
