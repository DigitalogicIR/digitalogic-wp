<?php
/**
 * Closed website-cookie contracts without real accounts or browser writes.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileWebsiteActivity as Activity;
use PHPUnit\Framework\TestCase;

/** Tests the exact parser and credential boundary shared by the website handlers. */
final class MobileWebsiteActivityTest extends TestCase {
	/** A native bearer, missing cookie, invalid nonce or another cookie owner cannot authorize. */
	public function test_only_matching_wordpress_cookie_and_nonce_are_allowed(): void {
		$valid = static fn( $cookie ) => 'fixture-cookie' === $cookie ? 7 : 0;
		$nonce = static fn( $value ) => 'fixture-nonce' === $value;
		self::assertTrue( Activity::credentialsAllowed( 7, 'fixture-cookie', 'fixture-nonce', false, $valid, $nonce ) );
		foreach ( array( array( 0, 'fixture-cookie', 'fixture-nonce', false ), array( 8, 'fixture-cookie', 'fixture-nonce', false ), array( 7, '', 'fixture-nonce', false ), array( 7, 'tampered', 'fixture-nonce', false ), array( 7, 'fixture-cookie', '', false ), array( 7, 'fixture-cookie', 'stale', false ), array( 7, 'fixture-cookie', 'fixture-nonce', true ) ) as $input ) {
			self::assertFalse( Activity::credentialsAllowed( ...array_merge( $input, array( $valid, $nonce ) ) ) );
		}
	}

	/** Each revalidation observes revocation; a successful earlier check is not a grant. */
	public function test_cookie_revocation_is_observed_on_every_check(): void {
		$active = true;
		$valid  = static function () use ( &$active ): int {
			return $active ? 7 : 0;
		};
		self::assertTrue( Activity::credentialsAllowed( 7, 'fixture-cookie', 'fixture-nonce', false, $valid, static fn() => true ) );
		$active = false;
		self::assertFalse( Activity::credentialsAllowed( 7, 'fixture-cookie', 'fixture-nonce', false, $valid, static fn() => true ) );
	}

	/** Website observations have no timestamps, owner fields or bulk-import surface. */
	public function test_visible_view_accepts_only_one_exact_integer_product_id(): void {
		self::assertSame( 123, Activity::viewId( array( 'product_id' => 123 ) ) );
		foreach ( array(
			null,
			array(),
			array( 'product_id' => '123' ),
			array( 'product_id' => 0 ),
			array( 'product_id' => -1 ),
			array( 'product_id' => 1.1 ),
			array( 'product_id' => true ),
			array(
				'product_id' => 123,
				'user_id'    => 7,
			),
			array(
				'product_id' => 123,
				'viewed_at'  => 'fixture',
			),
			array( 'views' => array( 123 ) ),
		) as $input ) {
			self::assertNull( Activity::viewId( $input ) );
		}
	}

	/** Cookie presentation follows each installed provider, without a new identity field. */
	public function test_cookie_values_have_exact_ordering_and_provider_limits(): void {
		$items  = array_map( static fn( $id ) => array( 'product_id' => $id ), range( 30, 1 ) );
		$values = Activity::cookieValues( $items );
		self::assertSame( array( 'woodmart_recently_viewed_products', 'woocommerce_recently_viewed' ), array_keys( $values ) );
		self::assertSame( implode( '|', range( 30, 19 ) ), $values['woodmart_recently_viewed_products'] );
		self::assertSame( implode( '|', range( 16, 30 ) ), $values['woocommerce_recently_viewed'] );
		self::assertSame(
			array(
				'woodmart_recently_viewed_products' => '',
				'woocommerce_recently_viewed'       => '',
			),
			Activity::cookieValues( array() )
		);
	}

	/** Only canonical parent links affect website presentation; missing/private parents disappear. */
	public function test_variation_parent_presentation_preserves_cloud_records(): void {
		$make     = static function ( $parent_id ) {
			return new class($parent_id) {
				/**
				 * Canonical parent fixture.
				 *
				 * @param int $parent_id Exact canonical parent.
				 */
				public function __construct( private int $parent_id ) {}
				/**
				 * Whether this exact product is a variation.
				 *
				 * @param string $type Requested product type.
				 * @return bool
				 */
				public function is_type( string $type ): bool {
					return 'variation' === $type && $this->parent_id > 0;
				}
				/** Exact canonical parent. */
				public function get_parent_id(): int {
					return $this->parent_id;
				}
			};
		};
		$products = array(
			11 => $make( 10 ),
			12 => $make( 10 ),
			10 => $make( 0 ),
			20 => $make( 0 ),
			31 => $make( 30 ),
			30 => $make( 0 ),
			41 => $make( 40 ),
		);
		$items    = array_map(
			static fn( $id ) => array(
				'product_id' => $id,
				'viewed_at'  => 'fixture',
			),
			array( 11, 20, 12, 10, 31, 41, 99 )
		);
		$original = $items;
		$result   = Activity::websiteProductIds( $items, static fn( $id ) => $products[ $id ] ?? null, static fn( $id ) => isset( $products[ $id ] ) && 30 !== $id );
		self::assertSame( array( 10, 20 ), $result );
		self::assertSame( $original, $items );
	}

	/** Private responses are never reusable by an intermediary or another viewer. */
	public function test_private_cache_headers_are_limited_to_exact_routes(): void {
		foreach ( array( '/digitalogic/v1/account/recently-viewed', '/digitalogic/v1/account/events' ) as $route ) {
			$request  = ( new WP_REST_Request() )->set_route( $route );
			$response = Activity::privateResponse( new WP_REST_Response( array( 'code' => 'unauthorized' ), 401 ), null, $request );
			self::assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		}
		$request  = ( new WP_REST_Request() )->set_route( '/digitalogic/v1/events/stream' );
		$response = Activity::privateResponse( new WP_REST_Response( array() ), null, $request );
		self::assertArrayNotHasKey( 'Cache-Control', $response->get_headers() );
	}

	/** Moving between provider groups invalidates even when the union of product IDs is unchanged. */
	public function test_group_move_changes_revision_without_exposing_group_payload(): void {
		$prior = $GLOBALS['wpdb'];
		$db    = $this->getMockBuilder( Digitalogic_Test_WPDB::class )->onlyMethods( array( 'get_results' ) )->getMock();
		$db->expects( self::exactly( 3 ) )->method( 'get_results' )->willReturnOnConsecutiveCalls(
			array(
				array(
					'wishlist_id' => '1',
					'product_id'  => '123',
				),
			),
			array(
				array(
					'wishlist_id' => 2,
					'product_id'  => 123,
				),
			),
			array(
				array(
					'wishlist_id' => '2',
					'product_id'  => '123',
				),
			)
		);
		$GLOBALS['wpdb'] = $db;
		try {
			$method = new ReflectionMethod( Activity::class, 'wishlistRevision' );
			$first  = $method->invoke( null, 7 );
			$second = $method->invoke( null, 7 );
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
			self::assertNotSame( $first, $second );
			self::assertSame( $second, $method->invoke( null, 7 ) );
		} finally {
			$GLOBALS['wpdb'] = $prior;
		}
	}
}
