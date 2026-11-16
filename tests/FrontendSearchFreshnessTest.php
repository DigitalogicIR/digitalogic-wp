<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../laravel/app/Services/CatalogSearchPolicy.php';
require_once __DIR__ . '/../includes/integrations/class-frontend-search.php';

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	/** Exact-SKU fixture for canonical autocomplete ranking. */
	function wc_get_product_id_by_sku( $sku ) {
		return $GLOBALS['digitalogic_test_exact_skus'][ (string) $sku ] ?? 0;
	}
}

final class FrontendSearchFreshnessTest extends TestCase {

	/** Exercise real response/cache code in an isolated WordPress fixture. */
	public function test_cache_revision_and_fresh_price_boundaries(): void {
		$output = array();
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/search-cache-regression.php' ), $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertContains( 'Search cache regression: 11 boundaries passed', $output );
	}

	public function test_daemon_rejects_search_before_reusing_woocommerce_request_state(): void {
		$search = Digitalogic_Frontend_Search::instance();
		$this->assertFalse( $search->allow_public_ajax_search_action( true, 'woodmart_ajax_search', array(), 'websocket' ) );
		$this->assertTrue( $search->allow_public_ajax_search_action( true, 'woodmart_ajax_search', array(), 'ajax' ) );
		$this->assertTrue( $search->allow_public_ajax_search_action( true, 'digitalogic_catalog_page', array(), 'websocket' ) );
		$this->assertFalse( $search->allow_public_ajax_search_action( false, 'unrelated', array(), 'websocket' ) );
	}

	/** WoodMart receives the symmetric compiled view, never reciprocal saved rows. */
	public function test_woodmart_runtime_alias_projection_uses_the_shared_policy(): void {
		$search   = ( new ReflectionClass( Digitalogic_Frontend_Search::class ) )->newInstanceWithoutConstructor();
		$saved    = "Super Mini =\nNode MCU = nodemcu";
		$compiled = $search->compile_search_aliases_for_woodmart( $saved, 'search_synonyms' );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->assertSame( $saved, $compiled );
		} else {
			$this->assertStringContainsString( 'supermini = super mini,super-mini', $compiled );
			$this->assertStringContainsString( 'nodemcu = node mcu,node-mcu', $compiled );
		}
		$this->assertSame( 'unchanged', $search->compile_search_aliases_for_woodmart( 'unchanged', 'another_option' ) );
	}

	/** Only an actual saved alias change publishes the existing cache event. */
	public function test_alias_setting_change_queues_one_search_invalidation(): void {
		$search  = ( new ReflectionClass( Digitalogic_Frontend_Search::class ) )->newInstanceWithoutConstructor();
		$pending = new ReflectionProperty( $search, 'invalidation_pending' );
		$pending->setValue( $search, false );

		$search->search_aliases_updated(
			array( 'search_synonyms' => 'Super Mini =' ),
			array( 'search_synonyms' => 'Super Mini =' )
		);
		$this->assertFalse( $pending->getValue( $search ) );

		$search->search_aliases_updated(
			array( 'search_synonyms' => 'Super Mini =' ),
			array( 'search_synonyms' => "Super Mini =\nNode MCU = nodemcu" )
		);
		$this->assertTrue( $pending->getValue( $search ) );
	}

	/** The existing WoodMart field explains the shared two-way semantics. */
	public function test_woodmart_alias_help_does_not_request_reciprocal_rows(): void {
		$search = ( new ReflectionClass( Digitalogic_Frontend_Search::class ) )->newInstanceWithoutConstructor();
		$help   = $search->clarify_search_alias_admin_help(
			'Native help',
			'Synonyms are one-way. If you need both directions, add the synonym in reverse.',
			'woodmart'
		);

		$this->assertStringContainsString( 'two-way group', $help );
		$this->assertStringContainsString( 'reciprocal rows are not required', $help );
		$this->assertSame( 'Native help', $search->clarify_search_alias_admin_help( 'Native help', 'Other text', 'woodmart' ) );
	}

	/** Alias ranking is canonical, except when the literal query is an exact SKU. */
	public function test_woodmart_runtime_query_preserves_exact_literal_sku(): void {
		$GLOBALS['digitalogic_test_options']['xts-woodmart-options'] = array( 'search_synonyms' => 'Super Mini =' );
		$GLOBALS['digitalogic_test_exact_skus'] = array();
		$search = ( new ReflectionClass( Digitalogic_Frontend_Search::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( $search, 'theme_search_query' );

		$canonical = $method->invoke( $search, array( 'query' => 'SuperMini' ), 'Digitalogic\\Laravel\\Services\\CatalogSearchPolicy' );
		$this->assertSame( 'super mini', $canonical['query'] );

		$GLOBALS['digitalogic_test_exact_skus']['SuperMini'] = 77;
		$exact = $method->invoke( $search, array( 'query' => 'SuperMini' ), 'Digitalogic\\Laravel\\Services\\CatalogSearchPolicy' );
		$this->assertSame( 'SuperMini', $exact['query'] );

		unset( $GLOBALS['digitalogic_test_exact_skus'], $GLOBALS['digitalogic_test_options']['xts-woodmart-options'] );
	}
}
