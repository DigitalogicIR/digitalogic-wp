<?php
/**
 * Failure-path coverage for transaction-owned public invalidation.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile -- PHPUnit names and its local SQL fixture intentionally share this file.

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-digitalogic-storefront-invalidation-outbox.php';

/** Model SQL's exact-value predicate, absent from the general WPDB fixture. */
class Digitalogic_Invalidation_Test_WPDB extends Digitalogic_Test_WPDB {
	/**
	 * Interleave a newer commit immediately before ACK.
	 *
	 * @var callable|null
	 */
	public $before_ack = null;
	/**
	 * Simulate process loss after downstream queue persistence.
	 *
	 * @var bool
	 */
	public $fail_ack = false;

	/**
	 * Preserve the base fixture and enforce SQL compare-and-swap semantics.
	 *
	 * @param string     $table Target table.
	 * @param array      $data Updated fields.
	 * @param array      $where Exact predicates.
	 * @param array|null $formats Value formats.
	 * @param array|null $where_formats Predicate formats.
	 */
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( $table === $this->options && isset( $where['option_value'] ) ) {
			$callback         = $this->before_ack;
			$this->before_ack = null;
			if ( is_callable( $callback ) ) {
				$callback();
			}
			if ( $this->fail_ack ) {
				return false;
			}
			$current = $GLOBALS['digitalogic_test_options'][ $where['option_name'] ] ?? null;
			if ( maybe_serialize( $current ) !== $where['option_value'] ) {
				return 0;
			}
		}
		return parent::update( $table, $data, $where, $formats, $where_formats );
	}
}


/** Exercise durable recovery using the real Panel publisher with fake Redis. */
final class StorefrontInvalidationOutboxTest extends TestCase {
	/**
	 * Simulated delivery transport.
	 *
	 * @var Digitalogic_Test_Redis_Client
	 */
	private $redis;

	/** Isolate mutable storage and lock owners between failure scenarios. */
	protected function setUp(): void {
		foreach ( array( 'options', 'option_cache', 'filters', 'actions', 'action_callbacks', 'update_failures', 'transaction_failures', 'cache_deletes', 'scheduled_events', 'posts', 'terms', 'term_meta', 'object_terms', 'wc_lookup_rows' ) as $key ) {
			$GLOBALS[ 'digitalogic_test_' . $key ] = array();
		}
		$GLOBALS['digitalogic_test_schedule_failure'] = false;
		$GLOBALS['wpdb']                              = new Digitalogic_Invalidation_Test_WPDB();
		foreach ( array( 'Digitalogic_Product_Sync_Receiver', 'Digitalogic_Pricing_Service' ) as $class ) {
			( new ReflectionClass( $class ) )->setStaticPropertyValue( 'instance', null );
		}
		$this->redis = new Digitalogic_Test_Redis_Client();
		$redis       = $this->redis;
		$GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_client'] = static function () use ( $redis ) {
			return $redis;
		};
	}

	/** Commit a marker through the same SQL transaction fixture as business tests. */
	private function stage_commit(): array {
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::stage() );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		return $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ];
	}

	/** A rolled-back canonical mutation cannot trigger public reconciliation. */
	public function test_rollback_discards_marker_without_public_event(): void {
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::stage() );
		$GLOBALS['wpdb']->query( 'ROLLBACK' );
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( array(), $this->redis->published );
	}

	/** Mandatory staging failure must be returned to the rollback owner. */
	public function test_stage_failure_is_visible_to_transaction_owner(): void {
		$GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Storefront_Invalidation_Outbox::OPTION;
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );
		$this->assertInstanceOf( WP_Error::class, Digitalogic_Storefront_Invalidation_Outbox::stage() );
		$GLOBALS['wpdb']->query( 'ROLLBACK' );
		$this->assertSame( array(), $this->redis->published );
	}

	/** Full invalidations coalesce without retaining any canonical business values. */
	public function test_committed_metadata_coalesces_and_generation_survives_ack(): void {
		$first  = $this->stage_commit();
		$second = $this->stage_commit();
		$this->assertSame( array( 'generation', 'token', 'staged_at', 'pending' ), array_keys( $second ) );
		$this->assertSame( 2, $second['generation'] );
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( 'catalog.updated', $events[0]['name'] );
		$this->assertSame(
			array(
				'idempotency_key' => $second['token'],
				'generation'      => 2,
				'staged_at'       => $second['staged_at'],
			),
			$events[0]['data']
		);
		$this->assertFalse( $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ]['pending'] );
		$this->assertSame( 3, $this->stage_commit()['generation'] );
	}

	/** An unavailable downstream queue cannot consume a pending marker. */
	public function test_queue_failure_retains_pending_marker_and_one_retry(): void {
		$this->stage_commit();
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		$this->assertFalse( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		Digitalogic_Storefront_Invalidation_Outbox::recover();
		$this->assertTrue( $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ]['pending'] );
		$this->assertNotFalse( wp_next_scheduled( Digitalogic_Storefront_Invalidation_Outbox::RETRY_HOOK ) );
		$this->assertSame( array(), $this->redis->published );
	}

	/** A stored event without wake responsibility must retry even as a duplicate. */
	public function test_wake_failure_without_durable_obligation_retains_marker_then_duplicate_recovers(): void {
		$this->stage_commit();
		$this->redis->publish_result                   = false;
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_event_wake_outbox_v1';
		$this->assertFalse( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$this->assertTrue( $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ]['pending'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->redis->publish_result                 = 0;
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertFalse( $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ]['pending'] );
	}

	/** Existing durable wake recovery may accept delivery responsibility. */
	public function test_durable_wake_accepts_responsibility_when_redis_is_down(): void {
		$this->stage_commit();
		$this->redis->publish_result = false;
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$this->assertArrayHasKey( 'digitalogic_panel_event_wake_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertNotFalse( wp_next_scheduled( 'digitalogic_panel_event_wake_retry_v1' ) );
	}

	/** Replaying an unacknowledged delivery wakes clients without a duplicate row. */
	public function test_crash_after_queue_before_ack_replays_same_event_and_wakes_again(): void {
		$this->stage_commit();
		$GLOBALS['wpdb']->fail_ack = true;
		$this->assertFalse( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$GLOBALS['wpdb']->fail_ack = false;
		$before                    = count( $this->redis->published );
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$this->assertGreaterThan( $before, count( $this->redis->published ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
	}

	/** An ACK racing a newer canonical commit cannot clear that newer marker. */
	public function test_compare_and_swap_never_acknowledges_newer_generation(): void {
		$this->stage_commit();
		$GLOBALS['wpdb']->before_ack = function () {
			$this->stage_commit();
			// Prevent the second bounded attempt from accepting generation two.
			$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		};
		$this->assertFalse( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$current = $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ];
		$this->assertSame( 2, $current['generation'] );
		$this->assertTrue( $current['pending'] );
		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->assertTrue( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
	}
}
