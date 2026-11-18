<?php
/**
 * One authoritative public Android release channel without files or publication.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use Digitalogic\Laravel\Http\Controllers\MobileReleaseController;
use Digitalogic\Laravel\Services\MobileRelease as Release;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';

/** The real publication/controller/cache logic reads a controlled authoritative SQL snapshot. */
final class MobileReleaseChannelTest extends TestCase {
	/**
	 * Original global presence/value pairs.
	 *
	 * @var array
	 */
	private array $saved = array();
	/**
	 * Previous Laravel container.
	 *
	 * @var Container
	 */
	private Container $previous_container;
	/**
	 * Authoritative serialized option inputs, separate from the WP option cache.
	 *
	 * @var array
	 */
	private array $values = array();
	/**
	 * Number of authoritative publication SQL reads.
	 *
	 * @var int
	 */
	private int $reads = 0;
	/**
	 * Simulated authoritative database failure.
	 *
	 * @var bool
	 */
	private bool $database_failure = false;
	/**
	 * Reverse SQL row order to prove order-independent revision identity.
	 *
	 * @var bool
	 */
	private bool $reverse_rows = false;
	/**
	 * Optional publication race at a SQL statement boundary.
	 *
	 * @var callable|null
	 */
	private $rows_provider = null;

	/** Establish local DB/cache doubles and real Laravel HTTP responses. */
	protected function setUp(): void {
		foreach ( array( 'wpdb', 'digitalogic_test_options', 'digitalogic_test_option_cache', 'digitalogic_test_current_user_id', 'digitalogic_test_object_cache_enabled', 'digitalogic_test_object_cache', 'digitalogic_test_cache_gets', 'digitalogic_test_object_cache_sets', 'digitalogic_test_cache_set_callback' ) as $name ) {
			$this->saved[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		$GLOBALS['digitalogic_test_options']              = array( 'digitalogic_report_cache_generation_v1' => 'catalog-generation-fixture' );
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_current_user_id']      = 0;
		$GLOBALS['digitalogic_test_object_cache_enabled'] = true;
		$GLOBALS['digitalogic_test_object_cache']         = array();
		$GLOBALS['digitalogic_test_cache_gets']           = array();
		$GLOBALS['digitalogic_test_object_cache_sets']    = array();
		unset( $GLOBALS['digitalogic_test_cache_set_callback'] );
		$read                     = function ( $prepared, $database ) {
			++$this->reads;
			self::assertStringContainsString( 'SELECT option_name,option_value', $prepared['query'] );
			self::assertSame( array( Release::OPTION, 'digitalogic_mobile_update_manifest' ), $prepared['args'] );
			$database->last_error = $this->database_failure ? 'Synthetic database failure.' : '';
			if ( $this->database_failure ) {
				return null;
			}
			$values = is_callable( $this->rows_provider ) ? ( $this->rows_provider )( $this->reads ) : $this->values;
			$rows   = array();
			foreach ( $values as $name => $value ) {
				$rows[] = array(
					'option_name'  => $name,
					'option_value' => maybe_serialize( $value ),
				);
			}
			return $this->reverse_rows ? array_reverse( $rows ) : $rows;
		};
		$GLOBALS['wpdb']          = new class($read) extends Digitalogic_Test_WPDB {
			/**
			 * Install a read-only publication SQL fixture.
			 *
			 * @param Closure $reader Fixture statement reader.
			 */
			public function __construct( private Closure $reader ) {}
			/**
			 * Read one authoritative publication statement.
			 *
			 * @param mixed $prepared Canonical prepared query.
			 * @param mixed $output Requested row representation.
			 * @return array|null Fixture rows or explicit database failure.
			 */
			public function get_results( $prepared, $output = ARRAY_A ) {
				unset( $output );
				return ( $this->reader )( $prepared, $this );
			}
		};
		$this->values             = $this->publicationValues( 23 );
		$this->previous_container = Container::getInstance();
		$container                = new Container();
		$factory                  = $this->createStub( ResponseFactory::class );
		$factory->method( 'json' )->willReturnCallback( static fn( $data, $status = 200, $headers = array(), $options = 0 ) => new JsonResponse( $data, $status, $headers, $options ) );
		$factory->method( 'make' )->willReturnCallback( static fn( $data = '', $status = 200, $headers = array() ) => new LaravelResponse( $data, $status, $headers ) );
		$container->instance( ResponseFactory::class, $factory );
		Container::setInstance( $container );
	}

	/** Restore globals/container without leaving synthetic public state in other tests. */
	protected function tearDown(): void {
		foreach ( $this->saved as $name => $value ) {
			if ( $value[0] ) {
				$GLOBALS[ $name ] = $value[1];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		Container::setInstance( $this->previous_container );
	}

	/**
	 * Synthetic public manifest only; no real APK, account or credentials.
	 *
	 * @param int $version Exact selected version.
	 * @return array Valid public metadata.
	 */
	private function manifest( int $version = 23 ): array {
		return array(
			'package'             => 'ir.digitalogic.app',
			'version_code'        => $version,
			'version_name'        => '0.1.0',
			'min_sdk'             => 24,
			'apk_url'             => Release::url( $version, 'apk' ),
			'sha256'              => str_repeat( 'a', 64 ),
			'bytes'               => 2048,
			'signing_cert_sha256' => Release::CERTIFICATE,
		);
	}

	/**
	 * One SQL statement's two authoritative options.
	 *
	 * @param int  $version Active issued build.
	 * @param bool $enabled Whether automatic update offers are enabled.
	 * @return array Authoritative option map.
	 */
	private function publicationValues( int $version, bool $enabled = true ): array {
		return array(
			Release::OPTION                      => $this->manifest( $version ) + array(
				'issued_at'       => '2026-11-18T00:00:00+00:00',
				'manifest_sha256' => str_repeat( 'b', 64 ),
				'internal_field'  => 'must-not-be-public',
			),
			'digitalogic_mobile_update_manifest' => $enabled ? Release::manifestUrl() : '',
		);
	}

	/**
	 * Real controller response prepared exactly as the HTTP layer prepares HEAD.
	 *
	 * @param string $action Manifest or APK action.
	 * @param string $method GET or HEAD.
	 * @param array  $query Untrusted query values, which cannot select a release.
	 * @return Response Prepared real controller response.
	 */
	private function channel( string $action, string $method = 'GET', array $query = array() ): Response {
		$request = Request::create( '/apps/digitalogic/latest.' . ( 'manifest' === $action ? 'json' : 'apk' ), $method, $query );
		$request->headers->set( 'If-None-Match', '*' );
		return ( new MobileReleaseController() )->$action( $request )->prepare( $request );
	}

	/**
	 * Invoke the actual bootstrap cache boundary with a controlled public builder.
	 *
	 * @return Response Real cache/release-fenced HTTP response.
	 */
	private function bootstrapCache(): Response {
		$request = Request::create( '/app-api/bootstrap', 'GET' );
		$build   = static fn( array $publication ) => array(
			'release'     => $publication['release'],
			'self_update' => $publication['enabled'],
		);
		return ( new ReflectionMethod( MobileCatalogController::class, 'cached' ) )->invoke( new MobileCatalogController(), $request, 'bootstrap', array(), $build );
	}

	/** SQL state wins over stale WP option caches and unordered SQL rows. */
	public function test_publication_ignores_stale_options_and_normalizes_row_order(): void {
		foreach ( $this->publicationValues( 18 ) as $name => $value ) {
			$GLOBALS['digitalogic_test_options'][ $name ]      = $value;
			$GLOBALS['digitalogic_test_option_cache'][ $name ] = $value;
		}
		$first = Release::publication();
		self::assertSame( 23, $first['release']['version_code'] );
		self::assertTrue( $first['enabled'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first['revision'] );
		self::assertArrayNotHasKey( 'internal_field', $first['release'] );
		self::assertArrayNotHasKey( 'manifest_sha256', $first['release'] );
		$this->reverse_rows = true;
		self::assertSame( $first, Release::publication() );
		self::assertSame( 23, Release::advertised()['version_code'] );
		self::assertSame( 3, $this->reads );
	}

	/** Permanent URLs are public read-only exact aliases, never arbitrary paths. */
	public function test_exact_aliases_and_read_only_methods(): void {
		foreach ( array( '/apps/digitalogic/latest.json', '/app-api/release/manifest' ) as $path ) {
			$route = Digitalogic_Mobile_API::route( $path );
			self::assertSame( '/_digitalogic/mobile/release/manifest', $route );
			self::assertSame( array( 'GET', 'HEAD' ), Digitalogic_Mobile_API::methods( $route ) );
		}
		foreach ( array( '/apps/digitalogic/latest.apk', '/app-api/release/apk' ) as $path ) {
			$route = Digitalogic_Mobile_API::route( $path );
			self::assertSame( '/_digitalogic/mobile/release/apk', $route );
			self::assertSame( array( 'GET', 'HEAD' ), Digitalogic_Mobile_API::methods( $route ) );
		}
		foreach ( array( '/apps/digitalogic/latest.apk/redirect', '/apps/digitalogic/latest.exe', '/apps/digitalogic/digitalogic-23-arm64.apk', '/app-api/release/issue' ) as $path ) {
			self::assertNull( Digitalogic_Mobile_API::route( $path ) );
		}
	}

	/** GET/HEAD always resolve current DB state; caller inputs cannot select a destination. */
	public function test_manifest_and_redirect_get_head_are_fresh_and_query_independent(): void {
		$query = array(
			'url'      => 'https://other.invalid/app.apk',
			'version'  => 1,
			'redirect' => 'https://other.invalid/',
		);
		foreach ( array( 'GET', 'HEAD' ) as $method ) {
			$manifest = $this->channel( 'manifest', $method, $query );
			self::assertSame( 200, $manifest->getStatusCode() );
			self::assertTrue( $manifest->headers->hasCacheControlDirective( 'no-store' ) );
			self::assertNull( $manifest->getEtag() );
			if ( 'GET' === $method ) {
				self::assertSame( $this->manifest(), json_decode( $manifest->getContent(), true, 512, JSON_THROW_ON_ERROR ) );
			} else {
				self::assertSame( '', $manifest->getContent() );
			}
			$apk = $this->channel( 'apk', $method, $query );
			self::assertSame( 307, $apk->getStatusCode() );
			self::assertSame( Release::url( 23, 'apk' ), $apk->headers->get( 'Location' ) );
			self::assertTrue( $apk->headers->hasCacheControlDirective( 'no-store' ) );
			if ( 'HEAD' === $method ) {
				self::assertSame( '', $apk->getContent() );
			}
		}
		self::assertSame( 4, $this->reads );
	}

	/** Disabling update offers changes their revision but preserves explicit public downloads. */
	public function test_disabling_offers_does_not_disable_public_download(): void {
		$enabled      = Release::publication();
		$this->values = $this->publicationValues( 23, false );
		$disabled     = Release::publication();
		self::assertFalse( $disabled['enabled'] );
		self::assertSame( $enabled['release'], $disabled['release'] );
		self::assertNotSame( $enabled['revision'], $disabled['revision'] );
		self::assertSame( 200, $this->channel( 'manifest' )->getStatusCode() );
		self::assertSame( 307, $this->channel( 'apk' )->getStatusCode() );
	}

	/** An absent, malformed or unreadable release never falls back to cached/disk metadata. */
	public function test_unavailable_release_is_503_and_never_cacheable(): void {
		foreach ( array( null, 'malformed', array( 'version_code' => '23' ), $this->manifest() + array( 'source_commit' => 'invalid' ) ) as $state ) {
			$this->values = array(
				Release::OPTION                      => $state,
				'digitalogic_mobile_update_manifest' => Release::manifestUrl(),
			);
			foreach ( array( 'manifest', 'apk' ) as $action ) {
				$response = $this->channel( $action );
				self::assertSame( 503, $response->getStatusCode() );
				self::assertSame( array( 'code' => 'release_unavailable' ), json_decode( $response->getContent(), true, 512, JSON_THROW_ON_ERROR ) );
				self::assertTrue( $response->headers->hasCacheControlDirective( 'no-store' ) );
				self::assertFalse( $response->headers->has( 'Location' ) );
			}
		}
		$this->values           = $this->publicationValues( 23 );
		$this->database_failure = true;
		self::assertSame( 503, $this->channel( 'manifest' )->getStatusCode() );
		self::assertSame( 503, $this->channel( 'apk' )->getStatusCode() );
		try {
			Release::publication();
			self::fail( 'Database failure must remain distinguishable from an absent release.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'release_storage_unavailable', $error->getMessage() );
		}
	}

	/** Source provenance is optional, strict and never confused with the APK content digest. */
	public function test_optional_source_commit_is_strict_and_public(): void {
		$commit = str_repeat( 'c', 40 );
		$this->values[ Release::OPTION ]['source_commit'] = $commit;
		$data = json_decode( $this->channel( 'manifest' )->getContent(), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $commit, $data['source_commit'] );
		self::assertSame( str_repeat( 'a', 64 ), $data['sha256'] );
		foreach ( array( str_repeat( 'C', 40 ), str_repeat( 'c', 39 ), str_repeat( 'c', 64 ), array(), null ) as $invalid ) {
			$this->values[ Release::OPTION ]['source_commit'] = $invalid;
			self::assertSame( 503, $this->channel( 'manifest' )->getStatusCode() );
		}
	}

	/** A newer issue changes bootstrap cache identity even while old WP options remain cached. */
	public function test_bootstrap_cache_tracks_authoritative_release_snapshot(): void {
		$first = $this->bootstrapCache();
		self::assertSame( 200, $first->getStatusCode() );
		self::assertSame( 23, json_decode( $first->getContent(), true )['release']['version_code'] );
		$this->values = $this->publicationValues( 24 );
		$second       = $this->bootstrapCache();
		self::assertSame( 200, $second->getStatusCode() );
		self::assertSame( 24, json_decode( $second->getContent(), true )['release']['version_code'] );
		self::assertSame( 'MISS', $second->headers->get( 'X-Digitalogic-Cache' ) );
		self::assertNotSame( $first->getEtag(), $second->getEtag() );
	}

	/** A promotion between the initial and final snapshot cannot cache or return a mixed offer. */
	public function test_bootstrap_release_race_fails_closed_before_cache_admission(): void {
		$this->rows_provider = fn( $read ) => $this->publicationValues( 1 === $read ? 23 : 24 );
		$response            = $this->bootstrapCache();
		self::assertSame( 503, $response->getStatusCode() );
		self::assertSame( 'release_changed_during_read', json_decode( $response->getContent(), true )['code'] );
		self::assertTrue( $response->headers->hasCacheControlDirective( 'no-store' ) );
		self::assertSame( array(), $GLOBALS['digitalogic_test_object_cache_sets'] );
	}

	/** Cached offers also recheck publication after lookup instead of trusting their prior issue. */
	public function test_warm_bootstrap_cache_hit_rechecks_release_before_returning(): void {
		self::assertSame( 200, $this->bootstrapCache()->getStatusCode() );
		$writes              = count( $GLOBALS['digitalogic_test_object_cache_sets'] );
		$this->reads         = 0;
		$this->rows_provider = fn( $read ) => $this->publicationValues( 1 === $read ? 23 : 24 );
		$response            = $this->bootstrapCache();
		self::assertSame( 503, $response->getStatusCode() );
		self::assertSame( 'release_changed_during_read', json_decode( $response->getContent(), true )['code'] );
		self::assertTrue( $response->headers->hasCacheControlDirective( 'no-store' ) );
		self::assertSame( $writes, count( $GLOBALS['digitalogic_test_object_cache_sets'] ) );
	}

	/** A bootstrap may not return a warm offer when its authoritative publication read fails. */
	public function test_bootstrap_storage_failure_rejects_warm_cached_offer(): void {
		self::assertSame( 200, $this->bootstrapCache()->getStatusCode() );
		$this->database_failure = true;
		$response               = $this->bootstrapCache();
		self::assertSame( 503, $response->getStatusCode() );
		self::assertSame( 'release_unavailable', json_decode( $response->getContent(), true )['code'] );
		self::assertTrue( $response->headers->hasCacheControlDirective( 'no-store' ) );
	}
}
