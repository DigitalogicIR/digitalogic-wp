<?php

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $object_id, $object_type = '', $resource_type = '' ) {
		unset( $object_type, $resource_type );

		return $GLOBALS['digitalogic_test_category_ancestors'][ (int) $object_id ] ?? array();
	}
}

require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-catalog.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/CatalogSearchPolicy.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-product-table.php';

if ( ! function_exists( 'wc_get_product_category_list' ) ) {
	/**
	 * Return an empty category list for focused table-row tests.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $separator Category separator.
	 * @return string
	 */
	function wc_get_product_category_list( $product_id, $separator = ', ' ) {
		unset( $product_id, $separator );

		return '';
	}
}

final class StorefrontCatalogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_filters']            = array();
		$GLOBALS['digitalogic_test_options']            = array();
		$GLOBALS['digitalogic_test_option_cache']       = array();
		$GLOBALS['digitalogic_test_category_ancestors'] = array();
		add_filter( 'digitalogic_is_public_storefront_request', static fn() => true );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		wp_cache_delete( 'invalid-product-titles-v1', 'digitalogic-storefront-catalog' );
	}

	/** Public title validity must match the native Unicode predicate exactly. */
	public function test_public_product_title_predicate_rejects_only_blank_dash_and_format_titles(): void {
		foreach ( array( '', '---', " \t\n", "\u{2013}\u{2014}\u{2212}", "\u{200B}\u{200F}\u{FEFF}", '&mdash;' ) as $invalid ) {
			$this->assertFalse( Digitalogic_Storefront_Catalog::has_public_product_title( $invalid ), bin2hex( $invalid ) );
		}
		foreach ( array( 'ESP32-C3', '-5V', 'نام کالا', 'A', '.', '⚙' ) as $valid ) {
			$this->assertTrue( Digitalogic_Storefront_Catalog::has_public_product_title( $valid ), $valid );
		}
		$this->assertFalse( Digitalogic_Storefront_Catalog::has_public_product_title( "\xC3\x28" ) );
		$this->assertFalse( Digitalogic_Storefront_Catalog::product_has_public_title( 99999999 ) );
	}

	/** Public Woo queries exclude invalid records while the stored records stay intact. */
	public function test_public_product_queries_exclude_invalid_title_ids_without_mutation(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public function get_results( $query, $output = ARRAY_A ) {
				unset( $query, $output );

				return array(
					array( 'ID' => 41, 'post_title' => '---' ),
					array( 'ID' => 42, 'post_title' => 'ESP32-C3' ),
				);
			}
		};
		$catalog         = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$catalog->clear_public_title_cache();

		$args = $catalog->filter_product_object_query_args( array( 'exclude' => array( 9 ) ) );
		$this->assertSame( array( 9, 41 ), $args['exclude'] );

		$query = new class() {
			public $vars = array( 'post_type' => 'product', 'post__not_in' => array( 9 ) );
			public function get( $key ) {
				return $this->vars[ $key ] ?? ''; }
			public function set( $key, $value ) {
				$this->vars[ $key ] = $value; }
			public function is_post_type_archive( $type ) {
				unset( $type ); return false; }
		};
		$catalog->filter_public_product_query( $query );
		$this->assertSame( array( 9, 41 ), $query->vars['post__not_in'] );
		$clauses = $catalog->filter_public_product_clauses( array( 'where' => ' WHERE 1=1' ), $query );
		$this->assertStringContainsString( 'wp_posts.ID NOT IN (41)', $clauses['where'] );
		$this->assertSame( '---', $GLOBALS['wpdb']->get_results( '' )[0]['post_title'] );
	}

	/** Persistent workers must refresh the local memo after an external repair. */
	public function test_invalid_title_memo_refreshes_from_shared_cache_after_local_ttl(): void {
		$external_cache = function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : null;
		if ( null !== $external_cache ) {
			wp_using_ext_object_cache( true );
		}
		$GLOBALS['digitalogic_test_object_cache_enabled'] = true;
		$GLOBALS['digitalogic_test_object_cache']         = array(
			'digitalogic-storefront-catalog:invalid-product-titles-v1' => array( 41 ),
		);
		$GLOBALS['digitalogic_test_cache_gets'] = array();
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		try {
			$this->assertSame( array( 41 ), $catalog->invalid_product_title_ids() );
			$this->assertSame( array( 'invalid-product-titles-v1', 'digitalogic-storefront-catalog', true ), $GLOBALS['digitalogic_test_cache_gets'][0] );
			$GLOBALS['digitalogic_test_object_cache']['digitalogic-storefront-catalog:invalid-product-titles-v1'] = array( 42 );
			$this->assertSame( array( 41 ), $catalog->invalid_product_title_ids() );

			$loaded_at = new ReflectionProperty( $catalog, 'invalid_product_title_ids_loaded_at' );
			$loaded_at->setValue( $catalog, hrtime( true ) - 2000000000 );
			$this->assertSame( array( 42 ), $catalog->invalid_product_title_ids() );
		} finally {
			unset( $GLOBALS['digitalogic_test_object_cache_enabled'], $GLOBALS['digitalogic_test_object_cache'], $GLOBALS['digitalogic_test_cache_gets'] );
			if ( null !== $external_cache ) {
				wp_using_ext_object_cache( $external_cache );
			}
		}
	}

	/** Store API direct details must not bypass collection-query visibility. */
	public function test_store_api_single_product_guard_returns_not_found_for_invalid_title(): void {
		$GLOBALS['digitalogic_test_posts'][910041] = array(
			'post_type'     => 'product',
			'post_status'   => 'publish',
			'post_title'    => "\u{200B}---",
			'post_password' => '',
			'meta'          => array(),
		);
		$GLOBALS['digitalogic_test_posts'][910042] = array(
			'post_type'     => 'product',
			'post_status'   => 'publish',
			'post_title'    => 'ESP32-C3',
			'post_password' => '',
			'meta'          => array(),
		);
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$invalid = ( new WP_REST_Request( array( 'id' => 910041 ) ) )->set_route( '/wc/store/v1/products/910041' )->set_method( 'GET' );
		$valid   = ( new WP_REST_Request( array( 'id' => 910042 ) ) )->set_route( '/wc/store/v1/products/910042' )->set_method( 'GET' );

		$error = $catalog->filter_store_api_single_product( null, array(), $invalid );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 404, $error->get_error_data()['status'] );
		$this->assertNull( $catalog->filter_store_api_single_product( null, array(), $valid ) );
		unset( $GLOBALS['digitalogic_test_posts'][910041], $GLOBALS['digitalogic_test_posts'][910042] );
	}

	/** Store API search must preserve Woo's explicit empty post__in sentinel. */
	public function test_store_api_search_does_not_resurrect_an_empty_constrained_result(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public function get_results( $query, $output = ARRAY_A ) {
				unset( $query, $output );

				return array();
			}

			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			public function get_col( $query ) {
				unset( $query );

				return array( 42 );
			}
		};
		$catalog         = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$catalog->clear_public_title_cache();
		$query = new class() {
			public $vars = array(
				'post_type' => 'product',
				'post__in'  => array( 0 ),
				'search'    => 'Super Mini',
			);
			public function get( $key ) {
				return $this->vars[ $key ] ?? ''; }
			public function set( $key, $value ) {
				$this->vars[ $key ] = $value; }
			public function is_post_type_archive( $type ) {
				unset( $type ); return false; }
		};

		$catalog->filter_public_product_query( $query );

		$this->assertSame( array( 0 ), $query->vars['post__in'] );
		$this->assertSame( '', $query->vars['search'] );
	}

	/** Woo Store candidate discovery uses the same bidirectional alias group. */
	public function test_store_api_search_uses_shared_bidirectional_model_aliases(): void {
		$GLOBALS['digitalogic_test_options']['xts-woodmart-options'] = array(
			'search_synonyms' => "Super Mini =\nNode MCU = nodemcu",
		);
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $catalog_search_query = '';
			public $catalog_search_args  = array();

			public function get_results( $query, $output = ARRAY_A ) {
				unset( $query, $output );

				return array();
			}

			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				$this->catalog_search_query = (string) $query;
				$this->catalog_search_args  = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;

				return (string) $query;
			}

			public function get_col( $query ) {
				unset( $query );

				return array( 41, 42, 43 );
			}
		};
		$catalog      = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$catalog->clear_public_title_cache();
		$method       = new ReflectionMethod( $catalog, 'store_api_search_product_ids' );
		$spaced       = $method->invoke( $catalog, 'super mini', 300 );
		$spaced_args  = $GLOBALS['wpdb']->catalog_search_args;
		$compact      = $method->invoke( $catalog, 'supermini', 300 );
		$compact_args = $GLOBALS['wpdb']->catalog_search_args;

		$this->assertSame( array( 41, 42, 43 ), $spaced );
		$this->assertSame( $spaced, $compact );
		$this->assertSame( $spaced_args, $compact_args );
		$this->assertContains( '%super mini%', $compact_args );
		$this->assertContains( '%super-mini%', $compact_args );
		$this->assertContains( '%supermini%', $compact_args );
		$this->assertSame( 301, end( $compact_args ) );
	}

	/** WP-CLI and other authoritative administration reads retain every record. */
	public function test_authoritative_admin_queries_are_not_filtered(): void {
		remove_all_filters( 'digitalogic_is_public_storefront_request' );
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		$this->assertSame( array( 'exclude' => array( 9 ) ), $catalog->filter_product_object_query_args( array( 'exclude' => array( 9 ) ) ) );
	}

	/** Table cells must retain table formatting; flex belongs to an inner wrapper. */
	public function test_relationship_queries_preserve_type_and_hidden_category_terms(): void {
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$terms   = array(
			(object) array(
				'term_id'  => 4,
				'taxonomy' => 'product_type',
			),
			(object) array(
				'term_id'  => 8,
				'taxonomy' => 'product_cat',
			),
		);
		foreach ( array(
			array( array( 'product_cat', 'product_type' ), array( 'hide_empty' => false ) ),
			array(
				array( 'product_cat' ),
				array(
					'hide_empty' => false,
					'object_ids' => array( 10756 ),
				),
			),
			array(
				array( 'product_cat' ),
				array(
					'hide_empty' => false,
					'fields'     => 'all_with_object_id',
				),
			),
		) as $case ) {
			$this->assertSame( $case[1], $catalog->force_hide_empty_product_categories( $case[1], $case[0] ) );
			$this->assertSame( $terms, $catalog->filter_invisible_product_categories( $terms, $case[0], $case[1] ) );
		}
	}

	public function test_catalog_code_and_product_cells_keep_table_column_layout(): void {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/storefront-catalog.css' );

		$this->assertIsString( $css );
		$this->assertMatchesRegularExpression( '/\.dgl-catalog-product-cell\s*\{[^}]*display:\s*table-cell;/s', $css );
		$this->assertMatchesRegularExpression( '/\.dgl-catalog-product-layout\s*\{[^}]*display:\s*flex;/s', $css );
		$this->assertMatchesRegularExpression( '/\.dgl-catalog-code\s*\{[^}]*display:\s*table-cell;/s', $css );
		$this->assertDoesNotMatchRegularExpression( '/\.dgl-catalog-code\s*\{[^}]*display:\s*flex;/s', $css );
	}

	public function test_forces_empty_category_hiding_only_for_product_categories(): void {
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		$product_args = $catalog->force_hide_empty_product_categories(
			array( 'hide_empty' => false ),
			array( 'product_cat' )
		);
		$post_args    = $catalog->force_hide_empty_product_categories(
			array( 'hide_empty' => false ),
			array( 'category' )
		);

		$this->assertTrue( $product_args['hide_empty'] );
		$this->assertFalse( $post_args['hide_empty'] );
	}

	public function test_keeps_empty_parent_categories_resolvable_by_slug(): void {
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$args    = array(
			'slug'       => array( 'passive-components' ),
			'hide_empty' => false,
		);

		$this->assertSame( $args, $catalog->force_hide_empty_product_categories( $args, array( 'product_cat' ) ) );
	}

	public function test_filters_non_catalog_terms_and_keeps_visible_ancestors(): void {
		$GLOBALS['digitalogic_test_category_ancestors'][31] = array( 20, 10 );
		$GLOBALS['wpdb']                                    = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships     = 'wp_term_relationships';
			public $term_taxonomy          = 'wp_term_taxonomy';
			public $terms                  = 'wp_terms';
			public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
			public $last_catalog_query     = '';

			public function get_col( $query ) {
				$this->last_catalog_query = (string) $query;

				return array( 31 );
			}
		};
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$terms   = array(
			(object) array(
				'term_id' => 10,
				'name'    => 'Parent',
			),
			(object) array(
				'term_id' => 20,
				'name'    => 'Child',
			),
			(object) array(
				'term_id' => 31,
				'name'    => 'Visible leaf',
			),
			(object) array(
				'term_id' => 40,
				'name'    => 'Empty',
			),
		);

		$filtered = $catalog->filter_invisible_product_categories(
			$terms,
			array( 'product_cat' ),
			array( 'fields' => 'all' )
		);

		$this->assertSame( array( 10, 20, 31 ), array_map( static fn( $term ) => $term->term_id, $filtered ) );
		$this->assertStringContainsString( "products.post_status = 'publish'", $GLOBALS['wpdb']->last_catalog_query );
		$this->assertStringContainsString( "visibility_term.slug = 'exclude-from-catalog'", $GLOBALS['wpdb']->last_catalog_query );
	}

	public function test_honors_hide_out_of_stock_store_setting(): void {
		$GLOBALS['digitalogic_test_options']['woocommerce_hide_out_of_stock_items'] = 'yes';
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships     = 'wp_term_relationships';
			public $term_taxonomy          = 'wp_term_taxonomy';
			public $terms                  = 'wp_terms';
			public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
			public $last_catalog_query     = '';

			public function get_col( $query ) {
				$this->last_catalog_query = (string) $query;

				return array();
			}
		};
		$catalog         = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		$catalog->get_visible_category_ids();

		$this->assertStringContainsString( 'wp_wc_product_meta_lookup', $GLOBALS['wpdb']->last_catalog_query );
		$this->assertStringContainsString( "product_lookup.stock_status = 'instock'", $GLOBALS['wpdb']->last_catalog_query );
	}

	public function test_filters_empty_product_categories_from_manual_storefront_menus(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy      = 'wp_term_taxonomy';
			public $terms              = 'wp_terms';

			public function get_col( $query ) {
				unset( $query );

				return array( 10 );
			}
		};
		$catalog         = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$items           = array(
			(object) array(
				'ID'        => 1,
				'object'    => 'product_cat',
				'object_id' => 10,
			),
			(object) array(
				'ID'        => 2,
				'object'    => 'product_cat',
				'object_id' => 20,
			),
			(object) array(
				'ID'        => 3,
				'object'    => 'custom',
				'object_id' => 0,
			),
		);

		$filtered = $catalog->filter_invisible_category_menu_items( $items );

		$this->assertSame( array( 1, 3 ), array_map( static fn( $item ) => $item->ID, $filtered ) );
	}

	/** Ensure a leaf Code search resolves to its published parent row. */
	public function test_table_search_maps_published_child_codes_back_to_parent_products(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $catalog_search_query = '';
			public $catalog_search_args  = array();

			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				$this->catalog_search_query = (string) $query;
				$this->catalog_search_args  = $args;

				return (string) $query;
			}

			public function get_col( $query ) {
				unset( $query );

				return array( 10600 );
			}
		};
		$table           = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method          = new ReflectionMethod( $table, 'search_product_ids' );
		$ids             = $method->invoke( $table, '113004011' );

		$this->assertSame( array( 10600 ), $ids );
		$this->assertStringContainsString( 'variations.post_parent = products.ID', $GLOBALS['wpdb']->catalog_search_query );
		$this->assertStringContainsString( "variations.post_status = 'publish'", $GLOBALS['wpdb']->catalog_search_query );
		$this->assertStringContainsString( 'variation_identifiers.meta_value LIKE %s', $GLOBALS['wpdb']->catalog_search_query );
		$this->assertSame( array_fill( 0, 4, '%113004011%' ), $GLOBALS['wpdb']->catalog_search_args );
	}

	/** The table reuses canonical aliases and never emits duplicate variation cards. */
	public function test_table_search_uses_shared_bidirectional_model_aliases(): void {
		$GLOBALS['digitalogic_test_options']['xts-woodmart-options'] = array(
			'search_synonyms' => "Super Mini =\nNode MCU = nodemcu",
		);
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $catalog_search_query = '';
			public $catalog_search_args  = array();

			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				$this->catalog_search_query = (string) $query;
				$this->catalog_search_args  = $args;

				return (string) $query;
			}

			public function get_col( $query ) {
				unset( $query );

				return array( 41, 42, 43 );
			}
		};
		$table        = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method       = new ReflectionMethod( $table, 'search_product_ids' );
		$spaced       = $method->invoke( $table, 'super mini' );
		$spaced_args  = $GLOBALS['wpdb']->catalog_search_args;
		$compact      = $method->invoke( $table, 'supermini' );
		$compact_args = $GLOBALS['wpdb']->catalog_search_args;

		$this->assertSame( array( 41, 42, 43 ), $spaced );
		$this->assertSame( $spaced, $compact );
		$this->assertSame( $spaced_args, $compact_args );
		$this->assertContains( '%super mini%', $compact_args );
		$this->assertContains( '%super-mini%', $compact_args );
		$this->assertContains( '%supermini%', $compact_args );
		$this->assertCount( 12, $compact_args );
	}

	/** Ensure legacy child references cannot masquerade as quick-add choices. */
	public function test_legacy_parent_child_codes_are_labeled_as_registered_and_disable_quick_add(): void {
		$GLOBALS['digitalogic_test_posts'][40] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Legacy parent',
			'meta'         => array( '_price' => '1000' ),
		);
		$GLOBALS['digitalogic_test_posts'][41] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 40,
			'post_title'   => 'Registered child',
			'meta'         => array( '_digitalogic_patris_product_code' => 'CHILD-41' ),
		);
		$product                               = new class( 40 ) extends WC_Product {
			public function get_price_html() {
				return '۱۰۰۰ تومان'; }
			public function managing_stock() {
				return false; }
			public function is_purchasable() {
				return true; }
			public function is_in_stock() {
				return true; }
			public function is_sold_individually() {
				return false; }
			public function add_to_cart_url() {
				return 'https://digitalogic.test/?add-to-cart=40'; }
		};
		$table                                 = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method                                = new ReflectionMethod( $table, 'product_row' );
		$html                                  = $method->invoke( $table, $product );

		$this->assertStringContainsString( 'کدهای ثبت‌شده برای مدل‌ها', $html );
		$this->assertStringContainsString( 'CHILD-41', $html );
		$this->assertStringContainsString( 'دیدن کد مدل‌ها', $html );
		$this->assertStringNotContainsString( 'افزودن سریع', $html );
	}

	/** Ensure source product codes use the canonical generic customer label. */
	public function test_table_uses_generic_product_code_label(): void {
		$GLOBALS['digitalogic_test_posts'][49] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Coded product',
			'meta'         => array(
				'_digitalogic_patris_product_code' => 'CODE-49',
				'_price'                           => '1000',
			),
		);
		$product                               = new class( 49 ) extends WC_Product {
			public function get_price_html() {
				return '۱۰۰۰ تومان'; }
			public function managing_stock() {
				return false; }
			public function is_purchasable() {
				return true; }
			public function is_in_stock() {
				return true; }
			public function is_sold_individually() {
				return false; }
			public function add_to_cart_url() {
				return 'https://digitalogic.test/?add-to-cart=49'; }
		};
		$table                                 = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method                                = new ReflectionMethod( $table, 'product_row' );
		$html                                  = $method->invoke( $table, $product );

		$this->assertStringContainsString( '>کد کالا</span>', $html );
		$this->assertStringContainsString( 'CODE-49', $html );
		$this->assertStringNotContainsString( 'کد پاتریس', $html );
		$this->assertSame( 6, substr_count( $html, '<td' ) );
		$this->assertStringContainsString( 'class="dgl-catalog-product-layout"', $html );
		$this->assertMatchesRegularExpression( '/<td class="dgl-catalog-product-cell">.*<\/td>\s*<td class="dgl-catalog-code">/s', $html );
	}

	/** Ensure an unrelated WooCommerce SKU keeps its own label. */
	public function test_table_calls_an_unrelated_code_sku_not_patris_code(): void {
		$GLOBALS['digitalogic_test_posts'][50] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Raspberry Pi',
			'meta'         => array(
				'_sku'   => 'RPI5-8GB',
				'_price' => '1000',
			),
		);
		$product                               = new class( 50 ) extends WC_Product {
			public function get_price_html() {
				return '۱۰۰۰ تومان'; }
			public function managing_stock() {
				return false; }
			public function is_purchasable() {
				return true; }
			public function is_in_stock() {
				return true; }
			public function is_sold_individually() {
				return false; }
			public function add_to_cart_url() {
				return 'https://digitalogic.test/?add-to-cart=50'; }
		};
		$table                                 = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method                                = new ReflectionMethod( $table, 'product_row' );
		$html                                  = $method->invoke( $table, $product );

		$this->assertStringContainsString( '>SKU</span>', $html );
		$this->assertStringContainsString( 'RPI5-8GB', $html );
		$this->assertStringNotContainsString( 'کد پاتریس', $html );
	}
}
