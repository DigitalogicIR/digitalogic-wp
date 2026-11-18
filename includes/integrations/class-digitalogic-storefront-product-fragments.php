<?php
/**
 * Bounded, session-aware reads of canonical WooCommerce product cards.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Refresh existing cards without querying or rendering an entire archive. */
final class Digitalogic_Storefront_Product_Fragments {

	public const ACTION  = 'digitalogic_product_fragments';
	public const MAX_IDS = 20;

	/** WC AJAX keeps frontend hooks and the caller's WordPress/Woo session. */
	public static function init(): void {
		add_action( 'wc_ajax_' . self::ACTION, array( self::class, 'serve' ) );
	}

	/** Public read only; never route this through the anonymous WS process. */
	public static function serve(): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Vary: Cookie', false );
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Exact method comparison rejects every other value without transforming it.
			wp_send_json_error( array( 'code' => 'digitalogic_fragments_method' ), 405 );
			return;
		}
		if ( ! self::same_origin( $_SERVER, home_url( '/' ) ) ) {
			wp_send_json_error( array( 'code' => 'digitalogic_fragments_origin' ), 403 );
			return;
		}
		// No nonce: this bounded GET reads only already-public products.
		$result = self::read( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error( array( 'code' => $result->get_error_code() ), (int) ( $data['status'] ?? 500 ) );
			return;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Reject cross-origin browser requests, including sibling subdomains.
	 *
	 * @param array  $server Request server headers.
	 * @param string $home   Canonical site URL.
	 */
	public static function same_origin( array $server, string $home ): bool {
		$site = strtolower( (string) ( $server['HTTP_SEC_FETCH_SITE'] ?? '' ) );
		if ( '' !== $site && ! in_array( $site, array( 'same-origin', 'none' ), true ) ) {
			return false;
		}
		if ( empty( $server['HTTP_ORIGIN'] ) ) {
			return true;
		}
		$origin = wp_parse_url( (string) $server['HTTP_ORIGIN'] );
		$target = wp_parse_url( $home );
		foreach ( array( &$origin, &$target ) as &$parts ) {
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return false;
			}
			$parts = array( strtolower( $parts['scheme'] ), strtolower( $parts['host'] ), (int) ( $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 ) ) );
		}
		unset( $parts );
		return $origin === $target;
	}

	/**
	 * Exact positive Woo IDs only; reject malformed/oversized batches outright.
	 *
	 * @param mixed $raw Untrusted requested IDs.
	 * @return array|WP_Error Exact IDs or invalid-input error.
	 */
	public static function ids( $raw ) {
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > self::MAX_IDS ) {
			return new WP_Error( 'digitalogic_fragments_ids', 'Supply 1 to 20 exact WooCommerce product IDs.', array( 'status' => 400 ) );
		}
		$ids = array();
		foreach ( $raw as $value ) {
			if ( ! ( is_int( $value ) || is_string( $value ) ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $value ) || (string) (int) $value !== (string) $value ) {
				return new WP_Error( 'digitalogic_fragments_ids', 'Invalid WooCommerce product ID.', array( 'status' => 400 ) );
			}
			$ids[] = (int) $value;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Return canonical card HTML keyed by exact parent ID, and omitted IDs.
	 * No prices, stock, identities, holds or business records are written here.
	 * Callers must reconcile archive membership/order separately when necessary.
	 *
	 * @param array $payload Requested exact IDs and archive visibility context.
	 * @return array|WP_Error Cards from one stable generation, or a retryable error.
	 */
	public static function read( array $payload ) {
		$ids = self::ids( $payload['ids'] ?? null );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		if ( ! function_exists( 'wc_get_template_part' ) || ! function_exists( 'wc_setup_loop' ) ) {
			return new WP_Error( 'digitalogic_fragments_unavailable', 'WooCommerce rendering is unavailable.', array( 'status' => 503 ) );
		}
		$context = $payload['context'] ?? 'catalog';
		if ( ! in_array( $context, array( 'catalog', 'search' ), true ) ) {
			return new WP_Error( 'digitalogic_fragments_context', 'Invalid card context.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'Digitalogic_Report_Engine' ) ) {
			return new WP_Error( 'digitalogic_fragments_generation_unavailable', 'The catalog generation is unavailable.', array( 'status' => 503 ) );
		}
		$generation = Digitalogic_Report_Engine::instance()->current_projection_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}
		$names = array( 'post', 'product', 'woocommerce_loop', 'wp_query', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$saved = array();
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$saved[ $name ] = $GLOBALS[ $name ];
			}
		}
		$cards   = array();
		$removed = array();
		try {
			$GLOBALS['wp_query']            = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Temporary canonical visibility context, restored in finally.
			$GLOBALS['wp_query']->is_search = 'search' === $context;
			wc_setup_loop(
				array(
					'name'         => 'digitalogic_fragments',
					'is_shortcode' => false,
					'is_search'    => 'search' === $context,
					'total'        => count( $ids ),
					'loop'         => 0,
				)
			);
			foreach ( $ids as $id ) {
				$post    = get_post( $id );
				$product = wc_get_product( $id );
				if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status || ! empty( $post->post_password ) || ! $product || ! $product->is_visible() ) {
					$removed[] = $id;
					continue;
				}
				$GLOBALS['post']    = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Canonical Woo templates require the exact current post; restored in finally.
				$GLOBALS['product'] = $product;
				setup_postdata( $post );
				$level = ob_get_level();
				ob_start();
				try {
					// Theme overrides, role pricing, availability and price-date filters remain authoritative.
					wc_get_template_part( 'content', 'product' );
					$html = ob_get_clean();
				} finally {
					while ( ob_get_level() > $level ) {
						ob_end_clean();
					}
				}
				if ( '' === trim( $html ) ) {
					$removed[] = $id;
				} else {
					$cards[ (string) $id ] = $html;
				}
			}
		} finally {
			foreach ( $names as $name ) {
				if ( array_key_exists( $name, $saved ) ) {
					$GLOBALS[ $name ] = $saved[ $name ];
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
		}
		$after = Digitalogic_Report_Engine::instance()->current_projection_generation();
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( ! hash_equals( $generation, $after ) ) {
			return new WP_Error( 'digitalogic_fragments_generation_changed', 'The catalog changed during rendering; retry.', array( 'status' => 409 ) );
		}
		return array(
			'cards'      => (object) $cards,
			'removed'    => $removed,
			'ids'        => $ids,
			'generation' => $generation,
		);
	}
}
