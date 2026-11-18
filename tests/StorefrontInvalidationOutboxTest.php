<?php
/**
 * Failure-path coverage for transaction-owned public invalidation.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile -- PHPUnit names and its local SQL fixture intentionally share this file.

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-digitalogic-storefront-invalidation-outbox.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-storefront-page-cache.php';

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
		foreach ( array( 'Digitalogic_Product_Sync_Receiver', 'Digitalogic_Pricing_Service', 'Digitalogic_Report_Engine' ) as $class ) {
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
		$this->assertSame( array( 'generation', 'token', 'staged_at', 'pending', 'cache_product_ids' ), array_keys( $second ) );
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

	/** A newer transaction cannot discard an older unpurged product's HTML. */
	public function test_pending_cache_coverage_survives_coalescing(): void {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$property = new ReflectionProperty( $receiver, 'materializer_committed_snapshots' );
		$property->setValue( $receiver, array( array( 'product_id' => 123 ) ) );
		$this->assertSame( array( 123 ), $this->stage_commit()['cache_product_ids'] );
		$property->setValue( $receiver, array( array( 'product_id' => 456 ) ) );
		$this->assertSame( array( 123, 456 ), $this->stage_commit()['cache_product_ids'] );
		$property->setValue( $receiver, array() );
	}

	/** A missing cache provider after process loss must retain recovery work. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_cache_failure_after_restart_retains_marker_without_publication(): void {
		define( 'WP_CACHE', true );
		$this->queue_verified_fixture();
		$this->stage_commit();
		( new ReflectionClass( Digitalogic_Product_Sync_Receiver::class ) )->setStaticPropertyValue( 'instance', null );
		$this->assertFalse( Digitalogic_Storefront_Invalidation_Outbox::dispatch() );
		$record = $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ];
		$this->assertTrue( $record['pending'] );
		$this->assertSame( array( 123 ), $record['cache_product_ids'] );
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( array(), $this->redis->published );
		$this->assertNotFalse( wp_next_scheduled( Digitalogic_Storefront_Invalidation_Outbox::RETRY_HOOK, array() ) );
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

	/** Simulate verified request metadata without saving any product fixture. */
	private function queue_verified_fixture(): array {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$property = new ReflectionProperty( $receiver, 'materializer_committed_snapshots' );
		$property->setValue( $receiver, array( 'verified-test' => array( 'product_id' => 123 ) ) );
		return array( 'status' => 'applied' );
	}

	/** The actual source owner stages before COMMIT and publishes after both locks. */
	public function test_source_owner_commits_and_dispatches_only_after_unlock(): void {
		$redis = $this->redis;
		$GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_client'] = function () use ( $redis ) {
			$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
			$this->assertFalse( Digitalogic_Pricing_Service::instance()->source_delivery_lock_is_owned() );
			$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
			return $redis;
		};
		$result = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
			function () {
				return $this->queue_verified_fixture();
			}
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertFalse( $GLOBALS['digitalogic_test_options'][ Digitalogic_Storefront_Invalidation_Outbox::OPTION ]['pending'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
	}

	/** The owner's final deadline guard rolls back its already-staged marker. */
	public function test_source_owner_final_guard_rolls_back_staged_invalidation(): void {
		$service = Digitalogic_Pricing_Service::instance();
		$clock   = new ReflectionProperty( $service, 'source_delivery_clock' );
		$times   = array( 0, 0, PHP_INT_MAX );
		$clock->setValue(
			$service,
			static function () use ( &$times ) {
				return array_shift( $times ) ?? PHP_INT_MAX;
			}
		);
		$result = $service->run_source_delivery_transaction(
			function () {
				return $this->queue_verified_fixture();
			}
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_source_delivery_deadline_exceeded', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertArrayNotHasKey( Digitalogic_Storefront_Invalidation_Outbox::OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
	}

	/** A mandatory outbox write failure aborts the actual source transaction. */
	public function test_source_owner_rolls_back_when_staging_fails(): void {
		$GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Storefront_Invalidation_Outbox::OPTION;
		$result                                        = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
			function () {
				return $this->queue_verified_fixture();
			}
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_storefront_invalidation_stage_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
	}
}
