<?php
/**
 * The site dashboard, maintenance screen, and optional plugin announcements.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Manage the WordPress dashboard and its supporting screens. */
final class Digitalogic_Admin_Workspace {
	const MAINTENANCE   = 'digitalogic-site-maintenance';
	const ANNOUNCEMENTS = 'digitalogic-plugin-updates';

	/** Register the admin-only hooks. */
	public static function register(): void {
		if ( ! defined( 'WP_REDIS_DISABLE_BANNERS' ) ) {
			define( 'WP_REDIS_DISABLE_BANNERS', true );
		}

		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'wp_dashboard_setup', array( self::class, 'dashboard' ), 100 );
		add_filter( 'get_user_option_meta-box-order_dashboard', array( self::class, 'dashboard_order' ) );
		add_filter( 'get_user_option_screen_layout_dashboard', array( self::class, 'dashboard_columns' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'style' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'order_assets' ) );
		add_action( 'add_meta_boxes', array( self::class, 'order_tracking_box' ), 100 );

		add_action( 'wp_before_admin_bar_render', array( self::class, 'trim_toolbar' ) );
		add_action( 'admin_head', array( self::class, 'route_vendor_notices' ) );
		add_action( 'tgmpa_register', array( self::class, 'route_theme_recommendation' ), 100 );
		add_filter( 'plugin_row_meta', array( self::class, 'remove_plugin_promotions' ), 100, 2 );
		add_filter( 'wp_parsidate_wp_admin_notice', array( self::class, 'parsidate_notices' ), 100 );
		add_filter( 'permalink_manager_default_alerts', array( self::class, 'permalink_offers' ) );
		add_filter( 'permalink_manager_alerts', array( self::class, 'permalink_setup' ), 100 );
		add_filter( 'rocket_hide_rocketcdn_notices', '__return_true' );
	}

	/** Add two destinations to the Dashboard menu. */
	public static function menu(): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
		add_dashboard_page( self::label( 'Site Maintenance', 'نگهداری سایت' ), self::label( 'Site Maintenance', 'نگهداری سایت' ), 'manage_woocommerce', self::MAINTENANCE, array( self::class, 'render_maintenance' ) );
		add_dashboard_page( self::label( 'Plugin Announcements', 'اطلاعیه افزونه‌ها' ), self::label( 'Plugin Announcements', 'اطلاعیه افزونه‌ها' ), 'manage_options', self::ANNOUNCEMENTS, array( self::class, 'render_announcements' ) );
	}

	/** Return the maintenance screen URL for admin links. */
	public static function maintenance_url(): string {
		return admin_url( 'index.php?page=' . self::MAINTENANCE );
	}

	/**
	 * Render the short interface copy for the active admin locale.
	 *
	 * @param string $english English wording.
	 * @param string $persian Persian wording.
	 */
	public static function label( string $english, string $persian ): string {
		return 'fa_IR' === determine_locale() ? $persian : $english;
	}

	/** Remove redundant widgets and register the workspace links. */
	public static function dashboard(): void {
		add_screen_option(
			'layout_columns',
			array(
				'max'     => 2,
				'default' => 2,
			)
		);
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		// These plugins and tools already have their own screens in the admin menu.
		$widgets = array(
			'dashboard_primary',
			'wpp_dashboard_primary',
			'dashboard_quick_press',
			'dashboard_right_now',
			'woocommerce_dashboard_recent_reviews',
			'dashboard_rediscache',
			'rank_math_dashboard_widget',
			'wp_mail_smtp_reports_widget_lite',
			'e-dashboard-overview',
			'ct_dashboard_statistics_widget',
			'wordfence_security_news',
			'cerber_quick',
			'zerospam_unified_widget',
			'wp_fail2ban_last_messages',
			'wpa_dashboard_widget',
			'wordfence_activity_report_widget',
			'fluent_support_reports_widget',
			'fluentform_stat_widget',
		);
		foreach ( $widgets as $id ) {
			foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $column ) {
				remove_meta_box( $id, 'dashboard', $column );
			}
		}

		wp_add_dashboard_widget( 'digitalogic-admin-workspace', self::label( 'Quick links', 'دسترسی سریع' ), array( self::class, 'render_links' ) );
	}

	/**
	 * Start pre-existing accounts in the new layout and respect later drag-and-drop changes.
	 *
	 * @param mixed $saved Saved per-user dashboard order.
	 */
	public static function dashboard_order( $saved ): array {
		$normal = is_array( $saved ) ? ( $saved['normal'] ?? '' ) : '';
		$side   = is_array( $saved ) ? ( $saved['side'] ?? '' ) : '';
		if ( in_array( 'digitalogic-admin-workspace', explode( ',', $normal . ',' . $side ), true ) ) {
			return $saved;
		}

		return array(
			'normal' => 'digitalogic-admin-workspace,woocommerce_dashboard_status',
			'side'   => 'dashboard_site_health,dashboard_activity',
		);
	}

	/**
	 * Fit legacy three- and four-column layouts into the current layout.
	 *
	 * @param mixed $saved Saved dashboard column count.
	 */
	public static function dashboard_columns( $saved ): int {
		return 1 === (int) $saved ? 1 : 2;
	}

	/** Render shortcuts to the screens this user can open. */
	public static function render_links(): void {
		$links = array(
			array( 'edit_shop_orders', admin_url( self::orders_path() ), 'cart', 'Orders', 'سفارش‌ها' ),
			array( 'edit_products', admin_url( 'edit.php?post_type=product' ), 'products', 'Products', 'محصولات' ),
			array( 'read', home_url( '/' ), 'admin-site', 'View site', 'مشاهده سایت' ),
			array( 'manage_woocommerce', self::maintenance_url(), 'admin-tools', 'Site Maintenance', 'نگهداری سایت' ),
		);

		echo '<ul class="dg-workspace-links">';
		foreach ( $links as list( $capability, $url, $icon, $english, $persian ) ) {
			if ( current_user_can( $capability ) ) {
				printf( '<li><a href="%s"><span class="dashicons dashicons-%s" aria-hidden="true"></span>%s</a></li>', esc_url( $url ), esc_attr( $icon ), esc_html( self::label( $english, $persian ) ) );
			}
		}
		echo '</ul>';
	}

	/** Use the current WooCommerce order storage screen. */
	private static function orders_path(): string {
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? 'admin.php?page=wc-orders'
			: 'edit.php?post_type=shop_order';
	}

	/** Load the workspace styles before the admin notices are rendered. */
	public static function style(): void {
		$file = 'assets/css/admin-workspace.css';
		wp_enqueue_style( 'digitalogic-admin-workspace', DIGITALOGIC_PLUGIN_URL . $file, array(), (string) filemtime( DIGITALOGIC_PLUGIN_DIR . $file ) );
	}

	/**
	 * Identify the exact screens on which contextual plugin notices are relevant.
	 *
	 * @param string $classes Existing body classes.
	 */
	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->in_admin( 'site' ) ) {
			return $classes;
		}

		$class = ' dg-workspace-admin';
		if ( 'dashboard' === $screen->id ) {
			$class .= ' dg-workspace-dashboard';
		}
		if ( in_array( $screen->id, array( 'dashboard_page_' . self::MAINTENANCE, 'plugins' ), true ) ) {
			$class .= ' dg-workspace-show-support-update';
		}
		if ( 'dashboard_page_' . self::MAINTENANCE === $screen->id || str_contains( $screen->id, 'cleantalk' ) ) {
			$class .= ' dg-workspace-show-cleantalk-setup';
		}
		if ( str_contains( $screen->id, 'wordpress-zero-spam' ) || str_contains( $screen->id, 'zero-spam' ) ) {
			$class .= ' dg-workspace-show-zero-spam-setup';
		}
		if ( ! str_contains( $screen->id, 'rank-math' ) ) {
			$class .= ' dg-workspace-hide-rank-math-setup';
		}
		return $classes . $class;
	}

	/** Keep redundant cache and anti-spam shortcuts in their plugin menus. */
	public static function trim_toolbar(): void {
		global $wp_admin_bar;
		if ( ! is_blog_admin() ) {
			return;
		}

		foreach ( array( 'cleantalk_admin_bar__parent_node', 'wp-rocket', 'redis-cache' ) as $node ) {
			$wp_admin_bar->remove_node( $node );
		}
	}

	/** Route known requests for upgrades and donations away from admin work screens. */
	public static function route_vendor_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->in_admin( 'site' ) ) {
			return;
		}

		// WebToffee uses one instance for its order milestone, loyalty and shipping upsells.
		$notices = $GLOBALS['wp_filter']['admin_notices'] ?? null;
		foreach ( $notices->callbacks ?? array() as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$callback = $registered['function'];
				if (
					is_array( $callback )
					&& $callback[0] instanceof Wt_Pklist_Promo_Banners
					&& in_array( $callback[1], array( 'render_banners', 'render_shipping_promo_banner_maybe' ), true )
				) {
					remove_action( 'admin_notices', $callback, $priority );
				}
			}
		}
		remove_action( 'admin_notices', array( 'ACF_To_REST_API_Donation', 'donation_notice' ) );
		remove_action( 'admin_notices', 'ss_admin_notice' );
		if ( 'toplevel_page_ss_options' !== $screen->id && ! str_ends_with( $screen->id, '_page_ss_options' ) ) {
			remove_action( 'admin_notices', 'ss_wc_admin_notice' );
		}
	}

	/** Give Digits' existing tracking controls a native order sidebar destination. */
	public static function order_tracking_box(): void {
		if ( ! function_exists( 'digits_wc_sms_send_message' ) ) {
			return;
		}

		foreach ( array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ) as $screen ) {
			add_meta_box(
				'digitalogic-order-tracking',
				self::label( 'Send postal tracking code', 'ارسال کد رهگیری پستی' ),
				array( self::class, 'render_order_tracking_box' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/** The original Digits inputs and handlers are moved into this destination. */
	public static function render_order_tracking_box(): void {
		echo '<div data-dg-order-tracking-slot></div>';
	}

	/** Load the tracking layout adapter on WooCommerce order editors. */
	public static function order_assets(): void {
		if ( ! function_exists( 'digits_wc_sms_send_message' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
			return;
		}
		$file = 'assets/js/admin-order-workspace.js';
		wp_enqueue_script( 'digitalogic-order-workspace', DIGITALOGIC_PLUGIN_URL . $file, array( 'jquery' ), (string) filemtime( DIGITALOGIC_PLUGIN_DIR . $file ), true );
	}

	/** Keep Woodmart's optional newsletter recommendation out of global install notices. */
	public static function route_theme_recommendation(): void {
		$theme_plugins = TGM_Plugin_Activation::get_instance();
		$slug          = 'mailchimp-for-wp';
		if ( isset( $theme_plugins->plugins[ $slug ] ) && empty( $theme_plugins->plugins[ $slug ]['required'] ) ) {
			$theme_plugins->plugins[ $slug ]['hide_notice'] = true;
		}
	}

	/**
	 * Remove the donation and upgrade links from Installed Plugins.
	 *
	 * @param array  $links Plugin metadata links.
	 * @param string $file Plugin basename.
	 */
	public static function remove_plugin_promotions( array $links, string $file ): array {
		if ( 'acf-to-rest-api/class-acf-to-rest-api.php' === $file ) {
			unset( $links['acf_to_rest_api_donation'] );
		}
		if ( 'redis-cache/redis-cache.php' === $file ) {
			$links = array_filter( $links, static fn ( string $link ): bool => ! str_contains( $link, 'utm_medium=meta-row' ) );
		}
		return $links;
	}

	/**
	 * Move the optional Parsigate promotion off work screens.
	 *
	 * @param array $notices WP-Parsidate notices.
	 */
	public static function parsidate_notices( array $notices ): array {
		return array_filter( $notices, static fn( $notice ) => ( $notice['id'] ?? '' ) !== 'parsigate_demo_install' );
	}

	/**
	 * Keep dated offers in the announcements screen.
	 *
	 * @param array $alerts Permalink Manager default alerts.
	 */
	public static function permalink_offers( array $alerts ): array {
		foreach ( array_keys( $alerts ) as $key ) {
			if ( str_ends_with( (string) $key, '-promo' ) ) {
				unset( $alerts[ $key ] );
			}
		}
		return $alerts;
	}

	/**
	 * Keep setup instructions on maintenance and Permalink Manager screens.
	 *
	 * @param array $alerts Permalink Manager alerts.
	 */
	public static function permalink_setup( array $alerts ): array {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'permalink-manager' ) ) {
			unset( $alerts['setup-wizard'] );
		}
		return $alerts;
	}

	/** Render diagnostics and the live Patris mapping review. */
	public static function render_maintenance(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
			return;
		}
		echo '<div class="wrap dg-workspace-page"><h1>' . esc_html( self::label( 'Site Maintenance', 'نگهداری سایت' ) ) . '</h1>';
		Digitalogic_Patris_Mapping_Guard::render_admin_report();

		if ( current_user_can( 'manage_options' ) && class_exists( 'Permalink_Manager_Setup_Wizard' ) && Permalink_Manager_Setup_Wizard::is_unconfigured() ) {
			printf(
				'<section class="dg-workspace-panel"><h2>Permalink Manager</h2><p>%s</p><a href="%s">%s</a></section>',
				esc_html( self::label( 'The plugin currently uses its default settings.', 'افزونه از تنظیمات پیش‌فرض استفاده می‌کند.' ) ),
				esc_url( Permalink_Manager_Setup_Wizard::get_page_url() ),
				esc_html( self::label( 'Review permalink setup', 'بررسی تنظیمات پیوندهای یکتا' ) )
			);
		}
		if ( current_user_can( 'manage_options' ) && class_exists( '\DigitalogicAdmin\Health' ) ) {
			echo '<section class="dg-workspace-panel"><h2>' . esc_html( self::label( 'Digitalogic diagnostics', 'وضعیت سامانه‌های دیجیتالاجیک' ) ) . '</h2>';
			\DigitalogicAdmin\Health::render();
			echo '</section>';
		}
		echo '</div>';
	}

	/** Render the general announcements or promotions category. */
	public static function render_announcements(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$is_promotions = isset( $_GET['category'] ) && 'promotions' === $_GET['category'];
		$page_url      = admin_url( 'index.php?page=' . self::ANNOUNCEMENTS );
		$tabs          = array(
			array( $page_url, self::label( 'General', 'عمومی' ), ! $is_promotions ),
			array( add_query_arg( 'category', 'promotions', $page_url ), self::label( 'Promotions', 'تبلیغات و حمایت مالی' ), $is_promotions ),
		);

		echo '<div class="wrap dg-workspace-page"><h1>' . esc_html( self::label( 'Plugin Announcements', 'اطلاعیه افزونه‌ها' ) ) . '</h1>';
		echo '<nav class="nav-tab-wrapper dg-announcement-tabs" aria-label="' . esc_attr( self::label( 'Announcement categories', 'دسته‌های اطلاعیه' ) ) . '">';
		foreach ( $tabs as list( $url, $label, $active ) ) {
			printf(
				'<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s</a>',
				$active ? ' nav-tab-active' : '',
				esc_url( $url ),
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( $is_promotions ) {
			self::render_promotions();
		} else {
			self::render_general_announcements();
		}
		echo '</div>';
	}

	/** Show live update metadata and operational notes. */
	private static function render_general_announcements(): void {
		if ( current_user_can( 'update_plugins' ) ) {
			$updates = array_map( 'get_object_vars', get_plugin_updates() );
			uasort( $updates, static fn ( $a, $b ): int => strnatcasecmp( $a['Name'], $b['Name'] ) );
			echo '<section class="dg-workspace-panel dg-announcement-updates"><h2>' . esc_html( self::label( 'Available plugin updates', 'به‌روزرسانی‌های افزونه‌ها' ) ) . '</h2>';
			if ( $updates ) {
				echo '<ul class="dg-announcement-update-list">';
				foreach ( $updates as $plugin ) {
					printf( '<li><bdi>%s</bdi><bdi dir="ltr">%s → %s</bdi></li>', esc_html( $plugin['Name'] ), esc_html( (string) $plugin['Version'] ), esc_html( (string) ( $plugin['update']->new_version ?? '' ) ) );
				}
				echo '</ul>';
				printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( self_admin_url( 'update-core.php' ) ), esc_html( self::label( 'Review updates', 'بررسی به‌روزرسانی‌ها' ) ) );
			} else {
				echo '<p>' . esc_html( self::label( 'WordPress currently reports no pending plugin updates.', 'وردپرس در حال حاضر به‌روزرسانی افزونه‌ای گزارش نمی‌کند.' ) ) . '</p>';
			}
			echo '</section>';
		}

		$notes = array(
			array(
				'stop-spammer-registrations-plugin/stop-spammer-registrations-new.php',
				'Stop Spammers',
				'The vendor has ended feature development and plans to provide security patches only.',
				'توسعه قابلیت‌های جدید متوقف شده است و سازنده فقط اصلاحات امنیتی ارائه می‌دهد.',
				'https://wordpress.org/plugins/stop-spammer-registrations-plugin/',
			),
			array( '', self::label( 'Site Maintenance', 'نگهداری سایت' ), 'Review configuration and compatibility issues that need attention.', 'مشاهده مشکلات پیکربندی و سازگاری نیازمند پیگیری.', self::maintenance_url(), self::label( 'Open Site Maintenance', 'باز کردن نگهداری سایت' ) ),
		);
		self::render_announcement_group( self::label( 'Maintenance notes', 'یادداشت‌های نگهداری' ), $notes );
	}

	/** Show optional commercial material only within the promotions category. */
	private static function render_promotions(): void {
		$webtoffee_offer = self::label( 'Bulk export for PDF invoices and credit notes', 'خروجی گروهی فاکتورها و یادداشت‌های اعتباری' );
		if ( class_exists( 'Wt_Pklist_Promo_Banners' ) && 'loyalty' === Wt_Pklist_Promo_Banners::get_active_promo_banner_type() ) {
			$webtoffee_offer .= ' · ' . self::label( 'Vendor loyalty offer: 15% off PRO with coupon PSLOYAL50.', 'پیشنهاد وفاداری سازنده: ۱۵٪ تخفیف نسخه حرفه‌ای با کد PSLOYAL50.' );
		}
		$paid = array(
			array( 'permalink-manager/permalink-manager.php', 'Permalink Manager', 'Pro features and offers', 'امکانات و پیشنهادهای نسخه حرفه‌ای', 'https://permalinkmanager.pro/pricing/' ),
			array( 'wp-rocket/wp-rocket.php', 'WP Rocket', 'Optional RocketCDN service', 'سرویس اختیاری RocketCDN', 'https://wp-rocket.me/rocketcdn/' ),
			array( 'print-invoices-packing-slip-labels-for-woocommerce/print-invoices-packing-slip-labels-for-woocommerce.php', 'WebToffee', $webtoffee_offer, $webtoffee_offer, 'https://www.webtoffee.com/product/woocommerce-pdf-invoices-packing-slips/' ),
			array( 'zero-spam/wordpress-zero-spam.php', 'Zero Spam', 'Optional Enhanced Protection plan', 'طرح اختیاری محافظت پیشرفته', 'https://www.zerospam.org/' ),
			array( 'redis-cache/redis-cache.php', 'Object Cache Pro', 'Paid upgrade for Redis Object Cache', 'ارتقای پولی افزونه Redis Object Cache', 'https://objectcache.pro/' ),
		);
		if ( class_exists( 'WC_Helper_Updater' ) && WC_Helper_Updater::get_plugins_without_subscription( false ) ) {
			$paid[] = array( '', 'WooCommerce', 'Subscriptions for WooCommerce extensions', 'اشتراک افزونه‌های ووکامرس', admin_url( 'admin.php?page=wc-admin&tab=my-subscriptions&path=%2Fextensions' ), self::label( 'View subscriptions', 'مشاهده اشتراک‌ها' ) );
		}

		$donations = array();
		if ( class_exists( 'ACF_To_REST_API_Donation' ) ) {
			$donations[] = array(
				'acf-to-rest-api/class-acf-to-rest-api.php',
				'ACF to REST-API',
				'Optional donation to the plugin maintainer',
				'حمایت مالی اختیاری از توسعه‌دهنده افزونه',
				ACF_To_REST_API_Donation::DONATION_URL,
				self::label( 'Donate', 'حمایت مالی' ),
			);
		}

		$offers = array(
			array( 'digits/digit.php', 'Digits', 'Stories for your storefront', 'نمایش استوری در فروشگاه', 'https://mersa-web.ir/go/story/' ),
			array( 'wp-parsidate/wp-parsidate.php', 'WP-Parsidate', 'Optional Parsigate payment integration', 'افزونه اختیاری درگاه پرداخت پارسی‌گیت', 'https://wordpress.org/plugins/parsigate/' ),
			array( 'wp-parsidate/wp-parsidate.php', 'Files.ir', 'Cloud storage featured by WP-Parsidate', 'سرویس ذخیره‌سازی ابری معرفی‌شده توسط پارسی‌دیت', 'https://files.ir/' ),
		);
		if ( 'woodmart' === get_template() && ! is_plugin_active( 'mailchimp-for-wp/mailchimp-for-wp.php' ) ) {
			$offers[] = array( '', 'Mailchimp for WordPress', 'Optional newsletter integration recommended by Woodmart', 'افزونه اختیاری خبرنامه پیشنهادی قالب وودمارت', 'https://wordpress.org/plugins/mailchimp-for-wp/' );
		}
		self::render_announcement_group( self::label( 'Paid upgrades & subscriptions', 'ارتقای پولی و اشتراک‌ها' ), $paid );
		self::render_announcement_group( self::label( 'Donations', 'حمایت مالی' ), $donations );
		self::render_announcement_group( self::label( 'Offers & recommendations', 'پیشنهادها و معرفی افزونه‌ها' ), $offers );
	}

	/**
	 * Render a group of cards for installed plugins and site-level destinations.
	 *
	 * @param string $heading Group heading.
	 * @param array  $items Announcement cards.
	 */
	private static function render_announcement_group( string $heading, array $items ): void {
		$items = array_filter( $items, static fn ( array $item ): bool => ! $item[0] || is_plugin_active( $item[0] ) );
		if ( ! $items ) {
			return;
		}
		echo '<section class="dg-announcement-group"><h2>' . esc_html( $heading ) . '</h2><div class="dg-workspace-announcements">';
		foreach ( $items as $announcement ) {
			list( , $vendor, $english, $persian, $url ) = $announcement;
			$action                                     = $announcement[5] ?? self::label( 'Learn more', 'اطلاعات بیشتر' );
			printf( '<article class="dg-workspace-panel"><h3><bdi>%1$s</bdi></h3><p>%2$s</p><a href="%3$s" aria-label="%4$s">%5$s</a></article>', esc_html( $vendor ), esc_html( self::label( $english, $persian ) ), esc_url( $url ), esc_attr( $action . ' · ' . $vendor ), esc_html( $action ) );
		}
		echo '</div></section>';
	}
}
