<?php
/**
 * Keep empty or non-catalog product categories off the public storefront.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Storefront_Catalog {
	private const INVALID_TITLE_CACHE_KEY   = 'invalid-product-titles-v1';
	private const INVALID_TITLE_CACHE_GROUP = 'digitalogic-storefront-catalog';
	private const INVALID_TITLE_LOCAL_TTL_NS = 1000000000;

	private static $instance = null;

	/**
	 * Visible category IDs for the current request.
	 *
	 * @var array<int, true>|null
	 */
	private $visible_category_ids = null;

	/**
	 * Direct public product counts keyed by product-category ID.
	 *
	 * @var array<int, int>|null
	 */
	private $visible_category_counts = null;

	/**
	 * Published product and variation IDs whose stored title is not customer-visible.
	 *
	 * @var int[]|null
	 */
	private $invalid_product_title_ids = null;

	/**
	 * Monotonic nanoseconds for the request-local title projection.
	 *
	 * A WebSocket worker can serve many commands in one PHP process, so this
	 * memo must not outlive an external product repair indefinitely.
	 *
	 * @var int
	 */
	private $invalid_product_title_ids_loaded_at = 0;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'get_terms_args', array( $this, 'force_hide_empty_product_categories' ), 20, 2 );
		add_filter( 'get_terms', array( $this, 'filter_invisible_product_categories' ), 20, 4 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_invisible_category_menu_items' ), 20, 2 );
		add_filter( 'woocommerce_product_subcategories_args', array( $this, 'force_hide_empty_product_categories' ), 20, 1 );
		add_filter( 'woocommerce_product_categories_widget_args', array( $this, 'force_hide_empty_product_categories' ), 20, 1 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'filter_product_visibility' ), 20, 2 );
		add_filter( 'woocommerce_product_object_query_args', array( $this, 'filter_product_object_query_args' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'filter_public_product_query' ), 20 );
		add_filter( 'posts_clauses', array( $this, 'filter_public_product_clauses' ), 30, 2 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'filter_store_api_single_product' ), 20, 3 );
		add_action( 'save_post_product', array( $this, 'clear_public_title_cache' ) );
		add_action( 'save_post_product_variation', array( $this, 'clear_public_title_cache' ) );
		add_action( 'deleted_post', array( $this, 'clear_public_title_cache' ) );
		add_action( 'trashed_post', array( $this, 'clear_public_title_cache' ) );
		add_action( 'untrashed_post', array( $this, 'clear_public_title_cache' ) );
	}

	/**
	 * Require at least one customer-visible character in a product title.
	 *
	 * The predicate mirrors the native client. It never changes stored content;
	 * it only prevents empty titles, Unicode dashes, whitespace and formatting
	 * controls from becoming public catalog records.
	 *
	 * @param mixed $title Stored product title.
	 * @return bool
	 */
	public static function has_public_product_title( $title ) {
		if ( ! is_scalar( $title ) ) {
			return false;
		}

		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( 1 !== preg_match( '//u', $title ) ) {
			return false;
		}

		return 1 !== preg_match( '/^[\p{Dash}\p{White_Space}\p{Cf}]*$/u', $title );
	}

	/**
	 * Check the raw stored title for an exact Woo product identity.
	 *
	 * @param int|object $product Product ID or Woo product object.
	 * @return bool
	 */
	public static function product_has_public_title( $product ) {
		$product_id     = is_object( $product ) && is_callable( array( $product, 'get_id' ) )
			? (int) $product->get_id()
			: absint( $product );
		if ( $product_id <= 0 ) {
			return false;
		}

		$post = get_post( $product_id );
		if ( ! $post ) {
			return false;
		}

		return self::has_public_product_title( get_post_field( 'post_title', $product_id, 'raw' ) );
	}

	/**
	 * Return the closed set of published products and variations with invalid titles.
	 *
	 * @return int[]
	 */
	public function invalid_product_title_ids() {
		$now = hrtime( true );
		if ( null !== $this->invalid_product_title_ids && ( $now - $this->invalid_product_title_ids_loaded_at ) < self::INVALID_TITLE_LOCAL_TTL_NS ) {
			return $this->invalid_product_title_ids;
		}
		$this->invalid_product_title_ids           = null;
		$this->invalid_product_title_ids_loaded_at = 0;

		$external_cache = ! function_exists( 'wp_using_ext_object_cache' ) || wp_using_ext_object_cache();
		$found          = false;
		$cached         = $external_cache ? wp_cache_get( self::INVALID_TITLE_CACHE_KEY, self::INVALID_TITLE_CACHE_GROUP, true, $found ) : false;
		if ( $external_cache && $found && is_array( $cached ) ) {
			$this->invalid_product_title_ids           = array_values( array_unique( array_filter( array_map( 'absint', $cached ) ) ) );
			$this->invalid_product_title_ids_loaded_at = $now;

			return $this->invalid_product_title_ids;
		}

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Trusted WordPress table; bounded result is object-cached below.
			"SELECT ID, post_title
			FROM {$wpdb->posts}
			WHERE post_type IN ('product','product_variation')
				AND post_status = 'publish'",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress table name and no request input.

		$this->invalid_product_title_ids = array();
		foreach ( (array) $rows as $row ) {
			if ( ! self::has_public_product_title( $row['post_title'] ?? '' ) ) {
				$this->invalid_product_title_ids[] = absint( $row['ID'] ?? 0 );
			}
		}
		$this->invalid_product_title_ids = array_values( array_unique( array_filter( $this->invalid_product_title_ids ) ) );
		$this->invalid_product_title_ids_loaded_at = $now;
		wp_cache_set( self::INVALID_TITLE_CACHE_KEY, $this->invalid_product_title_ids, self::INVALID_TITLE_CACHE_GROUP, 300 );

		return $this->invalid_product_title_ids;
	}

	/**
	 * Invalidate the public-title projection after a product lifecycle change.
	 *
	 * @return void
	 */
	public function clear_public_title_cache() {
		$this->invalid_product_title_ids           = null;
		$this->invalid_product_title_ids_loaded_at = 0;
		$this->visible_category_ids                = null;
		$this->visible_category_counts             = null;
		wp_cache_delete( self::INVALID_TITLE_CACHE_KEY, self::INVALID_TITLE_CACHE_GROUP );
	}

	/**
	 * Prevent an invalid stored title from passing Woo's public visibility check.
	 *
	 * @param bool $visible Existing Woo visibility result.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function filter_product_visibility( $visible, $product_id ) {
		if ( ! $visible || ! $this->is_public_storefront_request() ) {
			return (bool) $visible;
		}

		return self::product_has_public_title( $product_id );
	}

	/**
	 * Apply the same exclusion to public wc_get_products() consumers.
	 *
	 * @param array $query_args Woo product query arguments.
	 * @param mixed $query      Optional query object.
	 * @return array
	 */
	public function filter_product_object_query_args( $query_args, $query = null ) {
		unset( $query );
		if ( ! is_array( $query_args ) || ! $this->is_public_storefront_request() ) {
			return $query_args;
		}

		$query_args['exclude'] = array_values(
			array_unique(
				array_merge(
					array_map( 'absint', (array) ( $query_args['exclude'] ?? array() ) ),
					$this->invalid_product_title_ids()
				)
			)
		);

		return $query_args;
	}

	/**
	 * Hide invalid products from public WordPress, Woo, WoodMart and Store API queries.
	 *
	 * Store API's custom `search` query is upgraded to the same bounded,
	 * separator-insensitive policy as the native catalog. A matching variation
	 * exposes its canonical parent product; no fuzzy identity mapping is used.
	 *
	 * @param WP_Query $query WordPress product query.
	 * @return void
	 */
	public function filter_public_product_query( $query ) {
		if ( ! is_object( $query ) || ! is_callable( array( $query, 'get' ) ) || ! is_callable( array( $query, 'set' ) ) || ! $this->is_public_storefront_request() || ! $this->is_product_query( $query ) ) {
			return;
		}

		$excluded = array_values(
			array_unique(
				array_merge(
					array_map( 'absint', (array) $query->get( 'post__not_in' ) ),
					$this->invalid_product_title_ids()
				)
			)
		);
		$query->set( 'post__not_in', $excluded );

		$store_search = $query->get( 'search' );
		if ( ! is_scalar( $store_search ) || '' === trim( (string) $store_search ) ) {
			return;
		}

		$candidates = $this->store_api_search_product_ids( (string) $store_search, 500 );
		if ( null === $candidates ) {
			return;
		}

		$current_constraint = (array) $query->get( 'post__in' );
		$current            = array_values( array_map( 'absint', $current_constraint ) );
		if ( ! empty( $current_constraint ) ) {
			$candidates = array_values( array_intersect( $current, $candidates ) );
		}
		$query->set( 'search', '' );
		$query->set( 'post__in', empty( $candidates ) ? array( 0 ) : $candidates );
	}

	/**
	 * Enforce invalid-title exclusion after Woo has constructed include queries.
	 *
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query WordPress query.
	 * @return array
	 */
	public function filter_public_product_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $this->is_public_storefront_request() || ! $this->is_product_query( $query ) ) {
			return $clauses;
		}

		$invalid_ids = $this->invalid_product_title_ids();
		if ( empty( $invalid_ids ) ) {
			return $clauses;
		}

		global $wpdb;
		$clauses['where'] = (string) ( $clauses['where'] ?? '' ) . " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';

		return $clauses;
	}

	/**
	 * Stop Store API single-product routes before direct lookups can expose a record.
	 *
	 * @param mixed           $response Existing response or error.
	 * @param array           $handler Route callback handler.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function filter_store_api_single_product( $response, $handler, $request ) {
		unset( $handler );
		if ( null !== $response || ! is_object( $request ) || ! is_callable( array( $request, 'get_route' ) ) || ! is_callable( array( $request, 'get_method' ) ) ) {
			return $response;
		}
		if ( ! in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD' ), true ) || 1 !== preg_match( '#^/wc/store(?:/v1)?/products/[^/]+/?$#', (string) $request->get_route() ) ) {
			return $response;
		}

		$product_id = absint( $request->get_param( 'id' ) );
		if ( $product_id <= 0 ) {
			$slug       = $request->get_param( 'slug' );
			$post       = is_scalar( $slug ) ? get_page_by_path( sanitize_title_for_query( (string) $slug ), OBJECT, array( 'product', 'product_variation' ) ) : null;
			$product_id = is_object( $post ) ? absint( $post->ID ?? 0 ) : 0;
		}
		if ( $product_id > 0 && ! self::product_has_public_title( $product_id ) ) {
			return new WP_Error(
				'woocommerce_rest_product_invalid_id',
				__( 'Invalid product ID.', 'woocommerce' ),
				array( 'status' => 404 )
			);
		}

		return $response;
	}

	/**
	 * Resolve bounded Store API search candidates without changing product identity.
	 *
	 * A null result means the candidate set exceeded the safety bound and the
	 * caller must preserve Woo's original search behavior.
	 *
	 * @param string $search Search input.
	 * @param int    $limit Maximum candidate count.
	 * @return int[]|null
	 */
	private function store_api_search_product_ids( $search, $limit ) {
		$policy = '\\Digitalogic\\Laravel\\Services\\CatalogSearchPolicy';
		$terms  = class_exists( $policy ) ? $policy::terms( (string) $search ) : array( trim( (string) $search ) );
		$terms  = array_values( array_filter( array_unique( array_map( 'strval', $terms ) ) ) );
		if ( empty( $terms ) ) {
			return array();
		}

		global $wpdb;
		$lookup        = $wpdb->prefix . 'wc_product_meta_lookup';
		$invalid_ids   = $this->invalid_product_title_ids();
		$invalid_sql   = empty( $invalid_ids ) ? '' : ' AND products.ID NOT IN (' . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';
		$variation_sql = empty( $invalid_ids ) ? '' : ' AND variations.ID NOT IN (' . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';
		$parent_match  = array();
		$parent_values = array();
		$child_match   = array();
		$child_values  = array();
		foreach ( $terms as $term ) {
			$like            = '%' . $wpdb->esc_like( $term ) . '%';
			$parent_match[]  = 'products.post_title LIKE %s';
			$parent_values[] = $like;
			$parent_match[]  = 'product_lookup.sku LIKE %s';
			$parent_values[] = $like;
			$child_match[]   = 'variations.post_title LIKE %s';
			$child_values[]  = $like;
			$child_match[]   = 'variation_lookup.sku LIKE %s';
			$child_values[]  = $like;
		}
		$values = array_merge( $parent_values, $child_values, array( max( 1, $limit ) + 1 ) );
		$sql    = "SELECT DISTINCT products.ID
			FROM {$wpdb->posts} products
			LEFT JOIN {$lookup} product_lookup ON product_lookup.product_id = products.ID
			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				AND products.post_password = ''
				{$invalid_sql}
				AND ((" . implode( ' OR ', $parent_match ) . ")
					OR EXISTS (
						SELECT 1
						FROM {$wpdb->posts} variations
						LEFT JOIN {$lookup} variation_lookup ON variation_lookup.product_id = variations.ID
						WHERE variations.post_parent = products.ID
							AND variations.post_type = 'product_variation'
							AND variations.post_status = 'publish'
							AND variations.post_password = ''
							{$variation_sql}
							AND (" . implode( ' OR ', $child_match ) . ')
					)
				)
			ORDER BY products.ID ASC
			LIMIT %d';
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $values ) ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed SQL plus integer IDs; all customer values are prepared.

		return count( $ids ) > $limit ? null : $ids;
	}

	/**
	 * Determine whether a WordPress query can return public products.
	 *
	 * @param WP_Query $query WordPress query.
	 * @return bool
	 */
	private function is_product_query( $query ) {
		if ( ! is_object( $query ) || ! is_callable( array( $query, 'get' ) ) ) {
			return false;
		}

		$post_types = array_filter( (array) $query->get( 'post_type' ), 'is_string' );
		if ( array_intersect( array( 'product', 'product_variation' ), $post_types ) ) {
			return true;
		}
		if ( 'product_query' === $query->get( 'wc_query' ) || '' !== (string) $query->get( 'product' ) || '' !== (string) $query->get( 'product_cat' ) ) {
			return true;
		}

		return is_callable( array( $query, 'is_post_type_archive' ) ) && $query->is_post_type_archive( 'product' );
	}

	/**
	 * Make WooCommerce and page-builder category queries omit empty terms.
	 *
	 * @param array $args Query arguments.
	 * @param array $taxonomies Requested taxonomies.
	 * @return array
	 */
	public function force_hide_empty_product_categories( $args, $taxonomies = array( 'product_cat' ) ) {
		if ( ! is_array( $args ) || ! $this->is_public_storefront_request() ) {
			return $args;
		}

		$taxonomies = array_filter( (array) $taxonomies, 'is_string' );
		if ( ! empty( $args['object_ids'] ) || 'all_with_object_id' === ( $args['fields'] ?? '' ) || count( $taxonomies ) > 1 ) {
			return $args;
		}
		if ( in_array( 'product_cat', $taxonomies, true ) || 'product_cat' === ( $args['taxonomy'] ?? '' ) ) {
			$args['hide_empty'] = true;
		}

		return $args;
	}

	/**
	 * Remove terms whose products are not visible in the shop catalog.
	 *
	 * WordPress term counts include products hidden from the catalog. A single
	 * SQL projection is used here so an Elementor category carousel does not
	 * create an N+1 product query for every term.
	 *
	 * @param mixed $terms Term-query result.
	 * @param array $taxonomies Requested taxonomies.
	 * @param array $args Query arguments.
	 * @param mixed $term_query Term query object.
	 * @return mixed
	 */
	public function filter_invisible_product_categories( $terms, $taxonomies, $args, $term_query = null ) {
		unset( $term_query );

		if ( ! is_array( $terms ) || ! $this->is_public_storefront_request() ) {
			return $terms;
		}
		if ( ! in_array( 'product_cat', (array) $taxonomies, true ) ) {
			return $terms;
		}
		// Relationship priming includes product_type and must preserve all terms,
		// including categories hidden from display and zero-count type terms.
		if ( ! empty( $args['object_ids'] ) || 'all_with_object_id' === ( $args['fields'] ?? '' ) || count( (array) $taxonomies ) > 1 ) {
			return $terms;
		}
		if ( in_array( $args['fields'] ?? 'all', array( 'count', 'names', 'slugs', 'id=>name', 'id=>slug' ), true ) ) {
			return $terms;
		}

		$visible_ids = $this->get_visible_category_ids();
		if ( empty( $visible_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $visible_ids ) {
					$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;

					return $term_id > 0 && isset( $visible_ids[ $term_id ] );
				}
			)
		);
	}

	/**
	 * Whether a product category has a visible product in its subtree.
	 *
	 * @param int $term_id Product category term ID.
	 * @return bool
	 */
	public function is_category_visible( $term_id ) {
		return isset( $this->get_visible_category_ids()[ (int) $term_id ] );
	}

	/**
	 * Return the direct public product count for a category.
	 *
	 * WordPress term counts include public-invalid placeholder records, so they
	 * cannot be projected through the mobile/customer contract unchanged.
	 *
	 * @param int $term_id Product-category ID.
	 * @return int
	 */
	public function public_category_product_count( $term_id ) {
		$term_id = absint( $term_id );
		if ( null === $this->visible_category_counts ) {
			$this->visible_category_counts = array();
			global $wpdb;
			$stock_join  = '';
			$stock_where = '';
			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ) && isset( $wpdb->wc_product_meta_lookup ) ) {
				$stock_join  = " INNER JOIN {$wpdb->wc_product_meta_lookup} product_lookup ON product_lookup.product_id = products.ID";
				$stock_where = " AND product_lookup.stock_status = 'instock'";
			}
			$invalid_ids = $this->invalid_product_title_ids();
			$title_where = empty( $invalid_ids ) ? '' : ' AND products.ID NOT IN (' . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';
			$rows        = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One request-local aggregate over trusted WordPress tables.
				"SELECT category_taxonomy.term_id, COUNT(DISTINCT products.ID) AS product_count
				FROM {$wpdb->posts} products
				INNER JOIN {$wpdb->term_relationships} category_relationship
					ON category_relationship.object_id = products.ID
				INNER JOIN {$wpdb->term_taxonomy} category_taxonomy
					ON category_taxonomy.term_taxonomy_id = category_relationship.term_taxonomy_id
					AND category_taxonomy.taxonomy = 'product_cat'
				{$stock_join}
				WHERE products.post_type = 'product'
					AND products.post_status = 'publish'
					{$stock_where}
					{$title_where}
					AND NOT EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} visibility_relationship
						INNER JOIN {$wpdb->term_taxonomy} visibility_taxonomy
							ON visibility_taxonomy.term_taxonomy_id = visibility_relationship.term_taxonomy_id
							AND visibility_taxonomy.taxonomy = 'product_visibility'
						INNER JOIN {$wpdb->terms} visibility_term
							ON visibility_term.term_id = visibility_taxonomy.term_id
						WHERE visibility_relationship.object_id = products.ID
							AND visibility_term.slug = 'exclude-from-catalog'
					)
				GROUP BY category_taxonomy.term_id",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress table names and integer-only ID list.
			foreach ( (array) $rows as $row ) {
				$id = absint( $row['term_id'] ?? 0 );
				if ( $id > 0 ) {
					$this->visible_category_counts[ $id ] = max( 0, (int) ( $row['product_count'] ?? 0 ) );
				}
			}
		}

		return $this->visible_category_counts[ $term_id ] ?? 0;
	}

	/**
	 * Remove manually configured product-category links from public menus when
	 * their category has no visible product. Other custom links are preserved.
	 *
	 * @param array $items Menu item objects.
	 * @param mixed $args Menu arguments.
	 * @return array
	 */
	public function filter_invisible_category_menu_items( $items, $args = null ) {
		unset( $args );

		if ( ! is_array( $items ) || ! $this->is_public_storefront_request() ) {
			return $items;
		}

		$visible_ids = $this->get_visible_category_ids();

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $visible_ids ) {
					if ( ! is_object( $item ) || 'product_cat' !== ( $item->object ?? '' ) ) {
						return true;
					}

					return isset( $visible_ids[ (int) ( $item->object_id ?? 0 ) ] );
				}
			)
		);
	}

	/**
	 * Resolve direct product categories plus all of their ancestors.
	 *
	 * @return array<int, true>
	 */
	public function get_visible_category_ids() {
		if ( null !== $this->visible_category_ids ) {
			return $this->visible_category_ids;
		}

		global $wpdb;

		$this->visible_category_ids = array();
		if ( ! isset( $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms ) ) {
			return $this->visible_category_ids;
		}

		$stock_join  = '';
		$stock_where = '';
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ) && isset( $wpdb->wc_product_meta_lookup ) ) {
			$stock_join  = " INNER JOIN {$wpdb->wc_product_meta_lookup} product_lookup ON product_lookup.product_id = products.ID";
			$stock_where = " AND product_lookup.stock_status = 'instock'";
		}
		$invalid_ids = $this->invalid_product_title_ids();
		$title_where = empty( $invalid_ids ) ? '' : ' AND products.ID NOT IN (' . implode( ',', array_map( 'absint', $invalid_ids ) ) . ')';

		$sql = "SELECT DISTINCT category_taxonomy.term_id
			FROM {$wpdb->posts} products
			INNER JOIN {$wpdb->term_relationships} category_relationship
				ON category_relationship.object_id = products.ID
			INNER JOIN {$wpdb->term_taxonomy} category_taxonomy
				ON category_taxonomy.term_taxonomy_id = category_relationship.term_taxonomy_id
				AND category_taxonomy.taxonomy = 'product_cat'
			{$stock_join}
			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				{$stock_where}
				{$title_where}
				AND NOT EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} visibility_relationship
					INNER JOIN {$wpdb->term_taxonomy} visibility_taxonomy
						ON visibility_taxonomy.term_taxonomy_id = visibility_relationship.term_taxonomy_id
						AND visibility_taxonomy.taxonomy = 'product_visibility'
					INNER JOIN {$wpdb->terms} visibility_term
						ON visibility_term.term_id = visibility_taxonomy.term_id
					WHERE visibility_relationship.object_id = products.ID
						AND visibility_term.slug = 'exclude-from-catalog'
				)";

		$direct_ids = array_map( 'absint', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static table names and literals only.
		foreach ( $direct_ids as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}
			$this->visible_category_ids[ $term_id ] = true;
			foreach ( (array) get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$this->visible_category_ids[ (int) $ancestor_id ] = true;
			}
		}

		/**
		 * Filter the request-local set of storefront-visible product categories.
		 *
		 * @param array<int, true> $visible_category_ids Visible IDs keyed by term ID.
		 */
		$this->visible_category_ids = (array) apply_filters( 'digitalogic_visible_product_category_ids', $this->visible_category_ids );

		return $this->visible_category_ids;
	}

	/**
	 * Keep admin, CLI, cron and authenticated editorial operations authoritative.
	 * Public REST and Store API reads remain storefront reads.
	 *
	 * @return bool
	 */
	private function is_public_storefront_request() {
		$is_public = true;
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$is_public = false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$is_public = false;
		}
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$is_public = false;
		}
		if ( current_user_can( 'edit_products' ) && ! $this->is_store_api_request() ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this canonical product-editor capability.
			$is_public = false;
		}

		return (bool) apply_filters( 'digitalogic_is_public_storefront_request', $is_public );
	}

	/**
	 * Store API routes are shopper-facing even when an editor is signed in.
	 *
	 * @return bool
	 */
	private function is_store_api_request() {
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		return 1 === preg_match( '#(?:^|/)wc/store(?:/v1)?/products(?:/|\?|$)#', $route );
	}
}
