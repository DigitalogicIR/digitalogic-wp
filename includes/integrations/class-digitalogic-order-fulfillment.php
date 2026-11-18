<?php
/**
 * Order review, dispatch, and pickup fulfillment.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Keep fulfillment stages distinct from payment holds. */
final class Digitalogic_Order_Fulfillment {

	/** Register WooCommerce lifecycle and presentation hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( self::class, 'statuses' ), 60 );
		add_filter( 'woocommerce_order_is_paid_statuses', array( self::class, 'paid_statuses' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( self::class, 'payment_status' ), 20 );
		add_filter( 'woocommerce_cod_process_payment_order_status', array( self::class, 'payment_status' ), 20 );
		add_filter( 'woocommerce_email_actions', array( self::class, 'email_actions' ) );
		add_action( 'woocommerce_email', array( self::class, 'review_emails' ) );
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'normalize_pickup' ) );
		foreach ( array( 'review', 'shipped' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, 'wc_maybe_reduce_stock_levels' );
			add_action( 'woocommerce_order_status_' . $status, 'wc_release_stock_for_order', 11 );
			add_action( 'woocommerce_order_status_' . $status, 'wc_update_total_sales_counts' );
			add_action( 'woocommerce_order_status_' . $status, 'wc_update_coupon_usage_counts' );
		}
		add_action( 'woocommerce_my_account_my_orders_column_order-status', array( self::class, 'render_status' ) );
		add_filter( 'woocommerce_order_details_status', array( self::class, 'status_sentence' ), 20, 2 );
		add_filter( 'woocommerce_order_tracking_status', array( self::class, 'status_sentence' ), 20, 2 );
		foreach ( array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_filter( 'manage_' . $screen . '_columns', array( self::class, 'admin_columns' ), 30 );
			add_filter( 'bulk_actions-' . $screen, array( self::class, 'bulk_actions' ), 30 );
		}
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'admin_column' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'admin_column' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'admin_order_labels' ) );
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( self::class, 'preview' ), 20, 2 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( self::class, 'rest_order' ), 20, 2 );
		add_filter( 'woocommerce_email_subject_customer_completed_order', array( self::class, 'email_subject' ), 20, 2 );
		add_filter( 'woocommerce_email_heading_customer_completed_order', array( self::class, 'email_heading' ), 20, 2 );
		if ( did_action( 'woocommerce_email' ) ) {
			self::review_emails( WC()->mailer() );
		}
	}

	/** Register searchable statuses for both legacy order storage and HPOS. */
	public static function register_statuses(): void {
		foreach ( array(
			'review'  => 'در انتظار بررسی',
			'shipped' => 'ارسال شده',
		) as $slug => $label ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => self::persian() ? $label : ( 'review' === $slug ? 'Awaiting review' : 'Shipped' ),
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => 'review' === $slug
						// translators: %s: Number of orders awaiting review.
						? _n_noop( 'در انتظار بررسی <span class="count">(%s)</span>', 'در انتظار بررسی <span class="count">(%s)</span>', 'digitalogic' )
						// translators: %s: Number of dispatched orders.
						: _n_noop( 'ارسال شده <span class="count">(%s)</span>', 'ارسال شده <span class="count">(%s)</span>', 'digitalogic' ),
				)
			);
		}
	}

	/**
	 * Put the four fulfillment stages first, preserving every plugin status.
	 *
	 * @param array $statuses Registered order status labels.
	 */
	public static function statuses( array $statuses ): array {
		$primary = array(
			'wc-review'     => self::persian() ? 'در انتظار بررسی' : 'Awaiting review',
			'wc-processing' => self::persian() ? 'در حال پردازش' : 'Processing',
			'wc-shipped'    => self::persian() ? 'ارسال شده' : 'Shipped',
			'wc-completed'  => self::persian() ? 'تکمیل شده' : 'Completed',
		);
		return $primary + $statuses;
	}

	/**
	 * Preserve paid-order behavior without marking unpaid payment holds paid.
	 *
	 * @param array $statuses Paid status identifiers.
	 */
	public static function paid_statuses( array $statuses ): array {
		return array_values( array_unique( array_merge( $statuses, array( 'review', 'shipped' ) ) ) );
	}

	/**
	 * Physical orders enter review after payment; virtual completion stays intact.
	 *
	 * @param string $status Gateway-selected order status.
	 */
	public static function payment_status( string $status ): string {
		return 'processing' === $status ? 'review' : $status;
	}

	/**
	 * Include review transitions in WooCommerce's deferred email dispatcher.
	 *
	 * @param array $actions Transactional email action names.
	 */
	public static function email_actions( array $actions ): array {
		foreach ( array( 'pending', 'on-hold', 'failed', 'cancelled' ) as $from ) {
			$actions[] = 'woocommerce_order_status_' . $from . '_to_review';
		}
		return array_values( array_unique( $actions ) );
	}

	/**
	 * Preserve enabled new-order and order-received emails when entering review.
	 *
	 * @param WC_Emails $mailer Initialized WooCommerce mailer.
	 */
	public static function review_emails( $mailer ): void {
		$emails = $mailer->get_emails();
		foreach ( array( 'pending', 'on-hold', 'failed', 'cancelled' ) as $from ) {
			foreach ( array( 'WC_Email_New_Order', 'WC_Email_Customer_Processing_Order' ) as $key ) {
				if ( isset( $emails[ $key ] ) ) {
					add_action( 'woocommerce_order_status_' . $from . '_to_review_notification', array( $emails[ $key ], 'trigger' ), 10, 2 );
				}
			}
		}
	}

	/**
	 * Pickup requires every recorded shipping line to use local pickup.
	 *
	 * @param object $order WooCommerce order.
	 */
	public static function is_pickup( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return false;
		}
		$items = $order->get_items( 'shipping' );
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) || 'local_pickup' !== $item->get_method_id() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Merge dispatch and completion before persistence and notifications for pickup.
	 *
	 * @param object $order Order being persisted.
	 */
	public static function normalize_pickup( $order ): void {
		if ( 'shipped' === $order->get_status( 'edit' ) && self::is_pickup( $order ) ) {
			$order->set_status( 'completed' );
		}
	}

	/**
	 * Resolve a label from the actual order, never from a previous loop's order.
	 *
	 * @param object $order WooCommerce order.
	 * @param string $status Optional explicit status identifier.
	 */
	public static function label( $order, string $status = '' ): string {
		$status = '' !== $status ? $status : ( method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '' );
		if ( in_array( $status, array( 'shipped', 'completed' ), true ) && self::is_pickup( $order ) ) {
			return self::persian() ? 'تحویل شده' : 'Delivered';
		}
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status;
	}

	/**
	 * Render account-list status.
	 *
	 * @param object $order WooCommerce order.
	 */
	public static function render_status( $order ): void {
		echo esc_html( self::label( $order ) );
	}

	/**
	 * Replace only the status marker in WooCommerce's translated status sentence.
	 *
	 * @param string $text Translated sentence with an order-status marker.
	 * @param object $order WooCommerce order.
	 */
	public static function status_sentence( string $text, $order ): string {
		return (string) preg_replace_callback(
			'/(<mark\b[^>]*class=["\x27]order-status["\x27][^>]*>).*?(<\/mark>)/us',
			static fn( array $marker ): string => $marker[1] . esc_html( self::label( $order ) ) . $marker[2],
			$text
		);
	}

	/**
	 * Give the core order-status column an order-aware renderer.
	 *
	 * @param array $columns Registered admin columns.
	 */
	public static function admin_columns( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ 'order_status' === $key ? 'digitalogic_order_status' : $key ] = $label;
		}
		return $result;
	}

	/**
	 * Render both legacy and HPOS order rows.
	 *
	 * @param string     $column Current column identifier.
	 * @param object|int $order Order object or legacy order ID.
	 */
	public static function admin_column( string $column, $order ): void {
		if ( 'digitalogic_order_status' !== $column ) {
			return;
		}
		$order = is_object( $order ) ? $order : wc_get_order( $order );
		if ( $order ) {
			echo '<mark class="order-status status-' . esc_attr( $order->get_status() ) . '"><span>' . esc_html( self::label( $order ) ) . '</span></mark>';
		}
	}

	/**
	 * Core bulk handlers support registered mark_STATUS actions.
	 *
	 * @param array $actions Available admin bulk actions.
	 */
	public static function bulk_actions( array $actions ): array {
		if ( ! isset( $actions['mark_processing'] ) ) {
			return $actions;
		}
		$primary = array();
		foreach ( self::statuses( array() ) as $key => $label ) {
			$primary[ 'mark_' . substr( $key, 3 ) ] = self::persian() ? 'تغییر وضعیت به «' . $label . '»' : 'Change status to ' . $label;
		}
		if ( self::persian() ) {
			$actions['mark_on-hold']   = 'تغییر وضعیت به «در انتظار تأیید پرداخت»';
			$actions['mark_cancelled'] = 'تغییر وضعیت به «لغو شده»';
		}
		return $primary + $actions;
	}

	/**
	 * Tailor only the editor's options; keep the registered status API complete.
	 *
	 * @param object $order Order currently being edited.
	 */
	public static function admin_order_labels( $order ): void {
		if ( ! self::is_pickup( $order ) ) {
			return;
		}
		$label = wp_json_encode( self::persian() ? 'تحویل شده' : 'Delivered' );
		wc_enqueue_js( "jQuery('#order_status option[value=\"wc-shipped\"]').remove();jQuery('#order_status option[value=\"wc-completed\"]').text($label);jQuery('#order_status').trigger('change.select2');" );
	}

	/**
	 * Keep the admin preview consistent with the list and editor.
	 *
	 * @param array  $data Admin order preview data.
	 * @param object $order WooCommerce order.
	 */
	public static function preview( array $data, $order ): array {
		$data['status_name'] = self::label( $order );
		return $data;
	}

	/**
	 * Supply display text alongside the stable REST status identifier.
	 *
	 * @param WP_REST_Response $response Prepared REST response.
	 * @param object           $order WooCommerce order.
	 */
	public static function rest_order( $response, $order ) {
		$data                 = $response->get_data();
		$data['status_label'] = self::label( $order );
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Use the same final-state wording in the customer email subject.
	 *
	 * @param string $subject Configured email subject.
	 * @param object $order WooCommerce order.
	 */
	public static function email_subject( string $subject, $order ): string {
		return self::persian() && is_object( $order ) ? 'سفارش شمارهٔ ' . $order->get_order_number() . ' — ' . self::label( $order, 'completed' ) : $subject;
	}

	/**
	 * Use the same final-state wording in the customer email heading.
	 *
	 * @param string $heading Configured email heading.
	 * @param object $order WooCommerce order.
	 */
	public static function email_heading( string $heading, $order ): string {
		return self::persian() && is_object( $order ) ? 'وضعیت سفارش: ' . self::label( $order, 'completed' ) : $heading;
	}

	/** Respect the language currently being rendered. */
	private static function persian(): bool {
		return str_starts_with( strtolower( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() ), 'fa' );
	}
}
