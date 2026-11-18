<?php
/**
 * Prevent commerce without an owner-sourced Patris identity.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.Files.FileName -- Follows the shared Patris module naming convention.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Enforce source identity and weight at commerce boundaries. */
final class Digitalogic_Patris_Mapping_Guard {
	/**
	 * Request-local source identity index.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Register the mapping policy hooks.
	 */
	public static function boot() {
		add_filter( 'woocommerce_variation_is_visible', array( self::class, 'variation_visible' ), PHP_INT_MAX, 4 );
		add_filter( 'woocommerce_available_variation', array( self::class, 'variation_data' ), PHP_INT_MAX, 3 );
		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
			add_filter( 'woocommerce_product_get_' . $field, array( self::class, 'visible_price' ), PHP_INT_MAX, 2 );
			add_filter( 'woocommerce_product_variation_get_' . $field, array( self::class, 'visible_price' ), PHP_INT_MAX, 2 );
			add_filter( 'woocommerce_variation_prices_' . $field, array( self::class, 'visible_price' ), PHP_INT_MAX, 2 );
		}
		add_filter( 'woocommerce_is_purchasable', array( self::class, 'purchasable' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( self::class, 'purchasable' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_before_product_object_save', array( self::class, 'before_save' ), 100, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( self::class, 'before_save' ), 100, 1 );
		add_action( 'digitalogic_product_sync_state_committed', array( self::class, 'clear_index' ), 1, 0 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( self::class, 'price_hash' ), 10, 1 );
		add_action( 'updated_option', array( self::class, 'reset' ), 10, 1 );
		add_action( 'added_option', array( self::class, 'reset' ), 10, 1 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'add_post_metadata', array( self::class, 'price_metadata' ), 100, 4 );
		add_filter( 'update_post_metadata', array( self::class, 'price_metadata' ), 100, 4 );
	}

	/** Keep stock-bearing models visible independently of price eligibility. */
	private static function unpriced_stock_variation( $product ) {
		return $product instanceof WC_Product_Variation
			&& 'publish' === $product->get_status()
			&& $product->is_in_stock()
			&& (float) $product->get_stock_quantity() > 0
			&& '' === $product->get_price();
	}

	/** Price eligibility must not hide a model with real inventory. */
	public static function variation_visible( $visible, $variation_id, $parent_id, $variation ) {
		return self::unpriced_stock_variation( $variation ) ? true : $visible;
	}

	/** An absent price is not a zero price; purchasing remains unavailable. */
	public static function variation_data( $data, $parent, $variation ) {
		$raw_price = trim( (string) $variation->get_price() );
		if ( ! $variation->is_in_stock() || ! self::priceable( $variation ) || '' === $raw_price || ! is_numeric( $raw_price ) ) {
			$data['price_html']                         = '';
			$data['display_price']                      = null;
			$data['display_regular_price']              = null;
			$data['digitalogic_price_contract']         = 1;
			$data['digitalogic_price_raw']              = null;
			$data['digitalogic_price_html']             = '';
			$data['digitalogic_price_currency']         = get_woocommerce_currency();
			$data['digitalogic_price_decimals']         = wc_get_price_decimals();
			$data['is_purchasable']                     = false;
			$data['digitalogic_price_hidden_for_stock'] = ! $variation->is_in_stock();
			return $data;
		}

		$price_html = trim( (string) ( $data['price_html'] ?? '' ) );
		if ( '' === $price_html ) {
			$variation_html = trim( (string) $variation->get_price_html() );
			if ( '' !== $variation_html ) {
				$price_html = '<span class="price">' . $variation_html . '</span>';
			}
		}

		if ( '' !== $price_html ) {
			$price_html = '<span class="digitalogic-variation-price-contract" data-digitalogic-price-raw="'
				. esc_attr( $raw_price ) . '">' . $price_html . '</span>';
		}

		$data['price_html']                         = $price_html;
		$data['digitalogic_price_contract']         = 1;
		$data['digitalogic_price_raw']              = $raw_price;
		$data['digitalogic_price_html']             = $price_html;
		$data['digitalogic_price_currency']         = get_woocommerce_currency();
		$data['digitalogic_price_decimals']         = wc_get_price_decimals();
		$data['digitalogic_price_hidden_for_stock'] = false;
		return $data;
	}
	/**
	 * Discard the request-local source identity index.
	 */
	public static function clear_index() {
		self::$index = null; }
	/**
	 * Version the variation price cache.
	 *
	 * @param array $hash Existing price cache hash.
	 * @return array
	 */
	public static function price_hash( $hash ) {
		$hash['patris_mapping_policy'] = 2;
		return $hash; }

	/**
	 * Discard identity data after source state changes.
	 *
	 * @param string $name Changed option name.
	 */
	public static function reset( $name ) {
		if ( 'digitalogic_product_sync_state' === $name ) {
			self::$index = null; }
	}

	/**
	 * Parent containers derive prices from their independently checked leaves.
	 *
	 * @param WC_Product $product Product being checked.
	 * @return bool
	 */
	public static function connected( $product ) {
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && ! $child->is_type( array( 'variable', 'grouped' ) ) && self::connected( $child ) ) {
					return true; }
			}
			return false;
		}
		if ( null === self::$index ) {
			self::$index = array();
			$state       = get_option( 'digitalogic_product_sync_state', array() );
			foreach ( ( $state['sources'] ?? array() ) as $source ) {
				$scope = $source['source'] ?? array();
				foreach ( ( $source['products'] ?? array() ) as $code => $row ) {
					$key                 = ( $scope['id'] ?? '' ) . "\n" . ( $scope['dataset'] ?? '' ) . "\n" . $code;
					self::$index[ $key ] = (int) ( $source['applied_products'][ $code ]['woocommerce_id'] ?? 0 );
				}
			}
		}
		$code       = (string) $product->get_meta( '_digitalogic_patris_product_code', true );
		$owner_code = (string) $product->get_meta( '_digitalogic_patris_owner_product_code', true );
		$key        = (string) $product->get_meta( '_digitalogic_patris_owner_source_id', true ) . "\n"
		. (string) $product->get_meta( '_digitalogic_patris_owner_dataset', true ) . "\n" . $code;
		return '' !== $code && (string) $product->get_sku( 'edit' ) === $code
		&& $owner_code === $code && isset( self::$index[ $key ] )
		&& self::$index[ $key ] === (int) $product->get_id();
	}

	/**
	 * Filter the displayed price.
	 *
	 * @param mixed      $price Existing price.
	 * @param WC_Product $product Product being displayed.
	 * @return mixed
	 */
	public static function visible_price( $price, $product ) {
		return self::priceable( $product ) ? $price : '';
	}
	/**
	 * Require an eligible source mapping for purchases.
	 *
	 * @param bool       $allowed Existing purchase permission.
	 * @param WC_Product $product Product being checked.
	 * @return bool
	 */
	public static function purchasable( $allowed, $product ) {
		return $allowed && self::priceable( $product );
	}
	/**
	 * Check source identity and positive source weight.
	 *
	 * @param WC_Product $product Product being checked.
	 * @return bool
	 */
	private static function priceable( $product ) {
		if ( ! self::connected( $product ) ) {
			return false; }
		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return true; }
		$weight = $product->get_meta( '_digitalogic_patris_weight_grams', true );
		return is_numeric( $weight ) && (float) $weight > 0;
	}
	/**
	 * Block unauthorized unmapped price writes.
	 *
	 * @param WC_Product $product Product being saved.
	 * @throws RuntimeException When an unmapped product has a price.
	 */
	public static function before_save( $product ) {
		if ( Digitalogic_Patris_Price_Write_Guard::instance()->is_authorized_write() ) {
			return; }
		if ( ! self::connected( $product ) ) {
			$message = 'CRITICAL: Product ' . $product->get_id() . ' has no valid Patris mapping; price write blocked.';
			wc_get_logger()->critical( $message, array( 'source' => 'digitalogic-patris-integrity' ) );
			$report = get_option( 'digitalogic_patris_mapping_integrity', array() );
			$ids    = array_map( 'intval', $report['unmapped_ids'] ?? array() );
			if ( $product->get_id() && ! in_array( $product->get_id(), $ids, true ) ) {
				$ids[] = $product->get_id();
				update_option(
					'digitalogic_patris_mapping_integrity',
					array(
						'severity'     => 'critical',
						'checked_at'   => gmdate( 'c' ),
						'unmapped_ids' => $ids,
					),
					false
				);
			}
			if ( '' !== (string) $product->get_price( 'edit' ) ) {
				throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text; output consumers escape for their context.
			}
		}
	}
	/**
	 * Guard raw price metadata updates.
	 *
	 * @param mixed  $check Existing filter result.
	 * @param int    $id Product ID.
	 * @param string $key Metadata key.
	 * @param mixed  $value Proposed metadata value.
	 * @return mixed
	 */
	public static function price_metadata( $check, $id, $key, $value ) {
		if ( null !== $check || Digitalogic_Patris_Price_Write_Guard::instance()->is_authorized_write()
			|| ! in_array( $key, array( '_price', '_regular_price', '_sale_price' ), true ) || '' === (string) $value ) {
			return $check; }
		$p = wc_get_product( $id );
		if ( $p && ! self::connected( $p ) ) {
			wc_get_logger()->critical( 'CRITICAL: Unmapped Patris price metadata write blocked for ' . $id, array( 'source' => 'digitalogic-patris-integrity' ) );
			return false;
		}
		return $check;
	}
	/** Show a compact review of mapping failures on relevant admin screens. */
	public static function notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce.
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'edit-product', 'product' ), true ) ) {
			return;
		}

		$groups = self::unresolved_admin_mappings();
		$ids    = array_merge( $groups['published'] ?? array(), $groups['other'] ?? array() );
		if ( 'product' === $screen->id ) {
			$product_id = (int) get_the_ID();
			if ( ! $product_id ) {
				return;
			}
			$ids = array_filter(
				$ids,
				static fn ( int $id ): bool => $id === $product_id || (int) wp_get_post_parent_id( $id ) === $product_id
			);
		}
		if ( ! $ids ) {
			return;
		}

		$affected = count( $ids );
		$preview  = array_slice( $ids, 0, 6 );
		$name     = self::admin_product_name( reset( $preview ) );
		$more     = $affected - 1;
		echo '<div class="notice notice-error dg-patris-notice"><details class="dg-patris-disclosure">';
		printf(
			'<summary><span class="dg-patris-summary-heading"><strong>%s</strong><span class="dg-patris-count">%s</span></span><span class="dg-patris-preview"><bdi class="dg-patris-ellipsis">%s</bdi>',
			esc_html( Digitalogic_Admin_Workspace::label( 'Patris pricing is blocked for unmapped products', 'قیمت‌گذاری پاتریس برای کالاهای بدون نگاشت مسدود است' ) ),
			esc_html( sprintf( Digitalogic_Admin_Workspace::label( '%s affected', '%s مورد' ), number_format_i18n( $affected ) ) ),
			esc_html( $name )
		);
		if ( $more > 0 ) {
			printf( '<span class="dg-patris-count">%s</span>', esc_html( sprintf( Digitalogic_Admin_Workspace::label( '… %s more', '… %s مورد دیگر' ), number_format_i18n( $more ) ) ) );
		}
		echo '</span></summary><div class="dg-patris-disclosure-body">';
		self::render_admin_product_links( $preview );
		printf(
			'<p class="dg-patris-report-link"><a href="%s">%s</a></p>',
			esc_url( Digitalogic_Admin_Workspace::maintenance_url() . '#patris-mappings' ),
			esc_html( Digitalogic_Admin_Workspace::label( 'Open the full mapping report', 'مشاهده گزارش کامل نگاشت‌ها' ) )
		);
		echo '</div></details></div>';
	}

	/**
	 * Return the WooCommerce name, including the variation attributes where available.
	 *
	 * @param int $id WooCommerce object ID.
	 */
	private static function admin_product_name( int $id ): string {
		$product = wc_get_product( $id );
		$name    = $product ? trim( $product->get_name() ) : '';
		return '' !== $name ? $name : Digitalogic_Admin_Workspace::label( 'Untitled product', 'کالای بدون نام' );
	}

	/**
	 * Render WooCommerce records as named editor links.
	 *
	 * @param int[] $ids WooCommerce object IDs.
	 */
	private static function render_admin_product_links( array $ids ): void {
		echo '<ul class="dg-patris-products">';
		foreach ( $ids as $id ) {
			$parent_id = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : 0;
			$url       = get_edit_post_link( $parent_id ? $parent_id : $id, 'raw' );
			$name      = self::admin_product_name( $id );
			$object_id = $parent_id ? Digitalogic_Admin_Workspace::label( 'Variation', 'مدل' ) . ' #' . $id : '#' . $id;
			echo '<li>';
			if ( $url ) {
				printf( '<a class="dg-patris-product" href="%s" title="%s">', esc_url( $url ), esc_attr( $name ) );
			} else {
				echo '<span class="dg-patris-product">';
			}
			printf( '<bdi class="dg-patris-ellipsis">%s</bdi><bdi class="dg-patris-product-id">%s</bdi>', esc_html( $name ), esc_html( $object_id ) );
			echo $url ? '</a>' : '</span>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Check recorded pricing failures against current Patris source identities.
	 *
	 * @param bool $refresh Recheck the products directly on the maintenance screen.
	 * @return array<string,int[]>
	 */
	private static function unresolved_admin_mappings( bool $refresh = false ): array {
		$report = get_option( 'digitalogic_patris_mapping_integrity', array() );
		$ids    = array_unique( array_filter( array_map( 'absint', (array) ( $report['unmapped_ids'] ?? array() ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$key = 'patris_mapping_review_' . md5( implode( ',', $ids ) );
		if ( ! $refresh ) {
			$cached = wp_cache_get( $key, 'digitalogic' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$unmapped = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && ! self::connected( $product ) ) {
				$unmapped[ 'publish' === $product->get_status() ? 'published' : 'other' ][] = $id;
			}
		}
		wp_cache_set( $key, $unmapped, 'digitalogic', 2 * MINUTE_IN_SECONDS );
		return $unmapped;
	}

	/** Show the recorded products that still lack a current Patris mapping. */
	public static function render_admin_report(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce.
			return;
		}
		$unmapped = self::unresolved_admin_mappings( true );

		echo '<section id="patris-mappings" class="dg-workspace-panel"><h2>' . esc_html( Digitalogic_Admin_Workspace::label( 'Patris product mappings', 'نگاشت کالاهای پاتریس' ) ) . '</h2>';
		if ( ! $unmapped ) {
			echo '<p>' . esc_html( Digitalogic_Admin_Workspace::label( 'No unresolved product mappings in the recorded pricing failures.', 'در خطاهای ثبت‌شده قیمت‌گذاری، کالای بدون نگاشت باقی نمانده است.' ) ) . '</p></section>';
			return;
		}

		$groups = array(
			'published' => Digitalogic_Admin_Workspace::label( 'Published products and variations', 'کالاها و مدل‌های منتشرشده' ),
			'other'     => Digitalogic_Admin_Workspace::label( 'Other product statuses', 'کالاها با وضعیت‌های دیگر' ),
		);
		foreach ( $groups as $status => $label ) {
			if ( empty( $unmapped[ $status ] ) ) {
				continue;
			}
			printf( '<details class="dg-patris-disclosure"><summary><span class="dg-patris-summary-heading"><strong>%s</strong><span class="dg-patris-count">%s</span></span></summary><div class="dg-patris-disclosure-body">', esc_html( $label ), esc_html( number_format_i18n( count( $unmapped[ $status ] ) ) ) );
			self::render_admin_product_links( $unmapped[ $status ] );
			echo '</div></details>';
		}
		echo '</section>';
	}
}
