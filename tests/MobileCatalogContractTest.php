<?php
/**
 * Native CatalogContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-catalog.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCatalogController.php';

/** Regression coverage for the native adapter. */
final class MobileCatalogContractTest extends TestCase {
	/** Restore the synthetic database and catalog cache after every example. */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		unset( $GLOBALS['digitalogic_test_options']['xts-woodmart-options'], $GLOBALS['digitalogic_test_option_cache']['xts-woodmart-options'] );
		wp_cache_delete( 'invalid-product-titles-v1', 'digitalogic-storefront-catalog' );
	}

	/** Search queries select one canonical parent and match exact child models. */
	public function test_mobile_search_uses_separator_variants_and_variation_parent_discovery(): void {
		$GLOBALS['digitalogic_test_options']['xts-woodmart-options'] = array(
			'search_synonyms' => "Super Mini =\nNode MCU = nodemcu",
		);
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			/** Captured fixture queries.
			 *
			 * @var array
			 */
			public $catalog_queries = array();
			/** Captured bound fixture values.
			 *
			 * @var array
			 */
			public $catalog_values = array();

			/**
			 * Escape the synthetic LIKE value.
			 *
			 * @param mixed $value Fixture value.
			 * @return string Escaped value.
			 */
			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			/**
			 * Return an empty synthetic result set.
			 *
			 * @param mixed $prepared Prepared fixture query.
			 * @param mixed $output Requested format.
			 * @return array No provider rows.
			 */
			public function get_results( $prepared, $output = ARRAY_A ) {
				unset( $prepared, $output );

				return array();
			}

			/**
			 * Capture the count query without a database.
			 *
			 * @param mixed $prepared Prepared fixture query.
			 * @return int Synthetic empty count.
			 */
			public function get_var( $prepared ) {
				$this->catalog_queries[] = is_array( $prepared ) ? $prepared['query'] : (string) $prepared;
				$this->catalog_values[]  = is_array( $prepared ) ? $prepared['args'] : array();

				return 0;
			}

			/**
			 * Capture product selection without a database.
			 *
			 * @param mixed $prepared Prepared fixture query.
			 * @return array No selected products.
			 */
			public function get_col( $prepared ) {
				$this->catalog_queries[] = is_array( $prepared ) ? $prepared['query'] : (string) $prepared;
				$this->catalog_values[]  = is_array( $prepared ) ? $prepared['args'] : array();

				return array();
			}
		};
		Digitalogic_Storefront_Catalog::instance()->clear_public_title_cache();
		$controller                       = new MobileCatalogController();
		$method                           = new ReflectionMethod( $controller, 'productsData' );
		$spaced_query                     = MobileCatalogController::normalizeQuery( array( 'search' => 'Super Mini' ) );
		$compact_query                    = MobileCatalogController::normalizeQuery( array( 'search' => 'SuperMini' ) );
		$result                           = $method->invoke( $controller, $spaced_query );
		$sql                              = implode( "\n", $GLOBALS['wpdb']->catalog_queries );
		$values                           = array_merge( ...array_map( static fn( $args ) => isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args, $GLOBALS['wpdb']->catalog_values ) );
		$spaced_candidates                = $GLOBALS['wpdb']->catalog_values[0];
		$GLOBALS['wpdb']->catalog_queries = array();
		$GLOBALS['wpdb']->catalog_values  = array();
		$compact_result                   = $method->invoke( $controller, $compact_query );
		$compact_candidates               = $GLOBALS['wpdb']->catalog_values[0];

		self::assertSame( 0, $result['pagination']['total'] );
		self::assertSame( array(), $result['items'] );
		self::assertSame( $result['pagination'], $compact_result['pagination'] );
		self::assertSame( $spaced_query['search_terms'], $compact_query['search_terms'] );
		self::assertSame( $spaced_candidates, $compact_candidates );
		self::assertStringContainsString( "p.post_type='product'", $sql );
		self::assertStringContainsString( 'variations.post_parent=p.ID', $sql );
		self::assertStringContainsString( "variations.post_type='product_variation'", $sql );
		self::assertStringNotContainsString( "p.post_type='product_variation'", $sql );
		self::assertContains( '%supermini%', $values );
	}

	/** Alias expansion widens candidates but the literal query keeps exact-SKU rank zero. */
	public function test_mobile_exact_sku_ranking_uses_the_original_query(): void {
		$GLOBALS['digitalogic_test_options']['xts-woodmart-options'] = array(
			'search_synonyms' => 'MODEL-001 = legacy model',
		);
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			/** Bound values selected by the synthetic catalog query.
			 *
			 * @var array
			 */
			public $select_values = array();

			/**
			 * Escape the synthetic LIKE value.
			 *
			 * @param mixed $value Fixture value.
			 * @return string Escaped value.
			 */
			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			/**
			 * Return an empty synthetic result set.
			 *
			 * @param mixed $prepared Prepared fixture query.
			 * @param mixed $output Requested format.
			 * @return array No provider rows.
			 */
			public function get_results( $prepared, $output = ARRAY_A ) {
				unset( $prepared, $output );

				return array();
			}

			/**
			 * Return an empty synthetic count.
			 *
			 * @param mixed $prepared Prepared fixture query.
			 * @return int No matching products.
			 */
			public function get_var( $prepared ) {
				unset( $prepared );

				return 0;
			}

			/**
			 * Capture product selection without a database.
			 *
			 * @param mixed $prepared Prepared fixture query.
			 * @return array No selected products.
			 */
			public function get_col( $prepared ) {
				$this->select_values = is_array( $prepared ) ? $prepared['args'] : array();

				return array();
			}
		};
		Digitalogic_Storefront_Catalog::instance()->clear_public_title_cache();
		$query      = MobileCatalogController::normalizeQuery( array( 'search' => 'MODEL-001' ) );
		$controller = new MobileCatalogController();
		$method     = new ReflectionMethod( $controller, 'productsData' );
		$method->invoke( $controller, $query );
		$values      = isset( $GLOBALS['wpdb']->select_values[0] ) && is_array( $GLOBALS['wpdb']->select_values[0] )
			? $GLOBALS['wpdb']->select_values[0]
			: $GLOBALS['wpdb']->select_values;
		$where_count = 1 + ( 4 * count( $query['search_terms'] ) );

		self::assertContains( 'legacy model', $query['search_terms'] );
		self::assertSame( 'model-001', $query['search'] );
		self::assertSame( 'model-001', $values[ $where_count ] );
	}
	/**
	 * Verify public entry cannot dispatch internal routes or unbounded ids.
	 *
	 * @return void Native adapter result.
	 */
	public function test_public_entry_cannot_dispatch_internal_routes_or_unbounded_ids(): void {
		self::assertSame( '/_digitalogic/mobile/products/123', Digitalogic_Mobile_API::route( '/app-api/products/123' ) );
		self::assertSame( '/_digitalogic/mobile/bootstrap', Digitalogic_Mobile_API::route( '/app-api/bootstrap/' ) );
		foreach ( array( '/app-api/../../bridge/status', '/app-api/bridge/status', '/app-api/products/0', '/app-api/products/-1', '/app-api/products/12345678901', '/app-api/products/1/delete', '/panel/' ) as $path ) {
			self::assertNull( Digitalogic_Mobile_API::route( $path ) );
		}
	}

	/**
	 * Verify query bounds allowlists and structured input do not reach sql.
	 *
	 * @return void Native adapter result.
	 */
	public function test_query_bounds_allowlists_and_structured_input_do_not_reach_sql(): void {
		$query = MobileCatalogController::normalizeQuery(
			array(
				'page'         => -5,
				'per_page'     => 500000,
				'orderby'      => 'id; DROP TABLE',
				'order'        => 'ASC;DELETE',
				'stock_status' => 'private',
				'search'       => array( 'bad' ),
				'category'     => -5,
			)
		);
		self::assertSame( 1, $query['page'] );
		self::assertSame( 48, $query['per_page'] );
		self::assertSame( 'date', $query['orderby'] );
		self::assertSame( 'DESC', $query['order'] );
		self::assertSame( '', $query['stock_status'] );
		self::assertSame( '', $query['search'] );
		self::assertSame( 0, $query['category'] );
		self::assertSame( 120, mb_strlen( MobileCatalogController::normalizeQuery( array( 'search' => str_repeat( 'الف', 100 ) ) )['search'] ) );
	}

	/**
	 * Verify absent price stays absent and currency minor units are explicit.
	 *
	 * @return void Native adapter result.
	 */
	public function test_absent_price_stays_absent_and_currency_minor_units_are_explicit(): void {
		self::assertNull( MobileCatalogController::minorUnits( '', 0 ) );
		self::assertNull( MobileCatalogController::minorUnits( null, 2 ) );
		self::assertNull( MobileCatalogController::minorUnits( 'unknown', 2 ) );
		self::assertSame( '125000', MobileCatalogController::minorUnits( '125000', 0 ) );
		self::assertSame( '12345', MobileCatalogController::minorUnits( '123.45', 2 ) );
		self::assertSame( '0', MobileCatalogController::minorUnits( '0', 0 ) );
	}

	/**
	 * Verify name removes only its own exact trailing product code.
	 *
	 * @return void Native adapter result.
	 */
	public function test_name_removes_only_its_own_exact_trailing_product_code(): void {
		self::assertSame( 'Arduino Mini', MobileCatalogController::displayName( 'Arduino Mini [113001017]', '113001017' ) );
		self::assertSame( 'Arduino Mini [other]', MobileCatalogController::displayName( 'Arduino Mini [other]', '113001017' ) );
		self::assertSame( 'Board [113001017] adapter', MobileCatalogController::displayName( 'Board [113001017] adapter', '113001017' ) );
	}
}
