<?php
/**
 * Native CatalogController adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This adapter reads indexed public data through cached(); canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Digitalogic\Laravel\Services\CatalogSearchPolicy;
use Digitalogic\Laravel\Services\MobileCatalogCache;

/**
 * Public catalog projection. No private pricing metadata, tokens or customer data.
 */
final class MobileCatalogController {

	/**
	 * Discover only configured app capabilities and canonical storefront data.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function bootstrap( Request $request ): Response {
		return $this->cached(
			$request,
			'bootstrap',
			array(),
			function ( array $publication ): array {
				$release         = $publication['release'];
				$update_manifest = $publication['enabled'] ? \Digitalogic\Laravel\Services\MobileRelease::manifestUrl() : '';
				$featured        = $this->productsData(
					self::normalizeQuery(
						array(
							'per_page'     => 10,
							'stock_status' => 'instock',
							'has_images'   => '1',
						)
					)
				);
				return array(
					'release'      => $release,
					'address_map'  => \Digitalogic\Laravel\Services\MobileAddresses::map_config(),
					'business'     => \Digitalogic\Laravel\Services\MobileStorefrontInfo::business(),
					'site'         => array(
						'name'                => get_bloginfo( 'name' ),
						'url'                 => home_url( '/' ),
						'locale'              => 'fa-IR',
						'direction'           => 'rtl',
						'currency'            => get_woocommerce_currency(),
						'currency_minor_unit' => wc_get_price_decimals(),
					),
					'endpoints'    => array(
						'links_resolve'            => home_url( '/app-api/links/resolve' ),
						'catalog'                  => home_url( '/app-api/products' ),
						'categories'               => home_url( '/app-api/categories' ),
						'cart'                     => home_url( '/app-api/cart' ),
						'cart_claim'               => home_url( '/app-api/cart/claim' ),
						'woo_cart'                 => rest_url( 'wc/store/v1/cart' ),
						'account'                  => wc_get_page_permalink( 'myaccount' ),
						'login'                    => wc_get_page_permalink( 'myaccount' ),
						'checkout'                 => home_url( '/app-api/checkout' ),
						'checkout_quote'           => home_url( '/app-api/checkout/quote' ),
						'checkout_operations'      => home_url( '/app-api/checkout/operations' ),
						'orders'                   => wc_get_account_endpoint_url( 'orders' ),
						'profile'                  => wc_get_account_endpoint_url( 'edit-account' ),
						'update_manifest'          => $update_manifest,
						'websocket'                => 'wss://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/wordpress-ws',
						'forms'                    => home_url( '/app-api/forms' ),
						'uploads'                  => home_url( '/app-api/uploads' ),
						'requests'                 => home_url( '/app-api/requests' ),
						'native_requests'          => home_url( '/app-api/account/requests' ),
						'otp_request'              => home_url( '/app-api/auth/otp/request' ),
						'otp_verify'               => home_url( '/app-api/auth/otp/verify' ),
						'native_register'          => home_url( '/app-api/auth/register' ),
						'google'                   => home_url( '/app-api/auth/google' ),
						'browser_session'          => home_url( '/app-api/auth/browser/session' ),
						'browser_google_challenge' => home_url( '/app-api/auth/browser/google/challenge' ),
						'phone_request'            => home_url( '/app-api/account/phone/request' ),
						'phone_verify'             => home_url( '/app-api/account/phone/verify' ),
						'google_reauth'            => home_url( '/app-api/account/reauth/google' ),
						'native_profile'           => home_url( '/app-api/account/profile' ),
						'device_report'            => home_url( '/app-api/account/device-report' ),
						'native_orders'            => home_url( '/app-api/account/orders' ),
						'native_favorites'         => home_url( '/app-api/account/favorites' ),
						'recently_viewed'          => home_url( '/app-api/account/recently-viewed' ),
						'account_events'           => home_url( '/app-api/account/events' ),
						'native_sessions'          => home_url( '/app-api/auth/sessions' ),
						'native_logout'            => home_url( '/app-api/auth/logout' ),
						'events'                   => rest_url( 'digitalogic/v1/events/stream' ),
						'freshness'                => rest_url( 'digitalogic/v1/events/search-freshness' ),
						'pcb'                      => home_url( '/سفارش-چاپ-برد-pcb/' ),
						'import'                   => home_url( '/import-of-electronic-products/' ),
					),
					'capabilities' => array(
						'catalog'                   => true,
						'product_models'            => true,
						'cart'                      => true,
						'cloud_cart'                => MobileCartController::enabled(),
						'browser_checkout'          => false,
						'native_cart_handoff'       => false,
						'native_checkout'           => MobileCheckoutController::enabled(),
						'native_auth'               => MobileIdentityController::otpEnabled(),
						'native_phone_verification' => MobileIdentityController::phoneEnabled(),
						'native_messages'           => \Digitalogic\Laravel\Services\MobileMessages::enabled(),
						'google_reauth_nonce'       => MobileIdentityController::googleEnabled() && '1' === get_option( 'digitalogic_mobile_google_challenge_schema' ),
						'native_address_locations'  => true,
						'account_merge'             => \Digitalogic\Laravel\Services\MobileAccountMerge::enabled(),
						'native_registration'       => \Digitalogic\Laravel\Services\MobileRegistrationStore::enabled(),
						'google_auth'               => MobileIdentityController::googleEnabled(),
						'browser_auth'              => \Digitalogic\Laravel\Services\MobileBrowserSession::enabled(),
						'browser_google'            => \Digitalogic\Laravel\Services\MobileBrowserSession::enabled() && MobileIdentityController::googleEnabled(),
						'web_otp'                   => \Digitalogic\Laravel\Services\MobileBrowserSession::enabled() && MobileIdentityController::otpEnabled() && \Digitalogic\Laravel\Services\MobileOtpSmsFormat::available( 'web_otp' ),
						'otp_autofill'              => false,
						'android_sms_retriever'     => MobileIdentityController::otpEnabled() && \Digitalogic\Laravel\Services\MobileOtpSmsFormat::available(),
						'telemetry'                 => \Digitalogic\Laravel\Services\MobileDeviceReport::enabled(),
						'cloud_favorites'           => \Digitalogic\Laravel\Services\MobileWishlist::enabled(),
						'recently_viewed'           => MobileIdentityController::enabled() && \Digitalogic\Laravel\Services\MobileRecentViews::enabled(),
						'account_events'            => \Digitalogic\Laravel\Services\MobileAccountEvents::enabled(),
						'native_forms'              => MobileRequestController::enabled(),
						'push_notifications'        => false,
						'websocket'                 => (bool) get_option( 'digitalogic_mobile_websocket', false ),
						'sse'                       => true,
						'self_update'               => '' !== $update_manifest,
					),
					'realtime'     => array(
						'protocol'           => 'digitalogic.shopper',
						'ping_interval'      => 25,
						'reconnect_interval' => 3,
					),
					'auth'         => array(
						'google_server_client_id' => \Digitalogic\Laravel\Services\MobileGoogleIdentity::audience(),
						'registration_method'     => 'phone',
					),
					'policy'       => array(
						'product_export'            => false,
						'telemetry_opt_in_required' => false,
						'checkout_online_required'  => true,
						'max_catalog_age_seconds'   => 60,
					),
					'categories'   => $this->categoriesData(),
					'carousels'    => \Digitalogic_Mobile_API::sanitize_merchandising( get_option( 'digitalogic_mobile_merchandising', array() ) ),
					'collections'  => array(
						array(
							'id'    => 'ready',
							'title' => 'موجود و آماده سفارش',
							'query' => array(
								'stock_status' => 'instock',
								'orderby'      => 'date',
							),
						),
						array(
							'id'    => 'popular',
							'title' => 'انتخاب‌های محبوب',
							'query' => array( 'orderby' => 'popularity' ),
						),
					),
					'featured'     => $featured['items'],
					'generated_at' => gmdate( 'c' ),
				);
			}
		);
	}

	/**
	 * Return published Woo categories and their canonical links.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function categories( Request $request ): Response {
		return $this->cached(
			$request,
			'categories',
			array(),
			fn() => array(
				'items'        => $this->categoriesData(),
				'generated_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Resolve only exact canonical public Woo permalinks, never an arbitrary remote URL.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function resolveLink( Request $request ): Response {
		$url    = $request->query( 'url' );
		$result = is_string( $url ) ? \Digitalogic\Laravel\Services\MobileStorefrontInfo::resolve( $url ) : null;
		if ( null === $result ) {
			return response()->json(
				array(
					'code'    => 'unsupported_link',
					'message' => 'این پیوند در برنامه پشتیبانی نمی‌شود.',
				),
				404,
				array(
					'Cache-Control'               => 'public, max-age=15',
					'Access-Control-Allow-Origin' => '*',
				)
			);
		}
		return $this->cached( $request, 'links', array( 'url' => $url ), static fn() => $result );
	}

	/**
	 * Return a bounded, paginated public catalog projection.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function products( Request $request ): Response {
		$query = self::normalizeQuery( $request->query() );
		return $this->cached( $request, 'products', $query, fn() => $this->productsData( $query ) );
	}

	/**
	 * Return the exact requested public product without guessing its identity.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @param string  $id Exact scoped identifier.
	 * @return Response Native adapter result.
	 */
	public function product( Request $request, string $id ): Response {
		return $this->cached(
			$request,
			'product',
			array( 'id' => (int) $id ),
			function () use ( $id ) {
				$product = wc_get_product( (int) $id );
				return $product && $this->isPublic( $product ) ? $this->project( $product, true ) : new \WP_Error( 'product_not_found', '', array( 'status' => 404 ) );
			}
		);
	}

	/**
	 * Page complete canonical model details, bound to one catalog revision.
	 *
	 * @param Request $request Public bounded query.
	 * @param string  $id Exact variable parent ID.
	 * @return Response Full model page or a definitive refresh requirement.
	 */
	public function models( Request $request, string $id ): Response {
		$query = self::normalizeModelQuery( $request->query() );
		if ( is_wp_error( $query ) ) {
			return $this->catalogError( $query );
		}
		$query['id'] = (int) $id;
		return $this->cached(
			$request,
			'models',
			$query,
			function () use ( $id, $query ) {
				$product = wc_get_product( (int) $id );
				if ( ! $product || ! $product->is_type( 'variable' ) || ! $this->isPublic( $product ) ) {
					return new \WP_Error( 'product_not_found', '', array( 'status' => 404 ) );
				}
				$children = $this->publicChildren( $product );
				$total    = count( $children );
				return array(
					'parent_id'  => (int) $id,
					'items'      => array_map( fn( $child ) => $this->project( $child, true ), array_slice( $children, ( $query['page'] - 1 ) * $query['per_page'], $query['per_page'] ) ),
					'pagination' => array(
						'page'        => $query['page'],
						'per_page'    => $query['per_page'],
						'total'       => $total,
						'total_pages' => (int) ceil( $total / $query['per_page'] ),
					),
				);
			}
		);
	}

	/**
	 * Reject malformed continuations instead of silently combining revisions.
	 *
	 * @param array $input Public model query.
	 * @return array|\WP_Error Bounded query.
	 */
	public static function normalizeModelQuery( array $input ) {
		foreach ( array( 'page', 'per_page' ) as $field ) {
			if ( isset( $input[ $field ] ) && ( ! is_scalar( $input[ $field ] ) || ! preg_match( '/^[1-9][0-9]{0,5}$/D', (string) $input[ $field ] ) ) ) {
				return new \WP_Error( 'invalid_model_query', '', array( 'status' => 400 ) );
			}
		}
		$page     = (int) ( $input['page'] ?? 1 );
		$revision = $input['revision'] ?? '';
		if ( ! is_string( $revision ) || ( '' !== $revision && ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) || ( $page > 1 && '' === $revision ) ) {
			return new \WP_Error( 'model_revision_required', '', array( 'status' => 400 ) );
		}
		return array(
			'page'     => $page,
			'per_page' => min( 50, (int) ( $input['per_page'] ?? 24 ) ),
			'revision' => $revision,
		);
	}

	/**
	 * Normalize before cache lookup and SQL; only documented filters have an effect.
	 *
	 * @param array $input Validated or pending native input.
	 * @return array Native adapter result.
	 */
	public static function normalizeQuery( array $input ): array {
		$input  = array_filter( $input, 'is_scalar' );
		$search = CatalogSearchPolicy::normalize( (string) ( $input['search'] ?? '' ) );
		$sort   = (string) ( $input['orderby'] ?? ( '' === $search ? 'date' : 'relevance' ) );
		$stock  = (string) ( $input['stock_status'] ?? '' );
		return array(
			'page'         => max( 1, min( 500, (int) ( $input['page'] ?? 1 ) ) ),
			'per_page'     => max( 1, min( 48, (int) ( $input['per_page'] ?? 24 ) ) ),
			'search'       => $search,
			'search_terms' => CatalogSearchPolicy::terms( $search ),
			'category'     => max( 0, (int) ( $input['category'] ?? 0 ) ),
			'orderby'      => in_array( $sort, array( 'date', 'price', 'popularity', 'title', 'relevance' ), true ) ? $sort : 'date',
			'order'        => strtoupper( (string) ( $input['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			'stock_status' => in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock : '',
			'has_images'   => in_array( (string) ( $input['has_images'] ?? '' ), array( '1', 'true' ), true ),
		);
	}

	/**
	 * Indexed lookup chooses a bounded page; WooCommerce remains price/identity authority.
	 *
	 * @param array $query Query.
	 * @return array Native adapter result.
	 */
	private function productsData( array $query ): array {
		global $wpdb;
		$lookup      = $wpdb->prefix . 'wc_product_meta_lookup';
		$where       = "p.post_type='product' AND p.post_status = 'publish' AND p.post_password = ''";
		$values      = array();
		$invalid_ids = class_exists( '\\Digitalogic_Storefront_Catalog' ) ? \Digitalogic_Storefront_Catalog::instance()->invalid_product_title_ids() : array();
		if ( ! empty( $invalid_ids ) ) {
			$where .= ' AND p.ID NOT IN (' . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';
		}
		$visibility = '' === $query['search'] ? 'exclude-from-catalog' : 'exclude-from-search';
		$where     .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} vr JOIN {$wpdb->term_taxonomy} vt ON vt.term_taxonomy_id=vr.term_taxonomy_id JOIN {$wpdb->terms} v ON v.term_id=vt.term_id WHERE vr.object_id=p.ID AND vt.taxonomy='product_visibility' AND v.slug=%s)";
		$values[]   = $visibility;
		if ( $query['has_images'] ) {
			$where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} image WHERE image.post_id=p.ID AND image.meta_key='_thumbnail_id' AND CAST(image.meta_value AS UNSIGNED)>0)";
		}
		if ( '' !== $query['search'] ) {
			$parent_matches = array();
			$parent_values  = array();
			$child_matches  = array();
			$child_values   = array();
			foreach ( $query['search_terms'] as $term ) {
				$like             = '%' . $wpdb->esc_like( $term ) . '%';
				$parent_matches[] = 'p.post_title LIKE %s';
				$parent_values[]  = $like;
				$parent_matches[] = 'l.sku LIKE %s';
				$parent_values[]  = $like;
				$child_matches[]  = 'variations.post_title LIKE %s';
				$child_values[]   = $like;
				$child_matches[]  = 'variation_lookup.sku LIKE %s';
				$child_values[]   = $like;
			}
			$invalid_variations = empty( $invalid_ids ) ? '' : ' AND variations.ID NOT IN (' . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';
			$where             .= ' AND ((' . implode( ' OR ', $parent_matches ) . ") OR EXISTS (
				SELECT 1
				FROM {$wpdb->posts} variations
				LEFT JOIN {$lookup} variation_lookup ON variation_lookup.product_id=variations.ID
				WHERE variations.post_parent=p.ID
					AND variations.post_type='product_variation'
					AND variations.post_status='publish'
					AND variations.post_password=''
					{$invalid_variations}
					AND (" . implode( ' OR ', $child_matches ) . ')))';
			$values             = array_merge( $values, $parent_values, $child_values );
		}
		if ( $query['category'] > 0 ) {
			$children = get_term_children( $query['category'], 'product_cat' );
			$ids      = array_merge( array( $query['category'] ), is_wp_error( $children ) ? array() : array_map( 'absint', $children ) );
			$ids      = implode( ',', array_map( 'absint', $ids ) );
			$where   .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} cr JOIN {$wpdb->term_taxonomy} ct ON ct.term_taxonomy_id=cr.term_taxonomy_id WHERE cr.object_id=p.ID AND ct.taxonomy='product_cat' AND ct.term_id IN ({$ids}))";
		}
		if ( '' !== $query['stock_status'] ) {
			$where   .= ' AND l.stock_status=%s';
			$values[] = $query['stock_status'];
		} elseif ( get_option( 'woocommerce_hide_out_of_stock_items' ) === 'yes' ) {
			$where .= " AND l.stock_status != 'outofstock'";
		}
		$sorts        = array(
			'date'       => 'p.post_date_gmt',
			'price'      => 'l.min_price',
			'popularity' => 'l.total_sales',
			'title'      => 'p.post_title',
		);
		$order_values = array();
		if ( 'relevance' === $query['orderby'] && '' !== $query['search'] ) {
			$exact        = array_fill( 0, count( $query['search_terms'] ), 'p.post_title=%s' );
			$prefix       = array_fill( 0, count( $query['search_terms'] ), 'p.post_title LIKE %s' );
			$order        = 'CASE WHEN l.sku=%s THEN 0 WHEN (' . implode( ' OR ', $exact ) . ") THEN 1 ELSE 2 END ASC, CASE WHEN l.stock_status IN ('instock','onbackorder') THEN 0 ELSE 1 END ASC, CASE WHEN (" . implode( ' OR ', $prefix ) . ') THEN 0 ELSE 1 END ASC, l.total_sales DESC, p.post_date_gmt DESC, p.ID DESC';
			$order_values = array_merge( array( $query['search'] ), $query['search_terms'], array_map( static fn( $term ) => $wpdb->esc_like( $term ) . '%', $query['search_terms'] ) );
		} else {
			$order = ( $sorts[ $query['orderby'] ] ?? $sorts['date'] ) . ' ' . $query['order'] . ', p.ID ' . $query['order'];
		}
		$from = "FROM {$wpdb->posts} p LEFT JOIN {$lookup} l ON l.product_id=p.ID WHERE {$where}";
     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only trusted table names and allowlisted sort fragments are interpolated.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $values ) );
		$args  = array_merge( $values, $order_values, array( $query['per_page'], ( $query['page'] - 1 ) * $query['per_page'] ) );
     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Filters and bounds are prepared; sort fields are allowlisted.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID {$from} ORDER BY {$order} LIMIT %d OFFSET %d", $args ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $this->isPublic( $product ) ) {
				$item                 = $this->project( $product, false );
				$matched_variation_id = $this->exactMatchedVariationId( $product, $query['search'] );
				if ( null !== $matched_variation_id ) {
					$item['matched_variation_id'] = $matched_variation_id;
				}
				$items[] = $item;
			}
		}
		$more = $query['page'] * $query['per_page'] < $total;
		return array(
			'items'        => $items,
			'pagination'   => array(
				'page'      => $query['page'],
				'per_page'  => $query['per_page'],
				'has_more'  => $more,
				'next_page' => $more ? $query['page'] + 1 : null,
				'total'     => $total,
			),
			'generated_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Project existing public Woo product-category records.
	 *
	 * @return array Native adapter result.
	 */
	private function categoriesData(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_values(
			array_map(
				function ( $term ): array {
						$image_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
						$url      = get_term_link( $term );
						$count    = class_exists( '\\Digitalogic_Storefront_Catalog' )
							? \Digitalogic_Storefront_Catalog::instance()->public_category_product_count( $term->term_id )
							: (int) $term->count;
						return array(
							'id'        => $term->term_id,
							'name'      => $term->name,
							'slug'      => $term->slug,
							'parent'    => $term->parent,
							'count'     => $count,
							'permalink' => is_wp_error( $url ) ? '' : $url,
							'image'     => $image_id > 0 ? $this->image( $image_id ) : null,
						);
				},
				$terms
			)
		);
	}

	/**
	 * Require a published, visible, unprotected product before public projection.
	 *
	 * @param mixed $product Product.
	 * @return bool Native adapter result.
	 */
	private function isPublic( $product ): bool {
		if ( $product->get_status() !== 'publish' || get_post_field( 'post_password', $product->get_id() ) !== '' || $product->get_catalog_visibility() === 'hidden' ) {
			return false;
		}
		if ( $product instanceof \WC_Product && class_exists( '\\Digitalogic_Storefront_Catalog' ) && ! \Digitalogic_Storefront_Catalog::product_has_public_title( $product ) ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return $parent && $this->isPublic( $parent );
		}
		return true;
	}

	/**
	 * Resolve only an exact variation SKU match under the canonical parent card.
	 *
	 * @param mixed  $product Canonical parent product.
	 * @param string $search Normalized customer query.
	 * @return int|null Exact public variation ID, or no preselection.
	 */
	private function exactMatchedVariationId( $product, string $search ): ?int {
		if ( '' === $search || ! $product->is_type( 'variable' ) ) {
			return null;
		}
		foreach ( (array) $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || ! $this->isPublic( $child ) ) {
				continue;
			}
			$sku = CatalogSearchPolicy::normalize( (string) $child->get_sku() );
			if ( '' !== $sku && hash_equals( $sku, $search ) ) {
				return (int) $child->get_id();
			}
		}

		return null;
	}

	/**
	 * One canonical projection is shared by listings, detail and durable customer carts.
	 *
	 * @param int $id Exact scoped identifier.
	 * @return array|null Native adapter result.
	 */
	public function publicProduct( int $id ): ?array {
		$product = wc_get_product( $id );
		return $product && $this->isPublic( $product ) ? $this->project( $product, false ) : null;
	}

	/**
	 * Validate a public identity without building a full catalog payload.
	 *
	 * @param int $id Exact product or variation ID.
	 * @return bool Whether current visibility permits a public reference.
	 */
	public function isPublicProductId( int $id ): bool {
		$product = wc_get_product( $id );
		return $product && $this->isPublic( $product );
	}

	/**
	 * Values are decimal strings in the store's currency minor unit, never inferred.
	 *
	 * @param mixed $value Value.
	 * @param int   $decimals Decimals.
	 * @return string|null Native adapter result.
	 */
	public static function minorUnits( $value, int $decimals ): ?string {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$decimal = number_format( (float) $value, max( 0, $decimals ), '.', '' );
		return str_replace( '.', '', $decimal );
	}

	/**
	 * Remove only the exact product-own SKU suffix from a customer-facing name.
	 *
	 * @param string $name Name.
	 * @param string $sku Sku.
	 * @return string Native adapter result.
	 */
	public static function displayName( string $name, string $sku ): string {
		return '' === $sku ? $name : trim( preg_replace( '/\s*\[\s*' . preg_quote( $sku, '/' ) . '\s*\]\s*$/u', '', $name ) );
	}

	/**
	 * Count the complete canonical public variation inventory, never a page slice.
	 *
	 * Unavailable models remain models. A failed inventory read stays unknown.
	 *
	 * @param mixed $product Canonical Woo product.
	 * @return int|null Full public child count for a variable product, or unknown.
	 */
	public function variationCount( $product ): ?int {
		if ( ! $product->is_type( 'variable' ) ) {
			return null;
		}
		try {
			return count( $this->publicChildren( $product ) );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Load each public canonical child once, independent of availability and page size.
	 *
	 * @param mixed $product Variable parent.
	 * @return array Woo children in stable numeric identity order.
	 * @throws \RuntimeException If canonical inventory cannot be read.
	 */
	private function publicChildren( $product ): array {
		$ids = $product->get_children();
		if ( ! is_array( $ids ) ) {
			throw new \RuntimeException( 'Canonical model inventory is unavailable.' );
		}
		$ids = array_unique( array_map( 'absint', $ids ) );
		sort( $ids, SORT_NUMERIC );
		$children = array();
		foreach ( $ids as $id ) {
			$child = wc_get_product( $id );
			if ( $child && $child->is_type( 'variation' ) && (int) $child->get_parent_id() === (int) $product->get_id() && $this->isPublic( $child ) ) {
				$children[] = $child;
			}
		}
		return $children;
	}

	/**
	 * Project only currently available public prices, without changing saved prices.
	 *
	 * @param mixed      $product Canonical Woo product.
	 * @param int        $minor Canonical currency precision.
	 * @param array|null $children Already verified public children, scoped to this projection.
	 * @return array Public amount fields; absent offers remain empty.
	 */
	public function publicPriceAmounts( $product, int $minor, ?array $children = null ): array {
		$empty = array(
			'price'         => '',
			'regular_price' => '',
			'sale_price'    => '',
			'price_range'   => null,
		);
		if ( ! $product->is_in_stock() ) {
			return $empty;
		}
		if ( ! $product->is_type( 'variable' ) && ( '' === $product->get_price() || ! is_numeric( $product->get_price() ) ) ) {
			return $empty;
		}
		$offer = $product;
		$range = null;
		if ( $product->is_type( 'variable' ) ) {
			$offer   = null;
			$amounts = array();
			foreach ( $children ?? $this->publicChildren( $product ) as $child ) {
				if ( ! $child->is_in_stock() ) {
					continue;
				}
				$price = $child->get_price();
				if ( '' === $price || ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 ) {
					continue;
				}
				$amounts[] = (float) $price;
				if ( null === $offer || (float) $price < (float) $offer->get_price() ) {
					$offer = $child;
				}
			}
			if ( null === $offer ) {
				return $empty;
			}
			$range = array(
				'min_amount' => self::minorUnits( min( $amounts ), $minor ),
				'max_amount' => self::minorUnits( max( $amounts ), $minor ),
			);
		}
		return array(
			'price'         => self::minorUnits( $offer->get_price(), $minor ),
			'regular_price' => self::minorUnits( $offer->get_regular_price(), $minor ),
			'sale_price'    => $offer->is_on_sale() ? ( self::minorUnits( $offer->get_sale_price(), $minor ) ?? '' ) : '',
			'price_range'   => $range,
		);
	}

	/**
	 * Project canonical Woo product fields without private or inferred commerce information.
	 *
	 * @param mixed $product Product.
	 * @param bool  $details Details.
	 * @return array Native adapter result.
	 */
	private function project( $product, bool $details ): array {
		$children   = $product->is_type( 'variable' ) ? $this->publicChildren( $product ) : null;
		$minor      = (int) wc_get_price_decimals();
		$amounts    = $this->publicPriceAmounts( $product, $minor, $children );
		$image_ids  = array_values( array_unique( array_filter( array_merge( array( $product->get_image_id() ), $details ? $product->get_gallery_image_ids() : array() ) ) ) );
		$attributes = array();
		foreach ( $details ? $product->get_attributes() : array() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() ) {
				continue;
			}
			$options      = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			$attributes[] = array(
				'id'        => $attribute->get_id(),
				'name'      => wc_attribute_label( $attribute->get_name() ),
				'slug'      => $attribute->get_name(),
				'variation' => (bool) $attribute->get_variation(),
				'options'   => is_wp_error( $options ) ? array() : array_values( $options ),
			);
		}
		$categories = get_the_terms( $product->get_id(), 'product_cat' );
		$categories = is_array( $categories ) ? $categories : array();
		$data       = array(
			'detail_level'         => $details ? 'full' : 'summary',
			'id'                   => $product->get_id(),
			'parent_id'            => $product->get_parent_id(),
			'sku'                  => $product->get_sku(),
			'name'                 => self::displayName( html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ), $product->get_sku() ),
			'type'                 => $product->get_type(),
			'permalink'            => $product->get_permalink(),
			'short_description'    => wp_kses_post( strip_shortcodes( $product->get_short_description() ) ),
			'description'          => $details ? wp_kses_post( strip_shortcodes( $product->get_description() ) ) : '',
			'prices'               => $amounts + array(
				'currency_code'       => get_woocommerce_currency(),
				'currency_minor_unit' => $minor,
				'currency_symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			),
			'is_in_stock'          => $product->is_in_stock(),
			'stock_status'         => $product->get_stock_status(),
			'stock_quantity'       => $product->get_stock_quantity(),
			'is_purchasable'       => $product->is_in_stock() && '' !== $amounts['price'] && $product->is_purchasable(),
			'on_sale'              => '' !== $amounts['sale_price'],
			'sold_individually'    => $product->is_sold_individually(),
			'images'               => array_map( fn( $id ) => $this->image( (int) $id ), $image_ids ),
			'attributes'           => $attributes,
			'categories'           => array_map(
				static fn( $term ) => array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				$categories
			),
			'variations'           => array(),
			'variation_count'      => null === $children ? null : count( $children ),
			'variation_attributes' => $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array(),
			'average_rating'       => $product->get_average_rating(),
			'review_count'         => $product->get_review_count(),
			'modified_at'          => $product->is_in_stock() && $product->get_date_modified() ? $product->get_date_modified()->date( 'c' ) : null,
		);
		if ( null !== $children ) {
			$data['models_endpoint'] = home_url( '/app-api/products/' . $product->get_id() . '/models' );
			$data['models_complete'] = 0 === count( $children );
		}
		if ( $details && $product->is_type( 'variable' ) ) {
			$data['variations_complete'] = count( $children ) <= 100;
			foreach ( array_slice( $children, 0, 100 ) as $child ) {
				$data['variations'][] = array(
					'id'             => $child->get_id(),
					'parent_id'      => $product->get_id(),
					'type'           => 'variation',
					'sku'            => $child->get_sku(),
					'name'           => self::displayName( $child->get_name(), $child->get_sku() ),
					'attributes'     => $child->get_variation_attributes(),
					'price'          => $child->is_in_stock() ? self::minorUnits( $child->get_price(), $minor ) : '',
					'is_in_stock'    => $child->is_in_stock(),
					'is_purchasable' => $child->is_in_stock() && '' !== $child->get_price() && $child->is_purchasable(),
					'stock_quantity' => $child->get_stock_quantity(),
					'image'          => $child->get_image_id() ? $this->image( $child->get_image_id() ) : null,
				);
			}
		}
		if ( $details ) {
			$data['related_ids'] = array_values(
				array_filter(
					array_map( 'absint', wc_get_related_products( $product->get_id(), 8 ) ),
					function ( $related_id ) {
						$related = wc_get_product( $related_id );

						return $related && $this->isPublic( $related );
					}
				)
			);
			$data['documents']   = class_exists( 'Digitalogic_Product_Resources' ) ? \Digitalogic_Product_Resources::instance()->get_documents( $product->get_id() ) : array();
		}
		return $data;
	}

	/**
	 * Expose staged sizes of an attachment assigned to the exact product.
	 *
	 * @param int $id Exact scoped identifier.
	 * @return array Native adapter result.
	 */
	private function image( int $id ): array {
		return array(
			'id'        => $id,
			'src'       => (string) wp_get_attachment_image_url( $id, 'full' ),
			'thumbnail' => (string) wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ),
			'medium'    => (string) wp_get_attachment_image_url( $id, 'medium_large' ),
			'srcset'    => (string) wp_get_attachment_image_srcset( $id, 'full' ),
			'alt'       => get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Apply bounded public caching and conditional ETags to public projections.
	 *
	 * @param Request  $request Bounded native HTTP request.
	 * @param string   $route Route.
	 * @param array    $query Query.
	 * @param callable $build Build.
	 * @return Response Native adapter result.
	 */
	private function cached( Request $request, string $route, array $query, callable $build ): Response {
		$publication = null;
		if ( 'bootstrap' === $route ) {
			try {
				$publication                   = \Digitalogic\Laravel\Services\MobileRelease::publication();
				$query['publication_revision'] = $publication['revision'];
			} catch ( \RuntimeException $error ) {
				return $this->catalogError( new \WP_Error( 'release_unavailable', '', array( 'status' => 503 ) ) );
			}
		}
		$generation = $this->generation();
		if ( is_wp_error( $generation ) ) {
			return $this->catalogError( $generation );
		}
		$revision = hash( 'sha256', wp_json_encode( array( 'models-1', $generation, get_woocommerce_currency(), wc_get_price_decimals() ) ) );
		if ( 'models' === $route && '' !== $query['revision'] && ! hash_equals( $revision, $query['revision'] ) ) {
			return $this->catalogError(
				new \WP_Error(
					'catalog_revision_changed',
					'',
					array(
						'status'   => 409,
						'reset'    => true,
						'revision' => $revision,
					)
				)
			);
		}
		$key     = 'catalog-' . md5( wp_json_encode( array( $route, $query, $generation, get_woocommerce_currency(), get_option( 'digitalogic_mobile_merchandising', array() ), get_option( 'digitalogic_mobile_native_auth', false ), get_option( 'digitalogic_mobile_google_android_client', '' ), get_option( 'digitalogic_mobile_websocket', false ), get_option( 'digitalogic_mobile_forms', false ), get_option( 'digitalogic_mobile_cloud_cart', false ), get_option( 'digitalogic_mobile_native_checkout', false ), get_option( 'digitalogic_mobile_registration_schema' ), get_option( 'digitalogic_mobile_woo_favorites' ), get_option( 'digitalogic_mobile_android_sms', array() ), get_option( 'digitalogic_mobile_otp_format_schema' ), get_option( 'digitalogic_mobile_device_reports', false ), get_option( 'digitalogic_mobile_device_report_schema' ), get_option( 'digitalogic_mobile_browser_auth', false ), get_option( 'digitalogic_mobile_webotp_sms', array() ), get_option( 'digitalogic_mobile_account_activity', false ), 'account-activity-1', 'browser-session-1', 'device-report-automatic-1', 'availability-12', 'public-title-1' ) ) );
		$private = get_current_user_id() > 0;
		$data    = $private ? null : MobileCatalogCache::get( $key );
		$hit     = is_array( $data );
		if ( ! $hit ) {
			try {
				$data = null === $publication ? $build() : $build( $publication );
			} catch ( \Throwable $error ) {
				return $this->catalogError( new \WP_Error( 'catalog_unavailable', '', array( 'status' => 503 ) ) );
			}
		}
		$after = $this->generation();
		if ( is_wp_error( $after ) || ! hash_equals( $generation, $after ) ) {
			return $this->catalogError(
				new \WP_Error(
					'catalog_changed_during_read',
					'',
					array(
						'status' => 503,
						'reset'  => true,
					)
				)
			);
		}
		if ( null !== $publication ) {
			try {
				$current_publication = \Digitalogic\Laravel\Services\MobileRelease::publication();
				if ( ! hash_equals( $publication['revision'], $current_publication['revision'] ) ) {
					return $this->catalogError( new \WP_Error( 'release_changed_during_read', '', array( 'status' => 503 ) ) );
				}
			} catch ( \RuntimeException $error ) {
				return $this->catalogError( new \WP_Error( 'release_unavailable', '', array( 'status' => 503 ) ) );
			}
		}
		if ( is_wp_error( $data ) ) {
			return $this->catalogError( $data );
		}
		$data['revision']          = $revision;
		$data['cache_ttl_seconds'] = MobileCatalogCache::TTL_SECONDS;
		if ( ! $hit && ! $private ) {
			MobileCatalogCache::put( $key, $data );
		}
		$response = response()->json(
			$data,
			200,
			array(
				'Cache-Control'                 => $private || 'bootstrap' === $route ? 'private, no-store' : 'public, max-age=0, must-revalidate',
				'X-Digitalogic-Cache'           => $hit ? 'HIT' : 'MISS',
				'X-Digitalogic-Revision'        => $revision,
				'X-Content-Type-Options'        => 'nosniff',
				'Access-Control-Expose-Headers' => 'ETag, X-Digitalogic-Cache, X-Digitalogic-Revision',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$response->setEtag( hash( 'sha256', $response->getContent() ), true );
		// Apache may append its transfer encoding to a weak validator. Compare
		// equivalent GET representations against the original semantic validator.
		$request->headers->set( 'If-None-Match', preg_replace( '/-(?:gzip|br)(?=")/', '', $request->headers->get( 'If-None-Match', '' ) ) );
		$response->isNotModified( $request );
		return $response;
	}

	/** Read the committed canonical generation; a failure must never share a fallback cache key. */
	private function generation() {
		$generation = class_exists( 'Digitalogic_Report_Engine' ) ? \Digitalogic_Report_Engine::instance()->current_projection_generation() : null;
		return is_string( $generation ) && '' !== $generation ? $generation : new \WP_Error( 'catalog_generation_unavailable', '', array( 'status' => 503 ) );
	}

	/**
	 * Public errors are never cached and expose no provider exception detail.
	 *
	 * @param \WP_Error $error Bounded public error.
	 * @return Response Error response.
	 */
	private function catalogError( \WP_Error $error ): Response {
		$details = (array) $error->get_error_data();
		$status  = (int) ( $details['status'] ?? 503 );
		unset( $details['status'] );
		return response()->json(
			array( 'code' => $error->get_error_code() ) + $details,
			$status,
			array(
				'Cache-Control' => 'no-store',
				'Retry-After'   => '1',
			)
		);
	}
}
