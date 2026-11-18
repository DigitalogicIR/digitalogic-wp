<?php
// phpcs:ignoreFile -- Test doubles intentionally use plugin/vendor names and compact stubs.

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
	function wc_get_page_screen_id( $resource ) {
		return 'shop-order' === $resource ? 'woocommerce_page_wc-orders' : 'woocommerce_page_' . $resource;
	}
}

if ( ! function_exists( 'digits_wc_sms_send_message' ) ) {
	function digits_wc_sms_send_message() {}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return 'print-invoices-packing-slip-labels-for-woocommerce/print-invoices-packing-slip-labels-for-woocommerce.php' === $plugin;
	}
}

if ( ! function_exists( 'get_template' ) ) {
	function get_template() {
		return 'digitalogic-test';
	}
}

if ( ! class_exists( 'Wt_Pklist_Promo_Banners' ) ) {
	class Wt_Pklist_Promo_Banners {
		public static string $active_type = 'loyalty';

		public static function get_active_promo_banner_type(): string {
			return self::$active_type;
		}

		public function render_banners(): void {}

		public function render_shipping_promo_banner_maybe(): void {}

		public function render_unrelated_notice(): void {}
	}
}

final class Digitalogic_Admin_Workspace_Test_Screen {
	public string $id;
	private bool $site_admin;

	public function __construct( string $id, bool $site_admin = true ) {
		$this->id         = $id;
		$this->site_admin = $site_admin;
	}

	public function in_admin( string $context ): bool {
		return $this->site_admin && 'site' === $context;
	}
}

require_once dirname( __DIR__ ) . '/includes/admin/class-digitalogic-admin-workspace.php';

final class AdminWorkspaceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['digitalogic_test_enqueued_scripts']  = array();
		$GLOBALS['digitalogic_test_meta_boxes']        = array();
		$GLOBALS['digitalogic_test_meta_box_calls']    = array();
		$GLOBALS['digitalogic_test_locale']            = 'en_US';
		$GLOBALS['digitalogic_test_current_screen']    = new Digitalogic_Admin_Workspace_Test_Screen( 'dashboard' );
		$GLOBALS['wp_filter']                          = array();
		Wt_Pklist_Promo_Banners::$active_type         = 'loyalty';
	}

	protected function tearDown(): void {
		$GLOBALS['digitalogic_test_current_screen'] = (object) array( 'post_type' => 'product' );
		$GLOBALS['wp_filter']                       = array();
	}

	public function test_tracking_metabox_uses_classic_and_hpos_sidebars_without_rebuilding_controls(): void {
		Digitalogic_Admin_Workspace::order_tracking_box();

		$this->assertSame(
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			array_column( $GLOBALS['digitalogic_test_meta_box_calls'], 'screen' )
		);
		foreach ( $GLOBALS['digitalogic_test_meta_box_calls'] as $box ) {
			$this->assertSame( 'digitalogic-order-tracking', $box['id'] );
			$this->assertSame( 'side', $box['context'] );
			$this->assertSame( 'high', $box['priority'] );
			$this->assertSame( array( Digitalogic_Admin_Workspace::class, 'render_order_tracking_box' ), $box['callback'] );
		}

		ob_start();
		Digitalogic_Admin_Workspace::render_order_tracking_box();
		$this->assertSame( '<div data-dg-order-tracking-slot></div>', ob_get_clean() );
	}

	public function test_tracking_adapter_is_enqueued_only_on_order_editors(): void {
		$GLOBALS['digitalogic_test_current_screen'] = new Digitalogic_Admin_Workspace_Test_Screen( 'woocommerce_page_wc-orders' );
		Digitalogic_Admin_Workspace::order_assets();

		$this->assertArrayHasKey( 'digitalogic-order-workspace', $GLOBALS['digitalogic_test_enqueued_scripts'] );
		$script = $GLOBALS['digitalogic_test_enqueued_scripts']['digitalogic-order-workspace'];
		$this->assertSame( array( 'jquery' ), $script['dependencies'] );
		$this->assertTrue( $script['in_footer'] );
		$this->assertStringEndsWith( '/assets/js/admin-order-workspace.js', $script['src'] );

		$GLOBALS['digitalogic_test_enqueued_scripts'] = array();
		$GLOBALS['digitalogic_test_current_screen']   = new Digitalogic_Admin_Workspace_Test_Screen( 'dashboard' );
		Digitalogic_Admin_Workspace::order_assets();
		$this->assertSame( array(), $GLOBALS['digitalogic_test_enqueued_scripts'] );
	}

	public function test_only_webtoffee_promo_renderers_are_removed_from_admin_notices(): void {
		$promo     = new Wt_Pklist_Promo_Banners();
		$callbacks = array(
			7  => array( array( 'function' => array( $promo, 'render_banners' ) ) ),
			13 => array( array( 'function' => array( $promo, 'render_shipping_promo_banner_maybe' ) ) ),
			20 => array( array( 'function' => array( $promo, 'render_unrelated_notice' ) ) ),
		);
		$GLOBALS['wp_filter']['admin_notices'] = (object) array( 'callbacks' => $callbacks );
		foreach ( $callbacks as $priority => $entries ) {
			foreach ( $entries as $entry ) {
				add_action( 'admin_notices', $entry['function'], $priority );
			}
		}

		Digitalogic_Admin_Workspace::route_vendor_notices();

		$remaining = array_values( $GLOBALS['digitalogic_test_action_callbacks']['admin_notices'] );
		$this->assertCount( 1, $remaining );
		$this->assertSame( array( $promo, 'render_unrelated_notice' ), $remaining[0]['callback'] );
		$this->assertSame( 20, $remaining[0]['priority'] );
	}

	public function test_non_site_admin_context_does_not_remove_vendor_callbacks(): void {
		$promo = new Wt_Pklist_Promo_Banners();
		add_action( 'admin_notices', array( $promo, 'render_banners' ), 7 );
		$GLOBALS['wp_filter']['admin_notices']      = (object) array(
			'callbacks' => array( 7 => array( array( 'function' => array( $promo, 'render_banners' ) ) ) ),
		);
		$GLOBALS['digitalogic_test_current_screen'] = new Digitalogic_Admin_Workspace_Test_Screen( 'dashboard', false );

		Digitalogic_Admin_Workspace::route_vendor_notices();

		$this->assertCount( 1, $GLOBALS['digitalogic_test_action_callbacks']['admin_notices'] );
	}

	public function test_promotions_card_preserves_the_active_loyalty_offer(): void {
		$render = new ReflectionMethod( Digitalogic_Admin_Workspace::class, 'render_promotions' );

		ob_start();
		$render->invoke( null );
		$loyalty = ob_get_clean();
		$this->assertStringContainsString( 'WebToffee', $loyalty );
		$this->assertStringContainsString( 'PSLOYAL50', $loyalty );

		Wt_Pklist_Promo_Banners::$active_type = 'shipping';
		ob_start();
		$render->invoke( null );
		$ordinary = ob_get_clean();
		$this->assertStringContainsString( 'WebToffee', $ordinary );
		$this->assertStringNotContainsString( 'PSLOYAL50', $ordinary );
	}

	public function test_admin_notice_observer_is_not_started_inside_wp_admin(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/assets/js/branding/admin-branding.js' );

		$this->assertStringContainsString(
			"if (!document.body.classList.contains('wp-admin')) { observeInlineMessages(); }",
			$source
		);
	}
}
