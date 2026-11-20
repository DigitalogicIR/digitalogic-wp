<?php
/**
 * Fixed-slot catalog cache bounds without Redis, network or real credentials.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileCatalogCache as CatalogCache;
use PHPUnit\Framework\TestCase;

/** Exercise the actual adapter against the canonical WordPress cache test double. */
final class MobileCatalogCacheTest extends TestCase {
	/**
	 * Original test-global presence/value pairs.
	 *
	 * @var array
	 */
	private array $saved = array();

	/** Enable only the isolated in-memory WordPress cache. */
	protected function setUp(): void {
		foreach ( array( 'digitalogic_test_object_cache_enabled', 'digitalogic_test_object_cache', 'digitalogic_test_cache_gets', 'digitalogic_test_object_cache_sets', 'digitalogic_test_cache_set_callback', '_COOKIE', '_SERVER' ) as $name ) {
			$this->saved[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		$GLOBALS['digitalogic_test_object_cache_enabled'] = true;
		$GLOBALS['digitalogic_test_object_cache']         = array();
		$GLOBALS['digitalogic_test_cache_gets']           = array();
		$GLOBALS['digitalogic_test_object_cache_sets']    = array();
		unset( $GLOBALS['digitalogic_test_cache_set_callback'] );
	}

	/** Restore all globals, including absent ones, for neighboring tests. */
	protected function tearDown(): void {
		foreach ( $this->saved as $name => $original ) {
			if ( $original[0] ) {
				$GLOBALS[ $name ] = $original[1];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	/** Exact empty and populated public arrays survive without importing request authority. */
	public function test_exact_payload_and_ttl_without_credentials(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-must-not-enter-cache';
		$_COOKIE['fixture_session']    = 'synthetic-cookie-must-not-enter-cache';
		$key                           = 'products:g17:public-query-fixture';
		$data                          = array(
			'items' => array( array( 'id' => 123 ) ),
			'total' => 1,
		);
		self::assertNull( CatalogCache::get( $key ) );
		self::assertTrue( CatalogCache::put( $key, array() ) );
		self::assertSame( array(), CatalogCache::get( $key ) );
		self::assertTrue( CatalogCache::put( $key, $data ) );
		self::assertSame( $data, CatalogCache::get( $key ) );
		$stored = array_values( $GLOBALS['digitalogic_test_object_cache'] );
		self::assertSame(
			array(
				array(
					'key'  => $key,
					'data' => $data,
				),
			),
			$stored
		);
		self::assertStringNotContainsString( 'must-not-enter-cache', wp_json_encode( $stored ) );
		foreach ( $GLOBALS['digitalogic_test_object_cache_sets'] as $write ) {
			self::assertMatchesRegularExpression( '/^slot:[a-f0-9]{2}$/', $write[0] );
			self::assertSame( CatalogCache::GROUP, $write[1] );
			self::assertSame( 60, $write[2] );
		}
	}

	/** Real deterministic hash collisions never return another complete query's data. */
	public function test_collision_is_a_miss_and_replacement_is_isolated(): void {
		$seen = array();
		$pair = null;
		for ( $i = 0; $i <= CatalogCache::SLOT_COUNT; ++$i ) {
			$key  = 'public-fixture-' . $i;
			$slot = substr( hash( 'sha256', $key ), 0, 2 );
			if ( isset( $seen[ $slot ] ) ) {
				$pair = array( $seen[ $slot ], $key );
				break;
			}
			$seen[ $slot ] = $key;
		}
		self::assertNotNull( $pair );
		self::assertTrue( CatalogCache::put( $pair[0], array( 'id' => 1 ) ) );
		self::assertNull( CatalogCache::get( $pair[1] ) );
		self::assertSame( array( 'id' => 1 ), CatalogCache::get( $pair[0] ) );
		self::assertTrue( CatalogCache::put( $pair[1], array( 'id' => 2 ) ) );
		self::assertNull( CatalogCache::get( $pair[0] ) );
		self::assertSame( array( 'id' => 2 ), CatalogCache::get( $pair[1] ) );
		self::assertCount( 1, $GLOBALS['digitalogic_test_object_cache'] );
	}

	/** Arbitrarily many distinct queries consume no more than the fixed 256 slots. */
	public function test_cardinality_is_fixed_without_a_registry(): void {
		for ( $i = 0; $i < 2048; ++$i ) {
			self::assertTrue( CatalogCache::put( 'bounded-public-' . $i, array( 'id' => $i ) ) );
		}
		self::assertCount( 256, $GLOBALS['digitalogic_test_object_cache'] );
		foreach ( array_keys( $GLOBALS['digitalogic_test_object_cache'] ) as $key ) {
			self::assertMatchesRegularExpression( '/^digitalogic-mobile-v2:slot:[a-f0-9]{2}$/', $key );
		}
	}

	/** The exact byte limit is inclusive; oversized payloads never truncate or evict. */
	public function test_payload_boundary_and_oversize_preserve_existing_data(): void {
		$data = array( 'value' => str_repeat( 'x', CatalogCache::MAX_PAYLOAD_BYTES - strlen( wp_json_encode( array( 'value' => '' ) ) ) ) );
		self::assertSame( CatalogCache::MAX_PAYLOAD_BYTES, strlen( wp_json_encode( $data ) ) );
		self::assertTrue( CatalogCache::put( 'boundary', $data ) );
		$too_large           = $data;
		$too_large['value'] .= 'x';
		self::assertFalse( CatalogCache::put( 'boundary', $too_large ) );
		self::assertCount( 1, $GLOBALS['digitalogic_test_object_cache_sets'] );
		self::assertSame( $data, CatalogCache::get( 'boundary' ) );
	}

	/** Non-JSON/recursive data and object private state cannot bypass admission bounds. */
	public function test_non_json_values_are_not_admitted(): void {
		$recursive          = array();
		$recursive['again'] =& $recursive;
		foreach ( array( array( 'value' => new stdClass() ), array( 'value' => INF ), array( 'value' => "\xB1\x31" ), $recursive ) as $data ) {
			self::assertFalse( CatalogCache::put( 'invalid', $data ) );
		}
		self::assertSame( array(), $GLOBALS['digitalogic_test_object_cache'] );
	}

	/** Missing or corrupt envelopes are misses, including non-array stored payloads. */
	public function test_corrupt_envelopes_are_misses(): void {
		$key  = 'corrupt-public';
		$slot = CatalogCache::GROUP . ':slot:' . substr( hash( 'sha256', $key ), 0, 2 );
		foreach ( array(
			false,
			array(),
			array(
				'key'  => 7,
				'data' => array(),
			),
			array(
				'key'  => $key,
				'data' => 'invalid',
			),
		) as $envelope ) {
			$GLOBALS['digitalogic_test_object_cache'][ $slot ] = $envelope;
			self::assertNull( CatalogCache::get( $key ) );
		}
	}

	/** A disabled or failing cache cannot turn a healthy catalog response into an error. */
	public function test_unavailable_cache_fails_as_miss_or_false(): void {
		$GLOBALS['digitalogic_test_object_cache_enabled'] = false;
		self::assertFalse( CatalogCache::put( 'unavailable', array() ) );
		self::assertNull( CatalogCache::get( 'unavailable' ) );
		$GLOBALS['digitalogic_test_object_cache_enabled'] = true;
		$GLOBALS['digitalogic_test_cache_set_callback']   = static function (): void {
			throw new RuntimeException( 'Synthetic cache transport failure.' );
		};
		self::assertFalse( CatalogCache::put( 'unavailable', array() ) );
		self::assertSame( array(), $GLOBALS['digitalogic_test_object_cache'] );
	}
}
