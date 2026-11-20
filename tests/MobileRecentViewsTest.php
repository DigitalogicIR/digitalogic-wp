<?php
/**
 * Bounded history reconciliation and public transport contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileRecentViews as History;
use Digitalogic\Laravel\Services\MobileAccountEvents as Events;
use PHPUnit\Framework\TestCase;

/** Pure synthetic history, no customers or provider transports. */
final class MobileRecentViewsTest extends TestCase {

	/** Strict shapes prevent clients choosing an owner or supplying serialized metadata. */
	public function test_malformed_history_fails_closed(): void {
		$now   = 1800000000;
		$valid = array(
			'product_id' => 4,
			'viewed_at'  => gmdate( 'c', $now ),
		);
		foreach ( array(
			null,
			'invalid',
			array( 'views' => array() ),
			array_fill( 0, 31, $valid ),
			array( $valid + array( 'user_id' => 2 ) ),
			array(
				array(
					'product_id' => '4',
					'viewed_at'  => gmdate( 'c', $now ),
				),
			),
			array(
				array(
					'product_id' => 0,
					'viewed_at'  => gmdate( 'c', $now ),
				),
			),
			array(
				array(
					'product_id' => 4,
					'viewed_at'  => '2026-02-30T00:00:00Z',
				),
			),
			array(
				array(
					'product_id' => 4,
					'viewed_at'  => '2026-02-28T00:00:00+03:30',
				),
			),
		) as $input ) {
			self::assertNull( History::normalize( $input, $now ) );
		}
	}

	/** Expired/far-future entries are acknowledged without losing other valid entries. */
	public function test_clock_skew_expiry_and_duplicate_batch_are_bounded(): void {
		$now   = 1800000000;
		$input = array();
		foreach ( array( array( 4, $now - 20 ), array( 4, $now - 10 ), array( 5, $now + 120 ), array( 6, $now + 301 ), array( 7, $now - History::RETENTION - 1 ) ) as $row ) {
			$input[] = array(
				'product_id' => $row[0],
				'viewed_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $row[1] ),
			);
		}
		self::assertSame(
			array(
				4 => $now - 10,
				5 => $now,
			),
			History::normalize( $input, $now )
		);
		self::assertSame(
			array( 4 => $now ),
			History::normalize(
				array(
					array(
						'product_id' => 4,
						'viewed_at'  => gmdate( 'Y-m-d\TH:i:s', $now ) . '.123456Z',
					),
				),
				$now
			)
		);
	}

	/** Replays and old offline intent cannot reorder a newer canonical view backwards. */
	public function test_incremental_merge_is_idempotent_commutative_and_max_per_product(): void {
		$now    = 1800000000;
		$left   = array(
			8 => $now - 3,
			4 => $now - 8,
		);
		$right  = array(
			4 => $now - 1,
			8 => $now - 20,
			9 => $now - 1,
		);
		$result = History::combine( $left, $right, $now );
		self::assertSame(
			array(
				4 => $now - 1,
				9 => $now - 1,
				8 => $now - 3,
			),
			$result
		);
		self::assertSame( $result, History::combine( $right, $left, $now ) );
		self::assertSame( $result, History::combine( $result, $right, $now ) );
	}

	/** The same operation powers reviewed account consolidation without source deletion. */
	public function test_account_union_is_bounded_and_does_not_mutate_source(): void {
		$now    = 1800000000;
		$source = array();
		$target = array();
		foreach ( range( 1, 40 ) as $id ) {
			$source[ $id ]      = $now - $id;
			$target[ $id + 20 ] = $now - $id - 40;
		}
		$before = $source;
		$merged = History::combine( $target, $source, $now );
		self::assertCount( 30, $merged );
		self::assertSame( range( 1, 30 ), array_keys( $merged ) );
		self::assertSame( $before, $source );
		self::assertSame(
			array(),
			History::combine(
				array(
					1 => $now - History::RETENTION - 1,
					2 => 'invalid',
				),
				array(),
				$now
			)
		);
	}

	/** Public guests and other accounts never receive wishlist or view events. */
	public function test_history_and_favorites_events_are_exact_owner_metadata_only(): void {
		foreach ( array( 'history', 'favorites' ) as $kind ) {
			$event = array(
				'id'   => 12,
				'name' => 'mobile.' . $kind . '.updated',
				'time' => 'fixture',
				'data' => array(
					'user_id'    => 7,
					'product_id' => 4,
					'items'      => array( 4 ),
					'address'    => 'private',
				),
			);
			self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 0 ) );
			self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 8 ) );
			$own = Digitalogic_WebSocket_Server::project_shopper_event( $event, 7 );
			self::assertSame( $kind . '.updated', $own['name'] );
			self::assertSame( array( 'scope' => 'history' === $kind ? 'recently_viewed' : 'favorites' ), $own['data'] );
			self::assertSame( 'wp-panel:12', $own['event_id'] );
			self::assertCount( 1, Events::batch( array( $event ), 12, 11, 7 )['events'] );
		}
	}

	/** Account paths remain unversioned, closed and separately method restricted. */
	public function test_history_and_events_gateway_routes_are_bounded(): void {
		self::assertSame( '/_digitalogic/mobile/account/recently-viewed', Digitalogic_Mobile_API::route( '/app-api/account/recently-viewed' ) );
		self::assertSame( '/_digitalogic/mobile/account/events', Digitalogic_Mobile_API::route( '/app-api/account/events' ) );
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/account/recently-viewed/7' ) );
		self::assertSame( array( 'GET', 'HEAD', 'POST' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/account/recently-viewed' ) );
		self::assertSame( array( 'GET' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/account/events' ) );
	}
}
