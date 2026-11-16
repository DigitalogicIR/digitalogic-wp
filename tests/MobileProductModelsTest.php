<?php
/**
 * Complete model continuations and canonical catalog generation fencing.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/MobileVariationCountTest.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';

/** Uses the real controller, ReportEngine generation reader and WP cache adapter. */
final class MobileProductModelsTest extends TestCase {
	private const GENERATION_OPTION = 'digitalogic_report_cache_generation_v1';

	/**
	 * Original global presence/value pairs.
	 *
	 * @var array
	 */
	private array $saved = array();

	/**
	 * Existing Laravel service container.
	 *
	 * @var Container
	 */
	private Container $previous_container;

	/** Use isolated canonical option/cache doubles and a real HTTP response factory. */
	protected function setUp(): void {
		foreach ( array( 'wpdb', 'variation_count_fixture', 'digitalogic_test_current_user_id', 'digitalogic_test_options', 'digitalogic_test_option_cache', 'digitalogic_test_object_cache_enabled', 'digitalogic_test_object_cache', 'digitalogic_test_cache_gets', 'digitalogic_test_object_cache_sets', 'digitalogic_test_cache_set_callback' ) as $name ) {
			$this->saved[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();
		$GLOBALS['digitalogic_test_options']              = array( self::GENERATION_OPTION => 'fixture-generation-a' );
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_current_user_id']      = 0;
		$GLOBALS['digitalogic_test_object_cache_enabled'] = true;
		$GLOBALS['digitalogic_test_object_cache']         = array();
		$GLOBALS['digitalogic_test_cache_gets']           = array();
		$GLOBALS['digitalogic_test_object_cache_sets']    = array();
		unset( $GLOBALS['digitalogic_test_cache_set_callback'], $GLOBALS['variation_count_fixture'] );
		$this->previous_container = Container::getInstance();
		$container                = new Container();
		$factory                  = $this->createStub( ResponseFactory::class );
		$factory->method( 'json' )->willReturnCallback( static fn( $data, $status = 200, $headers = array(), $options = 0 ) => new JsonResponse( $data, $status, $headers, $options ) );
		$container->instance( ResponseFactory::class, $factory );
		Container::setInstance( $container );
	}

	/** Restore all globals and the container, retaining no synthetic authority. */
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
	 * Call the real shared HTTP cache boundary with a controlled public builder.
	 *
	 * @param callable     $build Public payload builder.
	 * @param array        $query Model continuation query.
	 * @param Request|null $request Actual conditional HTTP request.
	 * @return Response Controller HTTP response.
	 */
	private function cached( callable $build, array $query = array(), ?Request $request = null ): Response {
		$method  = new ReflectionMethod( MobileCatalogController::class, 'cached' );
		$request = $request ?? Request::create( '/app-api/products/9000/models', 'GET', $query );
		$query   = MobileCatalogController::normalizeModelQuery( $query );
		self::assertIsArray( $query );
		$query['id'] = 9000;
		return $method->invoke( new MobileCatalogController(), $request, 'models', $query, $build );
	}

	/**
	 * Decode only the actual response, not internal controller state.
	 *
	 * @param Response $response HTTP response.
	 * @return array JSON response body.
	 */
	private function body( Response $response ): array {
		return json_decode( $response->getContent(), true, 512, JSON_THROW_ON_ERROR );
	}

	/** Route bounds cannot expose an arbitrary route or unbounded product identifier. */
	public function test_only_exact_bounded_model_route_is_public(): void {
		self::assertSame( '/_digitalogic/mobile/products/9000/models', Digitalogic_Mobile_API::route( '/app-api/products/9000/models' ) );
		foreach ( array( '/app-api/products/0/models', '/app-api/products/-1/models', '/app-api/products/12345678901/models', '/app-api/products/9000/models/delete', '/app-api/products/9000/model' ) as $path ) {
			self::assertNull( Digitalogic_Mobile_API::route( $path ) );
		}
	}

	/** Continuations require exact 64-hex revisions and a bounded positive page size. */
	public function test_query_bounds_and_revision_requirements(): void {
		self::assertSame(
			array(
				'page'     => 1,
				'per_page' => 24,
				'revision' => '',
			),
			MobileCatalogController::normalizeModelQuery( array() )
		);
		$valid = MobileCatalogController::normalizeModelQuery(
			array(
				'page'     => '2',
				'per_page' => '999999',
				'revision' => str_repeat( 'a', 64 ),
			)
		);
		self::assertSame( 50, $valid['per_page'] );
		self::assertSame( 2, $valid['page'] );
		foreach ( array(
			array( 'page' => 0 ),
			array( 'page' => -1 ),
			array( 'page' => '1.5' ),
			array( 'page' => 1000000 ),
			array( 'page' => array( 1 ) ),
			array( 'per_page' => 0 ),
			array( 'per_page' => array( 1 ) ),
			array( 'page' => 2 ),
			array( 'revision' => str_repeat( 'a', 63 ) ),
			array( 'revision' => str_repeat( 'A', 64 ) ),
			array( 'revision' => array( 'invalid' ) ),
		) as $query ) {
			$error = MobileCatalogController::normalizeModelQuery( $query );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 400, $error->get_error_data()['status'] );
		}
	}

	/** Real models() counts the full public inventory beyond the older 100-model detail slice. */
	public function test_complete_public_inventory_and_out_of_range_continuation(): void {
		$ids                                = array_merge( range( 205, 1 ), array( 1, 206, 207, 208, 209 ) );
		$product                            = new Digitalogic_VariationCount_Fixture( 9000, 'variable', 0, children: $ids );
		$GLOBALS['variation_count_fixture'] = array( 9000 => $product );
		foreach ( range( 1, 205 ) as $id ) {
			$GLOBALS['variation_count_fixture'][ $id ] = new Digitalogic_VariationCount_Fixture( $id );
		}
		$GLOBALS['variation_count_fixture'][206] = new Digitalogic_VariationCount_Fixture( 206, status: 'private' );
		$GLOBALS['variation_count_fixture'][207] = new Digitalogic_VariationCount_Fixture( 207, visibility: 'hidden' );
		$GLOBALS['variation_count_fixture'][208] = new Digitalogic_VariationCount_Fixture( 208, parent: 9999 );
		$controller                              = new MobileCatalogController();
		$children                                = ( new ReflectionMethod( $controller, 'publicChildren' ) )->invoke( $controller, $product );
		self::assertSame( range( 1, 205 ), array_map( static fn( $child ) => $child->get_id(), $children ) );
		$initial  = $this->body( $this->cached( static fn() => array( 'items' => array() ) ) );
		$query    = array(
			'page'     => 6,
			'per_page' => 50,
			'revision' => $initial['revision'],
		);
		$response = $controller->models( Request::create( '/app-api/products/9000/models', 'GET', $query ), '9000' );
		self::assertSame( 200, $response->getStatusCode() );
		$data = $this->body( $response );
		self::assertSame( 9000, $data['parent_id'] );
		self::assertSame( array(), $data['items'] );
		self::assertSame(
			array(
				'page'        => 6,
				'per_page'    => 50,
				'total'       => 205,
				'total_pages' => 5,
			),
			$data['pagination']
		);
		self::assertSame( $initial['revision'], $data['revision'] );
	}

	/** A failed canonical generation cannot reuse a prior cached response or build fallback data. */
	public function test_generation_failure_does_not_read_the_warm_cache(): void {
		self::assertSame( 200, $this->cached( static fn() => array( 'items' => array() ) )->getStatusCode() );
		unset( $GLOBALS['digitalogic_test_options'][ self::GENERATION_OPTION ] );
		$reads    = count( $GLOBALS['digitalogic_test_cache_gets'] );
		$response = $this->cached(
			static function (): never {
				throw new LogicException( 'Generation failure must not build.' );
			}
		);
		self::assertSame( 503, $response->getStatusCode() );
		self::assertSame( 'catalog_generation_unavailable', $this->body( $response )['code'] );
		self::assertSame( $reads, count( $GLOBALS['digitalogic_test_cache_gets'] ) );
		self::assertTrue( $response->headers->hasCacheControlDirective( 'no-store' ) );
	}

	/** A change or generation-read failure during construction cannot admit a mixed projection. */
	public function test_build_time_generation_changes_fail_without_cache_admission(): void {
		foreach ( array( 'fixture-generation-b', null ) as $after ) {
			$GLOBALS['digitalogic_test_options'][ self::GENERATION_OPTION ] = 'fixture-generation-a';
			$response = $this->cached(
				static function () use ( $after ): array {
					$GLOBALS['digitalogic_test_options'][ self::GENERATION_OPTION ] = $after;
					return array( 'items' => array( array( 'id' => 123 ) ) );
				}
			);
			self::assertSame( 503, $response->getStatusCode() );
			self::assertSame(
				array(
					'code'  => 'catalog_changed_during_read',
					'reset' => true,
				),
				$this->body( $response )
			);
			self::assertSame( array(), $GLOBALS['digitalogic_test_object_cache_sets'] );
		}
	}

	/** A warm cached result still gets the second authoritative generation read. */
	public function test_cached_hit_is_rejected_when_generation_changes_during_read(): void {
		$this->cached( static fn() => array( 'items' => array() ) );
		$writes = count( $GLOBALS['digitalogic_test_object_cache_sets'] );
		foreach ( array( array( 'option_value' => 'fixture-generation-b' ), null ) as $after ) {
			$db = $this->getMockBuilder( Digitalogic_Test_WPDB::class )->onlyMethods( array( 'get_row' ) )->getMock();
			$db->expects( self::exactly( 2 ) )->method( 'get_row' )->willReturnOnConsecutiveCalls( array( 'option_value' => 'fixture-generation-a' ), $after );
			$GLOBALS['wpdb'] = $db;
			$response        = $this->cached(
				static function (): never {
					throw new LogicException( 'Warm cache must not build.' );
				}
			);
			self::assertSame( 503, $response->getStatusCode() );
			self::assertSame( 'catalog_changed_during_read', $this->body( $response )['code'] );
			self::assertSame( $writes, count( $GLOBALS['digitalogic_test_object_cache_sets'] ) );
		}
	}

	/** An old continuation is definitively reset before building or looking up a cache slot. */
	public function test_revision_mismatch_returns_current_revision_and_reset(): void {
		$first = $this->body( $this->cached( static fn() => array( 'items' => array() ) ) );
		$GLOBALS['digitalogic_test_options'][ self::GENERATION_OPTION ] = 'fixture-generation-b';
		$reads    = count( $GLOBALS['digitalogic_test_cache_gets'] );
		$response = $this->cached(
			static function (): never {
				throw new LogicException( 'Stale continuation must not build.' );
			},
			array(
				'page'     => 2,
				'revision' => $first['revision'],
			)
		);
		self::assertSame( 409, $response->getStatusCode() );
		$data = $this->body( $response );
		self::assertSame( 'catalog_revision_changed', $data['code'] );
		self::assertTrue( $data['reset'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $data['revision'] );
		self::assertNotSame( $first['revision'], $data['revision'] );
		self::assertSame( $reads, count( $GLOBALS['digitalogic_test_cache_gets'] ) );
	}

	/** Semantic weak validators survive transfer encoding suffixes and return an empty 304. */
	public function test_etag_conditional_cache_hit_returns_not_modified(): void {
		$first = $this->cached( static fn() => array( 'items' => array() ) );
		$etag  = $first->getEtag();
		self::assertNotNull( $etag );
		foreach ( array( $etag, substr( $etag, 0, -1 ) . '-gzip"', substr( $etag, 0, -1 ) . '-br"' ) as $validator ) {
			$request = Request::create( '/app-api/products/9000/models', 'GET' );
			$request->headers->set( 'If-None-Match', $validator );
			$response = $this->cached(
				static function (): never {
					throw new LogicException( 'Matching cache hit must not build.' );
				},
				request: $request
			);
			self::assertSame( 304, $response->getStatusCode() );
			self::assertSame( '', $response->getContent() );
			self::assertSame( 'HIT', $response->headers->get( 'X-Digitalogic-Cache' ) );
			self::assertSame( $etag, $response->getEtag() );
		}
	}

	/** Logged-in projections bypass public cache reads/writes and retain private no-store headers. */
	public function test_private_context_bypasses_cache_and_is_not_stored(): void {
		$this->cached( static fn() => array( 'items' => array( array( 'id' => 1 ) ) ) );
		$reads                                       = count( $GLOBALS['digitalogic_test_cache_gets'] );
		$writes                                      = count( $GLOBALS['digitalogic_test_object_cache_sets'] );
		$GLOBALS['digitalogic_test_current_user_id'] = 7;
		$response                                    = $this->cached( static fn() => array( 'items' => array( array( 'id' => 2 ) ) ) );
		self::assertSame( 200, $response->getStatusCode() );
		self::assertSame( array( array( 'id' => 2 ) ), $this->body( $response )['items'] );
		self::assertTrue( $response->headers->hasCacheControlDirective( 'private' ) );
		self::assertTrue( $response->headers->hasCacheControlDirective( 'no-store' ) );
		self::assertSame( 'MISS', $response->headers->get( 'X-Digitalogic-Cache' ) );
		self::assertSame( $reads, count( $GLOBALS['digitalogic_test_cache_gets'] ) );
		self::assertSame( $writes, count( $GLOBALS['digitalogic_test_object_cache_sets'] ) );
	}
}
