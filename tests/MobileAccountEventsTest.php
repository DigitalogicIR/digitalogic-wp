<?php
/**
 * Owner-only replay and revocation fixtures without network or real waits.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileAccountEvents as Events;
use PHPUnit\Framework\TestCase;

/** Uses the actual canonical shopper projector and in-memory WordPress options. */
final class MobileAccountEventsTest extends TestCase {
	/**
	 * Save test globals so a focused run cannot contaminate neighboring cases.
	 *
	 * @var array
	 */
	private array $saved = array();

	/** Prepare a synthetic enabled authority and queue. */
	protected function setUp(): void {
		foreach ( array( 'digitalogic_test_options', 'digitalogic_test_option_cache', 'digitalogic_test_cache_deletes' ) as $key ) {
			$this->saved[ $key ] = $GLOBALS[ $key ];
		}
		$GLOBALS['digitalogic_test_options']      = array(
			'digitalogic_mobile_account_activity' => true,
			'digitalogic_mobile_native_auth'      => true,
			'digitalogic_mobile_identity_schema'  => '2',
			'digitalogic_panel_events'            => array( $this->event( 10 ), $this->event( 11 ) ),
			'digitalogic_panel_event_sequence'    => 11,
		);
		$GLOBALS['digitalogic_test_option_cache'] = array();
	}

	/** Restore neighboring test state. */
	protected function tearDown(): void {
		foreach ( $this->saved as $key => $value ) {
			$GLOBALS[ $key ] = $value;
		}
	}

	/**
	 * Make a canonical synthetic event with extra fields that must be stripped.
	 *
	 * @param int    $id Sequence.
	 * @param string $name Internal canonical name.
	 * @param int    $owner Exact synthetic owner.
	 * @return array Internal fixture event.
	 */
	private function event( int $id, string $name = 'mobile.cart.updated', int $owner = 7 ): array {
		return array(
			'id'   => $id,
			'name' => $name,
			'time' => 'fixture',
			'data' => array(
				'user_id'         => $owner,
				'id'              => $owner,
				'revision'        => 2,
				'private_content' => 'must-not-leak',
			),
		);
	}

	/** The rollout flag cannot override disabled native authority. */
	public function test_enabled_requires_both_rollout_and_native_authority(): void {
		self::assertTrue( Events::enabled() );
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_account_activity'] = false;
		self::assertFalse( Events::enabled() );
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_account_activity'] = true;
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_native_auth']      = false;
		self::assertFalse( Events::enabled() );
	}

	/** Private data is reduced and another owner/public catalog never enters the batch. */
	public function test_batch_sorts_filters_and_keeps_global_transport_identity(): void {
		$events = array( $this->event( 14, 'user.updated' ), $this->event( 11 ), $this->event( 13, 'product.updated' ), $this->event( 12, 'mobile.cart.updated', 8 ), $this->event( 10 ) );
		$result = Events::batch( $events, 14, 10, 7 );
		self::assertSame( array( 11, 14 ), array_column( $result['events'], 'id' ) );
		self::assertSame( array( 'cart.updated', 'profile.updated' ), array_column( $result['events'], 'name' ) );
		self::assertSame( 'wp-panel:11', $result['events'][0]['event_id'] );
		self::assertSame(
			array(
				'scope'    => 'cart',
				'revision' => 2,
			),
			$result['events'][0]['data']
		);
		self::assertSame( 14, $result['cursor'] );
		self::assertFalse( $result['reset'] );
		self::assertFalse( $result['has_more'] );
		self::assertStringNotContainsString( 'must-not-leak', wp_json_encode( $result ) );
		self::assertSame( array(), Events::batch( $events, 14, 10, 0 )['events'] );
	}

	/** The queue retention boundary must request fresh state instead of hiding a gap. */
	public function test_initial_future_and_expired_cursors_reset_to_latest(): void {
		foreach ( array( 0, 5, 21 ) as $cursor ) {
			self::assertSame(
				array(
					'events'   => array(),
					'cursor'   => 20,
					'has_more' => false,
					'reset'    => true,
				),
				Events::batch( array( $this->event( 10 ), $this->event( 20 ) ), 20, $cursor, 7 )
			);
		}
		self::assertFalse( Events::batch( array( $this->event( 10 ) ), 10, 9, 7 )['reset'] );
		self::assertTrue( Events::batch( array(), 20, 10, 7 )['reset'] );
		self::assertFalse( Events::batch( array(), 20, 20, 7 )['reset'] );
	}

	/** Filtered rows count toward bounded work and advance the same global cursor. */
	public function test_batch_bounds_retained_work_and_does_not_skip_a_later_append(): void {
		$events = array();
		for ( $id = 1; $id <= 205; ++$id ) {
			$events[] = $this->event( $id, 'mobile.cart.updated', 8 );
		}
		$first = Events::batch( $events, 205, 1, 7 );
		self::assertSame(
			array(
				'events'   => array(),
				'cursor'   => 101,
				'has_more' => true,
				'reset'    => false,
			),
			$first
		);
		$second = Events::batch( $events, 205, 101, 7 );
		self::assertSame( 201, $second['cursor'] );
		self::assertTrue( $second['has_more'] );
		$last = Events::batch( $events, 205, 201, 7 );
		self::assertSame( 205, $last['cursor'] );
		self::assertFalse( $last['has_more'] );
		$bounded = Events::batch( array( $this->event( 10 ), $this->event( 11 ), $this->event( 12 ) ), 11, 10, 7 );
		self::assertSame( array( 11 ), array_column( $bounded['events'], 'id' ) );
		self::assertSame( 11, $bounded['cursor'] );
	}

	/** Even an immediate successful result must recheck the session before release. */
	public function test_poll_wait_zero_checks_authority_before_and_after_reads(): void {
		$calls  = 0;
		$result = Events::poll(
			10,
			0,
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			},
			7
		);
		self::assertIsArray( $result );
		self::assertSame( 2, $calls );
		self::assertSame( array( 11 ), array_column( $result['events'], 'id' ) );
	}

	/** Long HTTP requests must not serve the first iteration's cached option queue. */
	public function test_poll_refreshes_the_current_queue_and_sequence(): void {
		$GLOBALS['digitalogic_test_option_cache']['digitalogic_panel_events']         = array( $this->event( 10 ) );
		$GLOBALS['digitalogic_test_option_cache']['digitalogic_panel_event_sequence'] = 10;
		$result = Events::poll( 10, 0, static fn() => true, 7 );
		self::assertIsArray( $result );
		self::assertSame( array( 11 ), array_column( $result['events'], 'id' ) );
		self::assertSame( 11, $result['cursor'] );
	}

	/** Revocation at entry and between read/release never returns a private batch. */
	public function test_revoked_poll_never_releases_events(): void {
		foreach ( array( 0, 1 ) as $successful_checks ) {
			$calls  = 0;
			$result = Events::poll(
				10,
				0,
				static function () use ( &$calls, $successful_checks ): bool {
					return ++$calls <= $successful_checks;
				},
				7
			);
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'unauthorized', $result->get_error_code() );
			self::assertSame( array( 'status' => 401 ), $result->get_error_data() );
		}
	}

	/** Invalid bounds and unavailable authority fail without any wait or payload. */
	public function test_invalid_bounds_and_authority_exception_fail_closed(): void {
		foreach ( array( array( -1, 0 ), array( 10, -1 ), array( 10, 21 ) ) as $bounds ) {
			$result = Events::poll( $bounds[0], $bounds[1], static fn() => true, 7 );
			self::assertSame( 'invalid_account_events', $result->get_error_code() );
			self::assertSame( 400, $result->get_error_data()['status'] );
		}
		$result = Events::poll(
			10,
			0,
			static function (): bool {
				throw new RuntimeException( 'fixture' );
			},
			7
		);
		self::assertSame( 'account_events_unavailable', $result->get_error_code() );
		self::assertSame( 503, $result->get_error_data()['status'] );
	}
}
