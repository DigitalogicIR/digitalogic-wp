<?php
/**
 * Fulfillment semantics independent of real orders and external notifications.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify review, dispatch, and pickup cannot be confused with payment holds. */
final class OrderFulfillmentTest extends TestCase {

	/** Render Persian unless the test explicitly selects another locale. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_locale'] = 'fa_IR';
	}

	/**
	 * Construct an order double with actual shipping-method identifiers.
	 *
	 * @param string $status Initial status.
	 * @param array  $methods Recorded shipping method identifiers.
	 */
	private function order( string $status, array $methods ): object {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Small fixture accessors implement the order contract.
		return new class( $status, $methods ) {
			public function __construct( public string $status, public array $methods ) {}
			public function get_status( string $context = 'view' ): string {
				return $this->status;
			}
			public function get_order_number(): string {
				return '42';
			}
			public function set_status( string $status ): void {
				$this->status = $status;
			}
			public function get_items( string $type ): array {
				return array_map(
					static fn( string $method ): object => new class( $method ) {
					public function __construct( private string $method ) {}
					public function get_method_id(): string {
							return $this->method;
						}
					},
					$this->methods
				);
			}
		};
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.Missing

	/** Keep the requested sequence while retaining unknown plugin statuses. */
	public function test_primary_order_and_auxiliary_statuses(): void {
		$statuses = Digitalogic_Order_Fulfillment::statuses(
			Digitalogic_Storefront_UX::translate_order_statuses(
				array(
					'wc-pending' => 'Pending',
					'wc-on-hold' => 'On hold',
					'wc-custom'  => 'Custom',
				)
			)
		);
		$this->assertSame( array( 'wc-review', 'wc-processing', 'wc-shipped', 'wc-completed' ), array_slice( array_keys( $statuses ), 0, 4 ) );
		$this->assertSame( array( 'در انتظار بررسی', 'در حال پردازش', 'ارسال شده', 'تکمیل شده' ), array_slice( array_values( $statuses ), 0, 4 ) );
		$this->assertSame( 'در انتظار تأیید پرداخت', $statuses['wc-on-hold'] );
		$this->assertSame( 'در انتظار پرداخت', $statuses['wc-pending'] );
		$this->assertSame( 'Custom', $statuses['wc-custom'] );
	}

	/** Absence of shipping data and mixed packages must not imply pickup. */
	public function test_pickup_requires_all_shipping_lines_to_be_pickup(): void {
		$this->assertTrue( Digitalogic_Order_Fulfillment::is_pickup( $this->order( 'processing', array( 'local_pickup', 'local_pickup' ) ) ) );
		foreach ( array( array(), array( 'flat_rate' ), array( 'local_pickup', 'flat_rate' ), array( 'free_shipping' ) ) as $methods ) {
			$this->assertFalse( Digitalogic_Order_Fulfillment::is_pickup( $this->order( 'processing', $methods ) ) );
		}
	}

	/** A pickup dispatch becomes a single completed transition before saving. */
	public function test_pickup_dispatch_is_merged_with_completion(): void {
		$pickup = $this->order( 'shipped', array( 'local_pickup' ) );
		Digitalogic_Order_Fulfillment::normalize_pickup( $pickup );
		$this->assertSame( 'completed', $pickup->status );
		$this->assertSame( 'تحویل شده', Digitalogic_Order_Fulfillment::label( $pickup ) );
		Digitalogic_Order_Fulfillment::normalize_pickup( $pickup );
		$this->assertSame( 'completed', $pickup->status );
	}

	/** Shipping and unrelated pickup stages keep their stored identities. */
	public function test_normalization_preserves_shipping_and_earlier_stages(): void {
		foreach ( array( 'review', 'processing', 'completed', 'pending', 'cancelled' ) as $status ) {
			$order = $this->order( $status, array( 'local_pickup' ) );
			Digitalogic_Order_Fulfillment::normalize_pickup( $order );
			$this->assertSame( $status, $order->status );
		}
		$order = $this->order( 'shipped', array( 'flat_rate' ) );
		Digitalogic_Order_Fulfillment::normalize_pickup( $order );
		$this->assertSame( 'shipped', $order->status );
	}

	/** Render one order without leaking its wording into the next row. */
	public function test_customer_status_marker_is_order_specific_and_preserves_other_markup(): void {
		$input  = '<mark class="order-number">42</mark> <mark class="order-status">تکمیل شده</mark>';
		$pickup = Digitalogic_Order_Fulfillment::status_sentence( $input, $this->order( 'completed', array( 'local_pickup' ) ) );
		$this->assertStringContainsString( '<mark class="order-status">تحویل شده</mark>', $pickup );
		$this->assertStringContainsString( '<mark class="order-number">42</mark>', $pickup );
		$this->assertStringNotContainsString( 'تحویل شده', Digitalogic_Order_Fulfillment::status_sentence( $input, $this->order( 'completed', array( 'flat_rate' ) ) ) );
		$this->assertSame( 'unchanged', Digitalogic_Order_Fulfillment::status_sentence( 'unchanged', $this->order( 'completed', array( 'local_pickup' ) ) ) );
	}

	/** New physical payments enter review; payment holds and virtual orders do not. */
	public function test_payment_and_paid_status_contract(): void {
		$this->assertSame( 'review', Digitalogic_Order_Fulfillment::payment_status( 'processing' ) );
		$this->assertSame( 'completed', Digitalogic_Order_Fulfillment::payment_status( 'completed' ) );
		$this->assertSame( 'on-hold', Digitalogic_Order_Fulfillment::payment_status( 'on-hold' ) );
		$paid = Digitalogic_Order_Fulfillment::paid_statuses( array( 'processing', 'completed' ) );
		$this->assertContains( 'review', $paid );
		$this->assertContains( 'shipped', $paid );
		$this->assertNotContains( 'on-hold', $paid );
		$this->assertSame( $paid, Digitalogic_Order_Fulfillment::paid_statuses( $paid ) );
	}

	/** Both custom stages are available in bulk controls, except in the trash. */
	public function test_bulk_controls_and_trash(): void {
		$this->assertSame( array( 'untrash' => 'Restore' ), Digitalogic_Order_Fulfillment::bulk_actions( array( 'untrash' => 'Restore' ) ) );
		$actions = Digitalogic_Order_Fulfillment::bulk_actions(
			array(
				'mark_processing' => 'Processing',
				'trash'           => 'Trash',
			)
		);
		$this->assertSame( array( 'mark_review', 'mark_processing', 'mark_shipped', 'mark_completed' ), array_slice( array_keys( $actions ), 0, 4 ) );
		$this->assertSame( 'Trash', $actions['trash'] );
	}

	/** Review still enters WooCommerce's enabled transactional email pipeline. */
	public function test_review_email_events_are_registered_once(): void {
		$events = Digitalogic_Order_Fulfillment::email_actions( array( 'original-event' ) );
		$this->assertContains( 'original-event', $events );
		$this->assertContains( 'woocommerce_order_status_pending_to_review', $events );
		$this->assertContains( 'woocommerce_order_status_on-hold_to_review', $events );
		$this->assertSame( $events, Digitalogic_Order_Fulfillment::email_actions( $events ) );
	}

	/** Non-Persian contexts use complete English labels. */
	public function test_english_locale(): void {
		$GLOBALS['digitalogic_test_locale'] = 'en_US';
		$this->assertSame( 'Awaiting review', Digitalogic_Order_Fulfillment::statuses( array() )['wc-review'] );
		$this->assertSame( 'Delivered', Digitalogic_Order_Fulfillment::label( $this->order( 'completed', array( 'local_pickup' ) ) ) );
	}

	/** Legacy default voice copy and email previews use the pickup wording. */
	public function test_pickup_notification_copy_and_empty_email_preview(): void {
		$order = $this->order( 'completed', array( 'local_pickup' ) );
		$this->assertStringContainsString( 'تحویل شده', Digitalogic_Voice_Notifications::render_message( 'سفارش {order_number} شما تکمیل شد. از خرید شما سپاسگزاریم.', $order, 'completed' ) );
		$this->assertStringContainsString( 'تحویل شده', Digitalogic_Order_Fulfillment::email_subject( 'default', $order ) );
		$this->assertSame( 'default', Digitalogic_Order_Fulfillment::email_subject( 'default', false ) );
		$this->assertSame( 'default', Digitalogic_Order_Fulfillment::email_heading( 'default', false ) );
	}
}
