<?php
/**
 * Front-end search acceleration over the Digitalogic WebSocket transport.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digitalogic_Frontend_Search {
	private const CACHE_KEY_VERSION = 'canonical-alias-rank-v1';

	private static $instance      = null;
	private $invalidation_pending = false;

	private $public_actions = array(
		'woodmart_ajax_search',
		'digitalogic_catalog_page',
	);

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 100 );
		add_filter( 'digitalogic_command_requires_auth', array( $this, 'allow_public_search_command' ), 10, 4 );
		add_filter( 'digitalogic_websocket_ajax_action_allowed', array( $this, 'allow_public_ajax_search_action' ), 10, 4 );
		add_action( 'wp_ajax_woodmart_ajax_search', array( $this, 'serve_search' ), 1 );
		add_action( 'wp_ajax_nopriv_woodmart_ajax_search', array( $this, 'serve_search' ), 1 );
		add_filter( 'woodmart_option', array( $this, 'compile_search_aliases_for_woodmart' ), 20, 2 );
		add_filter( 'gettext_woodmart', array( $this, 'clarify_search_alias_admin_help' ), 20, 3 );
		add_action( 'update_option_xts-woodmart-options', array( $this, 'search_aliases_updated' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'cache_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'register_cache_settings' ) );
		add_action( 'digitalogic_report_projection_invalidated', array( $this, 'queue_invalidation' ) );
		add_action( 'digitalogic_pricing_apply_committed', array( $this, 'queue_invalidation' ) );
		add_action( 'shutdown', array( $this, 'publish_invalidation' ) );
	}

	/** Coalesce committed changes into one public invalidation per request. */
	public function queue_invalidation() {
		$this->invalidation_pending = true;
	}

	/**
	 * Give WoodMart a symmetric request-local view of its one-way synonym syntax.
	 *
	 * The administrator continues to edit the concise raw setting. Admin screens
	 * and WP-CLI therefore receive the saved value, while customer searches and
	 * WoodMart AJAX receive the shared normalized directional projection.
	 *
	 * @param mixed  $value Option value.
	 * @param string $slug WoodMart option key.
	 * @return mixed
	 */
	public function compile_search_aliases_for_woodmart( $value, $slug ) {
		if ( 'search_synonyms' !== $slug || ! is_scalar( $value ) ) {
			return $value;
		}
		$admin = function_exists( 'is_admin' ) && is_admin();
		$ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		if ( ( $admin && ! $ajax ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $value;
		}
		$policy = '\\Digitalogic\\Laravel\\Services\\CatalogSearchPolicy';
		return class_exists( $policy ) && is_callable( array( $policy, 'woodmartConfig' ) )
			? $policy::woodmartConfig( (string) $value )
			: $value;
	}

	/**
	 * Fence server/browser caches and notify connected storefronts after an edit.
	 *
	 * @param mixed  $old_value Previous WoodMart options.
	 * @param mixed  $new_value New WoodMart options.
	 * @param string $option Option name.
	 * @return void
	 */
	public function search_aliases_updated( $old_value, $new_value, $option = '' ) {
		unset( $option );
		$old_aliases = is_array( $old_value ) && is_scalar( $old_value['search_synonyms'] ?? null ) ? (string) $old_value['search_synonyms'] : '';
		$new_aliases = is_array( $new_value ) && is_scalar( $new_value['search_synonyms'] ?? null ) ? (string) $new_value['search_synonyms'] : '';
		if ( ! hash_equals( hash( 'sha256', $old_aliases ), hash( 'sha256', $new_aliases ) ) ) {
			$this->queue_invalidation();
		}
	}

	/**
	 * Describe Digitalogic's two-way semantics in WoodMart's existing field.
	 *
	 * @param string $translation Current translation.
	 * @param string $text Source text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function clarify_search_alias_admin_help( $translation, $text, $domain ) {
		if ( 'woodmart' !== $domain || false === strpos( (string) $text, 'Synonyms are one-way' ) ) {
			return $translation;
		}

		return function_exists( 'is_rtl' ) && is_rtl()
			? 'هر خط یک گروه دوطرفه است: «عبارت اصلی = جایگزین ۱، جایگزین ۲». دیجیتالاجیک فاصله، خط تیره و شکل چسبیده را برای همهٔ عبارت‌ها یکسان‌سازی می‌کند؛ نیازی به ثبت خط معکوس نیست.'
			: 'Each line is one two-way group: “canonical term = alternate 1, alternate 2”. Digitalogic normalizes spaced, hyphenated, and compact forms for every term; reciprocal rows are not required.';
	}

	/** Publish after all product saves in the request have completed. */
	public function publish_invalidation() {
		if ( $this->invalidation_pending ) {
			$this->invalidation_pending = false;
			Digitalogic_Panel::record_event( 'search.invalidated', array( 'scope' => 'search' ) );
		}
	}

	public function enqueue_scripts() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		wp_enqueue_style(
			'digitalogic-frontend-search',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/frontend-search.css',
			array(),
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/css/frontend-search.css' ) ?: DIGITALOGIC_VERSION
		);

		wp_enqueue_script(
			'digitalogic-frontend-search-ws',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/frontend-search-ws.js',
			array( 'jquery' ),
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/js/frontend-search-ws.js' ) ?: DIGITALOGIC_VERSION,
			true
		);

		wp_localize_script(
			'digitalogic-frontend-search-ws',
			'digitalogicFrontendSearchWs',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'websocket'      => Digitalogic_WebSocket::instance()->get_public_client_config(),
				'actions'        => apply_filters( 'digitalogic_frontend_search_websocket_actions', $this->public_actions ),
				'fallback_delay' => (int) apply_filters( 'digitalogic_frontend_search_websocket_fallback_delay', 1200 ),
				'cache'          => $this->cache_settings(),
				'unavailable'    => __( 'ناموجود', 'digitalogic' ),
			)
		);
	}

	public function cache_settings() {
		return $this->sanitize_cache_settings( get_option( 'digitalogic_search_cache', array() ) );
	}

	/**
	 * Keep the normal quantity-one WooCommerce HTML in search previews.
	 *
	 * @param string $tier_html Bulk-quantity price presentation.
	 * @param string $default_html Normal WooCommerce price HTML.
	 * @return string Normal price with currency, tax and variation formatting retained.
	 */
	public function preview_price_html( $tier_html, $default_html ) {
		unset( $tier_html );
		return $default_html;
	}

	/**
	 * Render commercial price without advertising a bulk tier as a unit price.
	 *
	 * @param WC_Product $product Search result product.
	 * @return string Price HTML.
	 */
	private function product_price_html( $product ) {
		add_filter( 'tiered_pricing_table/catalog_pricing/price_html', array( $this, 'preview_price_html' ), PHP_INT_MAX, 2 );
		try {
			return $product->get_price_html();
		} finally {
			remove_filter( 'tiered_pricing_table/catalog_pricing/price_html', array( $this, 'preview_price_html' ), PHP_INT_MAX );
		}
	}

	public function sanitize_cache_settings( $value ) {
		$value = is_array( $value ) ? $value : array();
		return array(
			'server'  => in_array( $value['server'] ?? 'object', array( 'off', 'object', 'transient' ), true ) ? ( $value['server'] ?? 'object' ) : 'object',
			'browser' => ! isset( $value['browser'] ) || ! empty( $value['browser'] ),
			'ttl'     => max( 5, min( 600, (int) ( $value['ttl'] ?? 60 ) ) ),
			'entries' => max( 1, min( 100, (int) ( $value['entries'] ?? 32 ) ) ),
		);
	}

	public function cache_menu() {
		add_submenu_page( 'digitalogic', __( 'Search cache', 'digitalogic' ), __( 'Search cache', 'digitalogic' ), 'manage_options', 'digitalogic-search-cache', array( $this, 'render_cache_settings' ) );
	}

	public function register_cache_settings() {
		register_setting( 'digitalogic_search_cache', 'digitalogic_search_cache', array( 'sanitize_callback' => array( $this, 'sanitize_cache_settings' ) ) );
	}

	public function render_cache_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->cache_settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Search cache', 'digitalogic' ) . '</h1><p>' . esc_html__( 'Changes to prices and products invalidate the cache. Every browser cache reuse is validated by the server; prices are verified against WooCommerce on every response. Unverified cached prices are never used on a failed request.', 'digitalogic' ) . '</p><form action="options.php" method="post">';
		settings_fields( 'digitalogic_search_cache' );
		echo '<p><label>' . esc_html__( 'Server storage', 'digitalogic' ) . ' <select name="digitalogic_search_cache[server]">';
		foreach ( array(
			'off'       => 'Disabled',
			'object'    => 'WordPress object cache (Redis when available)',
			'transient' => 'WordPress transients',
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $settings['server'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p><p><input type="hidden" name="digitalogic_search_cache[browser]" value="0"><label><input type="checkbox" name="digitalogic_search_cache[browser]" value="1" ' . checked( $settings['browser'], true, false ) . '> ' . esc_html__( 'Cache in browser memory (validated before reuse)', 'digitalogic' ) . '</label></p>';
		foreach ( array(
			'ttl'     => array( 'Retention in seconds', 5, 600 ),
			'entries' => array( 'Maximum browser entries', 1, 100 ),
		) as $key => $field ) {
			echo '<p><label>' . esc_html( $field[0] ) . ' <input type="number" name="digitalogic_search_cache[' . esc_attr( $key ) . ']" min="' . esc_attr( $field[1] ) . '" max="' . esc_attr( $field[2] ) . '" value="' . esc_attr( $settings[ $key ] ) . '"></label></p>';
		}
		submit_button();
		echo '</form></div>';
	}

	/** Cache search work, with an authoritative generation fence and fresh price readback. */
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only search; settings writes use the WordPress Settings API nonce and manage_options.
	public function serve_search() {
		if ( ! class_exists( 'XTS\\Modules\\Search\\Ajax_Search' ) ) {
			return;
		}
		nocache_headers();
		$settings   = $this->cache_settings();
		$engine     = Digitalogic_Report_Engine::instance();
		$generation = $engine->current_projection_generation();
		if ( is_wp_error( $generation ) ) {
			wp_send_json(
				array(
					'suggestions' => array(),
					'error'       => 'search_freshness_unavailable',
				),
				503
			);
			return;
		}
		$query = array();
		foreach ( array( 'query', 'post_type', 'number', 'product_cat', 'include_cat_search' ) as $field ) {
			$query[ $field ] = isset( $_REQUEST[ $field ] ) && is_scalar( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ) : '';
		}
		$query['number'] = max( 1, min( 50, (int) ( $query['number'] ?: 5 ) ) );
		if ( strlen( $query['query'] ) > 200 ) {
			wp_send_json(
				array(
					'suggestions' => array(),
					'error'       => 'search_query_too_long',
				),
				400
			);
			return;
		}
		// User/cookie context is hashed, never exposed or shared across sessions.
		// Expanded terms and the raw administrator-owned revision both fence alias edits.
		$policy         = '\\Digitalogic\\Laravel\\Services\\CatalogSearchPolicy';
		$query_terms    = class_exists( $policy ) ? $policy::terms( $query['query'] ) : array( $query['query'] );
		$alias_revision = class_exists( $policy ) && is_callable( array( $policy, 'revision' ) ) ? $policy::revision() : hash( 'sha256', '' );
		$key            = 'dg_search_' . hash( 'sha256', wp_json_encode( array( self::CACHE_KEY_VERSION, $generation, $query, $query_terms, $alias_revision, $settings, get_current_user_id(), $_COOKIE, get_locale(), get_woocommerce_currency() ) ) );
		$data           = false;
		if ( 'object' === $settings['server'] ) {
			$data = wp_cache_get( $key, 'digitalogic_search' );
		} elseif ( 'transient' === $settings['server'] ) {
			$data = get_transient( $key );
		}
		$hit = is_array( $data );
		if ( ! $hit ) {
			$old_request = $_REQUEST;
			$theme_query = $this->theme_search_query( $query, $policy );
			$_REQUEST    = array_merge( $_REQUEST, $theme_query );
			try {
				$search      = \XTS\Modules\Search\Ajax_Search::get_instance();
				$suggestions = $search->build_suggestions( $search->get_main_suggestions(), $search->get_product_categories_suggestions(), $search->get_blog_suggestions() );
				$data        = array( 'suggestions' => $suggestions );
				foreach ( $data['suggestions'] as &$item ) {
					if ( isset( $item['permalink'] ) ) {
						$product_id = url_to_postid( $item['permalink'] );
						$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
						if ( ! $product ) {
							continue;
						}
						$item['_dg_product_id'] = $product_id;
						unset( $item['price'] );
					}
				}
				unset( $item );
				$data['suggestions'] = $this->filter_public_product_suggestions( $data['suggestions'] );
			} finally {
				$_REQUEST = $old_request;
			}
		}
		// Keep price HTML out of the shared search cache. This also covers an
		// external pricing commit whose asynchronous invalidation is pending.
		$result                = $data;
		$result['suggestions'] = $this->filter_public_product_suggestions( (array) ( $result['suggestions'] ?? array() ) );
		foreach ( $result['suggestions'] as &$item ) {
			if ( isset( $item['_dg_product_id'] ) ) {
				$product   = wc_get_product( $item['_dg_product_id'] );
				$available = $product && $product->is_in_stock();
				if ( $product ) {
					$item['is_in_stock'] = $available;
				}
				$item['price'] = $available ? $this->product_price_html( $product ) : '';
				unset( $item['_dg_product_id'] );
			}
		}
		unset( $item );
		$after = $engine->current_projection_generation();
		if ( is_wp_error( $after ) || $generation !== $after ) {
			wp_send_json(
				array(
					'suggestions' => array(),
					'error'       => 'search_changed_during_read',
				),
				409
			);
			return;
		}
		if ( ! $hit && 'object' === $settings['server'] ) {
			wp_cache_set( $key, $data, 'digitalogic_search', $settings['ttl'] );
		} elseif ( ! $hit && 'transient' === $settings['server'] ) {
			set_transient( $key, $data, $settings['ttl'] );
		}
		$signature = hash( 'sha256', $key . wp_json_encode( $result ) );
		$known     = isset( $_REQUEST['dg_search_signature'] ) && is_string( $_REQUEST['dg_search_signature'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dg_search_signature'] ) ) : '';
		if ( $settings['browser'] && hash_equals( $signature, $known ) ) {
			$result = array( 'unchanged' => true );
		}
		$result['dg_cache'] = array(
			'signature' => $signature,
			'hit'       => $hit,
			'settings'  => $settings,
		);
		wp_send_json( $result );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/**
	 * Use one canonical alias form for WoodMart ranking without hiding exact SKUs.
	 *
	 * @param array  $query Sanitized request fields retained for cache identity.
	 * @param string $policy Shared policy class.
	 * @return array Runtime-only WoodMart request fields.
	 */
	private function theme_search_query( $query, $policy ) {
		if ( ! class_exists( $policy ) || ! is_callable( array( $policy, 'canonical' ) ) ) {
			return $query;
		}
		$original   = (string) ( $query['query'] ?? '' );
		$canonical  = $policy::canonical( $original );
		$normalized = is_callable( array( $policy, 'normalize' ) ) ? $policy::normalize( $original ) : $original;
		if ( '' === $canonical || $canonical === $normalized ) {
			return $query;
		}
		$exact_sku_id = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $original ) ) : 0;
		if ( $exact_sku_id <= 0 ) {
			$query['query'] = $canonical;
		}

		return $query;
	}

	/**
	 * Prevent cached or theme-generated suggestions from bypassing catalog visibility.
	 *
	 * Category and blog suggestions have no product identity and remain untouched.
	 *
	 * @param array $suggestions WoodMart suggestions.
	 * @return array
	 */
	private function filter_public_product_suggestions( $suggestions ) {
		return array_values(
			array_filter(
				(array) $suggestions,
				static function ( $item ) {
					if ( ! is_array( $item ) ) {
						return true;
					}
					$product_id = isset( $item['_dg_product_id'] )
						? max( 0, (int) $item['_dg_product_id'] )
						: ( isset( $item['permalink'] ) ? max( 0, (int) url_to_postid( $item['permalink'] ) ) : 0 );
					$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
					if ( ! $product ) {
						return true;
					}

					return ! class_exists( 'Digitalogic_Storefront_Catalog' ) || Digitalogic_Storefront_Catalog::product_has_public_title( $product_id );
				}
			)
		);
	}

	public function allow_public_search_command( $requires_auth, $command, $payload, $transport ) {
		if ( $transport === 'websocket' && in_array( $command, $this->public_actions, true ) ) {
			return false;
		}

		return $requires_auth;
	}

	public function allow_public_ajax_search_action( $allowed, $command, $payload, $transport ) {
		// WooCommerce and theme request-local caches cannot be reused by a daemon.
		// Existing clients already retry rejected commands through authenticated HTTP.
		if ( $transport === 'websocket' && $command === 'woodmart_ajax_search' ) {
			return false;
		}
		if ( $transport === 'websocket' && in_array( $command, $this->public_actions, true ) ) {
			return true;
		}

		return $allowed;
	}
}
