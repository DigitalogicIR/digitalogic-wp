<?php
/**
 * Website projections over canonical customer activity; guest cookies stay local.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 adapter contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Current canonical wishlist readback bypasses stale provider caches.

/** Observe successful provider state without replacing Woodmart handlers. */
final class MobileWebsiteActivity {
	private const CLOUD_COOKIE = 'digitalogic_recent_account';

	/** Register only website-cookie adapters; native/PWA authority remains separate. */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'track' ), 30 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 120 );
		add_action( 'wp_logout', array( self::class, 'clearCookies' ), 10 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'privateResponse' ), 10, 3 );
		foreach ( array( 'woodmart_add_to_wishlist', 'woodmart_remove_from_wishlist', 'woodmart_remove_group_from_wishlist', 'woodmart_rename_wishlist_group', 'woodmart_save_wishlist_group', 'woodmart_move_products_from_wishlist' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( self::class, 'beforeWishlist' ), 1 );
		}
		add_action( 'shutdown', array( self::class, 'flushWishlist' ), 45 );
	}

	/** Register exact website endpoints, retaining the normal WordPress REST cookie policy. */
	public static function routes(): void {
		foreach ( array(
			'/account/recently-viewed' => array( 'GET,POST', 'history' ),
			'/account/events'          => array( 'GET', 'events' ),
		) as $route => $definition ) {
			register_rest_route(
				'digitalogic/v1',
				$route,
				array(
					'methods'             => $definition[0],
					'callback'            => array( self::class, $definition[1] ),
					'permission_callback' => array( self::class, 'permission' ),
				)
			);
		}
	}

	/**
	 * Prevent private snapshots and even permission failures from entering page caches.
	 *
	 * @param mixed $response Existing REST response.
	 * @param mixed $server REST server, unchanged.
	 * @param mixed $request Current request.
	 * @return mixed Existing response with private cache policy where applicable.
	 */
	public static function privateResponse( $response, $server, $request ) {
		unset( $server );
		if ( in_array( $request->get_route(), array( '/digitalogic/v1/account/recently-viewed', '/digitalogic/v1/account/events' ), true ) && is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		return $response;
	}

	/**
	 * Verify closed credential semantics without allowing a native bearer to become a WP session.
	 *
	 * @param int      $owner Current WordPress owner.
	 * @param string   $cookie Actual logged-in cookie.
	 * @param string   $nonce REST header nonce.
	 * @param bool     $has_bearer Whether any Authorization header was supplied.
	 * @param callable $cookie_owner Validator returning the current canonical cookie owner.
	 * @param callable $nonce_valid Validator for this exact cookie generation's REST nonce.
	 * @return bool Whether both canonical credentials agree.
	 */
	public static function credentialsAllowed( int $owner, string $cookie, string $nonce, bool $has_bearer, callable $cookie_owner, callable $nonce_valid ): bool {
		return $owner > 0 && '' !== $cookie && '' !== $nonce && ! $has_bearer && $owner === (int) $cookie_owner( $cookie ) && (bool) $nonce_valid( $nonce );
	}

	/**
	 * Capture exact website-cookie authority, never a client-supplied account ID.
	 *
	 * @param mixed $request Current REST request.
	 * @return array|\WP_Error Exact owner and revalidation closure.
	 */
	private static function context( $request ) {
		if ( ! MobileRecentViews::enabled() ) {
			return new \WP_Error( 'account_activity_unavailable', 'account_activity_unavailable', array( 'status' => 503 ) );
		}
		$owner = get_current_user_id();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Authenticated cookie bytes must reach WordPress unchanged.
		$cookie     = defined( 'LOGGED_IN_COOKIE' ) && is_string( $_COOKIE[ LOGGED_IN_COOKIE ] ?? null ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : '';
		$nonce      = (string) $request->get_header( 'x-wp-nonce' );
		$has_bearer = '' !== (string) $request->get_header( 'authorization' ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$authority  = static function () use ( $owner, $cookie, $nonce, $has_bearer ): bool {
			clean_user_cache( $owner );
			if ( get_current_user_id() !== $owner || get_user_meta( $owner, '_digitalogic_merged_into', true ) ) {
				return false;
			}
			return self::credentialsAllowed( $owner, $cookie, $nonce, $has_bearer, static fn( $value ) => wp_validate_auth_cookie( $value, 'logged_in' ), static fn( $value ) => wp_verify_nonce( $value, 'wp_rest' ) );
		};
		if ( ! $authority() ) {
			return new \WP_Error( 'website_session_required', 'website_session_required', array( 'status' => 401 ) );
		}
		return array(
			'owner'     => $owner,
			'authority' => $authority,
			'lock'      => 'dgl-site-events-' . substr( hash_hmac( 'sha256', $cookie, wp_salt( 'auth' ) ), 0, 40 ),
		);
	}

	/**
	 * Require WordPress's own cookie and nonce for every website read or write.
	 *
	 * @param mixed $request Current REST request.
	 * @return bool|\WP_Error Permission result.
	 */
	public static function permission( $request ) {
		$context = self::context( $request );
		return is_wp_error( $context ) ? $context : true;
	}

	/**
	 * Parse only a single visible product observation; client timestamps are not needed here.
	 *
	 * @param mixed $input JSON body.
	 * @return int|null Exact product ID or invalid input.
	 */
	public static function viewId( $input ): ?int {
		return is_array( $input ) && array_keys( $input ) === array( 'product_id' ) && is_int( $input['product_id'] ) && $input['product_id'] > 0 ? $input['product_id'] : null;
	}

	/**
	 * Read or increment canonical history after an actual visible-page browser observation.
	 *
	 * @param mixed $request Current REST request.
	 * @return \WP_REST_Response|\WP_Error Canonical private snapshot.
	 */
	public static function history( $request ) {
		$context = self::context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( 'POST' === $request->get_method() ) {
			$id = self::viewId( $request->get_json_params() );
			if ( null === $id ) {
				return new \WP_Error( 'invalid_history_view', 'invalid_history_view', array( 'status' => 400 ) );
			}
			if ( ! MobileIdentityStore::admit( 'site-history:' . $context['owner'], 120, 300 ) ) {
				return new \WP_Error( 'rate_limited', 'rate_limited', array( 'status' => 429 ) );
			}
			$data = MobileRecentViews::sync( $context['owner'], array( $id => time() ), $context['authority'] );
		} else {
			try {
				$data = MobileRecentViews::snapshot( MobileRecentViews::read( $context['owner'] ) );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'history_unavailable', 'history_unavailable', array( 'status' => 503 ) );
			}
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! ( $context['authority'] )() ) {
			return new \WP_Error( 'website_session_required', 'website_session_required', array( 'status' => 401 ) );
		}
		self::hydrate( $data['items'] );
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Bounded private polling under the exact captured website session.
	 *
	 * @param mixed $request Current REST request.
	 * @return \WP_REST_Response|\WP_Error Private invalidations only.
	 */
	public static function events( $request ) {
		$context = self::context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$cursor = $request->get_param( 'cursor' ) ?? '0';
		$wait   = $request->get_param( 'wait' ) ?? '20';
		if ( ! is_scalar( $cursor ) || ! preg_match( '/\A[0-9]{1,15}\z/D', (string) $cursor ) || ! is_scalar( $wait ) || ! preg_match( '/\A(?:[0-9]|1[0-9]|20)\z/D', (string) $wait ) ) {
			return new \WP_Error( 'invalid_account_events', 'invalid_account_events', array( 'status' => 400 ) );
		}
		if ( ! MobileIdentityStore::admit( 'site-events:' . $context['lock'], 120, 60 ) ) {
			return new \WP_Error( 'rate_limited', 'rate_limited', array( 'status' => 429 ) );
		}
		global $wpdb;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $context['lock'] ) ) ) {
			return new \WP_Error( 'account_events_busy', 'account_events_busy', array( 'status' => 409 ) );
		}
		try {
			$data = MobileAccountEvents::poll( (int) $cursor, (int) $wait, $context['authority'], $context['owner'] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$response = new \WP_REST_Response( $data, 200 );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $context['lock'] ) );
		}
	}

	/**
	 * Owner snapshots captured before provider AJAX handlers.
	 *
	 * @var array
	 */
	private static array $wishlist_before = array();

	/** Hydrate canonical rendering only; visible JavaScript records the view later. */
	public static function track(): void {
		$user_id = get_current_user_id();
		if ( ! MobileRecentViews::enabled() || is_admin() || wp_doing_ajax() || 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}
		if ( $user_id < 1 || get_user_meta( $user_id, '_digitalogic_merged_into', true ) ) {
			if ( ! empty( $_COOKIE[ self::CLOUD_COOKIE ] ) ) {
				self::clearCookies();
			}
			return;
		}
		$purpose = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '' ) ) );
		if ( str_contains( $purpose, 'prefetch' ) ) {
			return;
		}
		try {
			$data = MobileRecentViews::snapshot( MobileRecentViews::read( $user_id ) );
			self::hydrate( $data['items'] );
		} catch ( \Throwable $error ) {
			// A history outage must never prevent normal storefront navigation.
			return;
		}
	}

	/** Enqueue only on authenticated website pages, without sharing native credentials. */
	public static function enqueue(): void {
		$owner = get_current_user_id();
		if ( ! MobileRecentViews::enabled() || $owner < 1 || is_admin() || get_user_meta( $owner, '_digitalogic_merged_into', true ) ) {
			return;
		}
		wp_enqueue_script( 'digitalogic-account-activity', DIGITALOGIC_PLUGIN_URL . 'assets/js/storefront-account-activity.js', array(), DIGITALOGIC_VERSION, true );
		wp_localize_script(
			'digitalogic-account-activity',
			'DigitalogicAccountActivity',
			array(
				'historyUrl' => rest_url( 'digitalogic/v1/account/recently-viewed' ),
				'eventsUrl'  => rest_url( 'digitalogic/v1/account/events' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'productId'  => function_exists( 'is_product' ) && is_product() ? (int) get_queried_object_id() : 0,
				'channel'    => substr( hash_hmac( 'sha256', (string) wp_get_session_token(), wp_salt( 'nonce' ) ), 0, 32 ),
			)
		);
	}

	/**
	 * Mirror only the public snapshot, respecting opposite canonical cookie orderings.
	 *
	 * @param array $items Canonical newest-first public items.
	 * @return array Exact existing cookie values.
	 */
	public static function cookieValues( array $items ): array {
		$ids = array_values( array_unique( array_map( static fn( $row ) => (int) $row['product_id'], $items ) ) );
		return array(
			'woodmart_recently_viewed_products' => implode( '|', array_slice( $ids, 0, max( 1, min( 30, (int) apply_filters( 'woodmart_max_recently_viewed_products', 12 ) ) ) ) ),
			'woocommerce_recently_viewed'       => implode( '|', array_reverse( array_slice( $ids, 0, 15 ) ) ),
		);
	}

	/**
	 * Set session-only provider projections and a boolean shared-browser cleanup marker.
	 *
	 * @param array $items Canonical snapshot items.
	 */
	private static function hydrate( array $items ): void {
		$presentation = array_map( static fn( $id ) => array( 'product_id' => $id ), self::websiteProductIds( $items ) );
		foreach ( self::cookieValues( $presentation ) as $name => $value ) {
			self::cookie( $name, $value );
		}
		self::cookie( self::CLOUD_COOKIE, '1' );
	}

	/**
	 * Theme widgets use exact public Woo parents; cloud variation identity remains unchanged.
	 *
	 * @param array         $items Canonical newest-first snapshot.
	 * @param callable|null $lookup Canonical product lookup, injectable only for no-network fixtures.
	 * @param callable|null $is_public Public-product predicate for the same canonical catalog.
	 * @return array Newest-first unique website product IDs.
	 */
	public static function websiteProductIds( array $items, ?callable $lookup = null, ?callable $is_public = null ): array {
		$lookup    ??= static fn( $id ) => wc_get_product( $id );
		$catalog     = new \Digitalogic\Laravel\Http\Controllers\MobileCatalogController();
		$is_public ??= static fn( $id ) => $catalog->isPublicProductId( $id );
		$ids         = array();
		foreach ( $items as $row ) {
			$id      = (int) ( $row['product_id'] ?? 0 );
			$product = $id > 0 ? $lookup( $id ) : null;
			if ( ! $product || ! $is_public( $id ) ) {
				continue;
			}
			$website_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $id;
			if ( $website_id > 0 && $is_public( $website_id ) && ! in_array( $website_id, $ids, true ) ) {
				$ids[] = $website_id;
			}
		}
		return $ids;
	}

	/** Clear only a cloud-hydrated history on logout/expired identity, never ordinary guest history. */
	public static function clearCookies(): void {
		if ( empty( $_COOKIE[ self::CLOUD_COOKIE ] ) ) {
			return;
		}
		foreach ( array( 'woodmart_recently_viewed_products', 'woocommerce_recently_viewed', self::CLOUD_COOKIE ) as $name ) {
			self::cookie( $name, '' );
		}
	}

	/**
	 * Preserve the canonical provider cookie name and update PHP rendering immediately.
	 *
	 * @param string $name Fixed provider name.
	 * @param string $value Public product IDs owned by the current viewer.
	 */
	private static function cookie( string $name, string $value ): void {
		if ( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ?? '' ) ) === $value ) {
			return;
		}
		if ( ! headers_sent() ) {
			wc_setcookie( $name, $value, 0, is_ssl(), false );
		}
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Read all canonical wishlist groups without creating or changing any list.
	 *
	 * @param int $user_id Verified website owner.
	 * @return string|null Stable current product/group-membership hash.
	 */
	private static function wishlistRevision( int $user_id ): ?string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT p.wishlist_id,p.product_id FROM %i p JOIN %i w ON w.ID=p.wishlist_id WHERE w.user_id=%d ORDER BY p.wishlist_id,p.product_id', $wpdb->prefix . 'woodmart_wishlist_products', $wpdb->prefix . 'woodmart_wishlists', $user_id ), ARRAY_A );
		return is_array( $rows ) && '' === $wpdb->last_error ? hash( 'sha256', wp_json_encode( array_map( static fn( $row ) => array( (int) $row['wishlist_id'], (int) $row['product_id'] ), $rows ) ) ) : null;
	}

	/** Snapshot before a known provider AJAX mutation; its nonce policy remains untouched. */
	public static function beforeWishlist(): void {
		$user_id = get_current_user_id();
		if ( MobileRecentViews::enabled() && MobileWishlist::available() && $user_id > 0 ) {
			self::$wishlist_before[ $user_id ] = self::wishlistRevision( $user_id );
		}
	}

	/** Emit only after the canonical provider persisted a different wishlist. */
	public static function flushWishlist(): void {
		foreach ( self::$wishlist_before as $user_id => $before ) {
			$after = self::wishlistRevision( (int) $user_id );
			if ( null !== $before && null !== $after && ! hash_equals( $before, $after ) ) {
				MobileRecentViews::emit( (int) $user_id, 'favorites', 'favorites' );
			}
		}
		self::$wishlist_before = array();
	}
}
