<?php
/**
 * Linguistic search equivalence never changes canonical product identity.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\CatalogSearchPolicy as Policy;
use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/CatalogSearchPolicy.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCatalogController.php';

/** Query-only normalization, alias bounds and explicit sorting regressions. */
final class CatalogSearchPolicyTest extends TestCase {

	/** Model punctuation survives numeral and presentation normalization. */
	public function test_normalization_preserves_model_and_sku_punctuation(): void {
		self::assertSame( '113001017', Policy::normalize( "\u{200F}۱۱۳۰۰۱۰۱۷\u{200E}" ) );
		self::assertSame( 'esp32-c3 / 3.3v', Policy::normalize( ' ESP32-C3 / ۳.۳V ' ) );
		self::assertSame( 'آردوینو uno', Policy::normalize( "آردوینو\u{200C}UNO" ) );
		self::assertSame( 'یک', Policy::normalize( 'يك' ) );
	}

	/** Existing theme syntax is the sole configurable alias registry. */
	public function test_canonical_theme_setting_expands_symmetrically(): void {
		$groups = Policy::groups( "arduino = آردوینو,اردوینو\nesp32 = esp 32" );
		self::assertCount( 2, $groups );
		$english = Policy::terms( 'Arduino UNO', $groups );
		self::assertSame( $english, Policy::terms( 'آردوینو UNO', $groups ) );
		self::assertSame( $english, Policy::terms( 'اردوینو UNO', $groups ) );
		self::assertContains( 'arduino uno', $english );
		self::assertContains( 'آردوینو uno', $english );
		self::assertSame( Policy::terms( 'esp32-c3', $groups ), Policy::terms( 'esp 32-c3', $groups ) );
	}

	/** Linguistic aliases do not replace substrings in unrelated model identifiers. */
	public function test_aliases_are_boundary_scoped_and_work_is_bounded(): void {
		self::assertSame( array( 'myarduino123' ), Policy::terms( 'myArduino123', array( array( 'arduino', 'آردوینو' ) ) ) );
		self::assertSame( array( 'esp32s3' ), Policy::terms( 'ESP32S3', array( array( 'esp32', 'esp 32' ) ) ) );
		self::assertSame( array(), Policy::terms( '' ) );
		self::assertLessThanOrEqual( 12, count( Policy::terms( 'a', array_fill( 0, 40, array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ) ) ) ) );
		self::assertSame( array( 'a' ), Policy::terms( 'a', array( 'invalid', array( 'a', array( 'invalid' ) ) ) ) );
	}

	/** Exact model separators are presentation variants, not fuzzy identities. */
	public function test_model_separator_variants_are_bounded_and_do_not_expand_words(): void {
		$terms = Policy::terms( 'Super Mini', array() );
		self::assertContains( 'super mini', $terms );
		self::assertContains( 'super-mini', $terms );
		self::assertContains( 'supermini', $terms );
		self::assertNotContains( 'super miniature', $terms );
		self::assertNotContains( 'supermini', Policy::terms( 'Super Miniature', array() ) );
		self::assertSame( array( '-5v' ), Policy::terms( '-5V', array() ) );
		self::assertContains( 'esp32-c3', Policy::terms( 'ESP32-C3', array() ) );
		self::assertLessThanOrEqual( 12, count( $terms ) );
	}

	/** One saved group makes every normalized separator form bidirectional. */
	public function test_one_model_alias_group_is_bidirectional_without_reciprocal_rows(): void {
		$groups  = Policy::groups( 'Super Mini =' );
		$spaced  = Policy::terms( 'Super Mini', $groups );
		$compact = Policy::terms( 'SuperMini', $groups );
		$hyphen  = Policy::terms( 'Super-Mini', $groups );

		self::assertSame( array( 'super mini', 'super-mini', 'supermini' ), $spaced );
		self::assertSame( $spaced, $compact );
		self::assertSame( $spaced, $hyphen );
		self::assertNotContains( 'super miniature', $compact );
		self::assertSame( 'super mini', Policy::canonical( 'Super Mini', $groups ) );
		self::assertSame( 'super mini', Policy::canonical( 'SuperMini', $groups ) );
		self::assertSame( 'super mini', Policy::canonical( 'Super-Mini', $groups ) );
		self::assertSame( 'board super mini rev2', Policy::canonical( 'Board SuperMini Rev2', $groups ) );
		self::assertSame( 'alpha', Policy::canonical( 'charlie', array( array( 'alpha' ), array( 'charlie' ), array( 'alpha', 'charlie' ) ) ) );
	}

	/** Administrators can add, edit and remove independent groups without code changes. */
	public function test_independent_groups_follow_only_the_saved_configuration(): void {
		$added = Policy::groups( "Super Mini =\nNode MCU = nodemcu" );
		self::assertSame( Policy::terms( 'supermini', $added ), Policy::terms( 'Super Mini', $added ) );
		self::assertContains( 'node mcu', Policy::terms( 'NodeMCU', $added ) );
		self::assertNotContains( 'node mcu', Policy::terms( 'supermini', $added ) );

		$edited = Policy::groups( "Super Mini =\nController Board = control-board" );
		self::assertSame( array( 'nodemcu' ), Policy::terms( 'NodeMCU', $edited ) );
		self::assertContains( 'controller board', Policy::terms( 'control-board', $edited ) );
		self::assertSame( Policy::terms( 'supermini', $added ), Policy::terms( 'supermini', $edited ) );

		$removed = Policy::groups( 'Super Mini =' );
		self::assertNotContains( 'controller board', Policy::terms( 'control-board', $removed ) );
		self::assertSame( Policy::terms( 'supermini', $added ), Policy::terms( 'supermini', $removed ) );
	}

	/** WoodMart receives a runtime directional view while the saved group stays concise. */
	public function test_woodmart_projection_contains_each_normalized_lookup_direction(): void {
		$config = Policy::woodmartConfig( "Super Mini =\nNode MCU = nodemcu" );
		self::assertStringContainsString( 'super mini = super-mini,supermini', $config );
		self::assertStringContainsString( 'supermini = super mini,super-mini', $config );
		self::assertStringContainsString( 'node mcu = node-mcu,nodemcu', $config );
		self::assertStringContainsString( 'nodemcu = node mcu,node-mcu', $config );
		self::assertSame( hash( 'sha256', 'Super Mini =' ), Policy::revision( 'Super Mini =' ) );
	}

	/** Shared aliases form one deterministic group on every consumer. */
	public function test_overlapping_groups_compile_to_one_woodmart_key_and_one_policy_component(): void {
		$groups = Policy::groups( "alpha = shared\nshared = gamma" );
		self::assertSame( Policy::terms( 'alpha', $groups ), Policy::terms( 'gamma', $groups ) );

		$config = Policy::woodmartConfig( "alpha = shared\nshared = gamma" );
		self::assertSame( 1, preg_match_all( '/^shared = /m', $config ) );
		self::assertSame( 1, preg_match( '/^shared = ([^\r\n]+)/m', $config, $match ) );
		$replacements = explode( ',', $match[1] );
		sort( $replacements, SORT_STRING );
		self::assertSame( array( 'alpha', 'gamma' ), $replacements );
	}

	/** The generated view matches WoodMart's installed associative-key parser. */
	public function test_generated_config_is_bidirectional_under_woodmart_parser_shape(): void {
		$config = Policy::woodmartConfig( 'Super Mini =' );
		$parsed = array();
		foreach ( explode( PHP_EOL, $config ) as $line ) {
			$parts = explode( '=', $line, 2 );
			self::assertCount( 2, $parts );
			$key            = str_replace( ' ', '_', trim( strtolower( $parts[0] ) ) );
			$parsed[ $key ] = array_map( 'trim', explode( ',', $parts[1] ) );
		}

		self::assertContains( 'super mini', $parsed['supermini'] );
		self::assertContains( 'supermini', $parsed['super_mini'] );
		self::assertContains( 'super mini', $parsed['super-mini'] );
	}

	/** Configuration and generated runtime work stay hard bounded and UTF-8 safe. */
	public function test_large_and_multibyte_configuration_is_safely_bounded(): void {
		$prefix     = "Super Mini =\n";
		$multibyte  = $prefix . str_repeat( 'a', 65534 - strlen( $prefix ) ) . 'ی';
		$utf_groups = Policy::groups( $multibyte );
		self::assertNotEmpty( $utf_groups );
		self::assertSame( array( 'Super Mini' ), $utf_groups[0] );

		$lines = array();
		for ( $group = 0; $group < 30; ++$group ) {
			$aliases = array();
			for ( $alias = 0; $alias < 30; ++$alias ) {
				$aliases[] = 'alias' . $group . 'x' . $alias;
			}
			$lines[] = 'group' . $group . ' = ' . implode( ',', $aliases );
		}
		$config = Policy::woodmartConfig( implode( "\n", $lines ) );
		self::assertLessThanOrEqual( 240, count( explode( PHP_EOL, $config ) ) );
		self::assertLessThanOrEqual( 524288, strlen( $config ) );
	}

	/** Prior app builds can keep sending date; new normal search selects relevance. */
	public function test_default_relevance_never_overrides_an_explicit_sort(): void {
		self::assertSame( 'date', MobileCatalogController::normalizeQuery( array() )['orderby'] );
		self::assertSame( 'relevance', MobileCatalogController::normalizeQuery( array( 'search' => 'Arduino' ) )['orderby'] );
		foreach ( array( 'date', 'price', 'popularity', 'title', 'relevance' ) as $sort ) {
			$actual = MobileCatalogController::normalizeQuery(
				array(
					'search'  => 'Arduino',
					'orderby' => $sort,
					'order'   => 'ASC',
				)
			);
			self::assertSame( $sort, $actual['orderby'] );
			self::assertSame( 'ASC', $actual['order'] );
		}
		self::assertSame(
			'date',
			MobileCatalogController::normalizeQuery(
				array(
					'search'  => 'Arduino',
					'orderby' => 'DROP TABLE',
				)
			)['orderby']
		);
	}

	/** Large overlapping configuration cannot discard the customer's literal query. */
	public function test_alias_bound_always_retains_the_original_query(): void {
		$terms = Policy::terms( 'zzzz', array( array( 'zzzz', 'a', 'b', 'c', 'd', 'e' ), array( 'zzzz', 'f', 'g', 'h', 'i', 'j' ), array( 'zzzz', 'k', 'l', 'm', 'n', 'o' ) ) );
		self::assertCount( 12, $terms );
		self::assertContains( 'zzzz', $terms );
	}
}
