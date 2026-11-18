<?php
/**
 * Standalone subprocess fixture: no WordPress, network or production writes.
 *
 * @package Digitalogic
 */

// phpcs:disable Squiz.Commenting.FunctionComment, Generic.CodeAnalysis.UnusedFunctionParameter -- Minimal WordPress API doubles preserve real signatures.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is deliberately absent.
define( 'ABSPATH', __DIR__ );
$scenario   = $argv[1];
$planned    = array();
$files      = array();
$home_calls = 0;
if ( 'cache_enabled' === $scenario || 'cache_disabled' === $scenario ) {
	define( 'WP_CACHE', 'cache_enabled' === $scenario );
} else {
	function rocket_get_purge_urls( $id, $post ) {
		$GLOBALS['planned'][] = $id;
		return array( 'https://example.test/product/' . $id . '/', 'https://example.test/shop/index(.*).html', 'https://example.test/shop/page' );
	}
	function rocket_clean_files( $urls ) {
		$GLOBALS['files'][] = $urls;
		if ( 'throw' === $GLOBALS['scenario'] ) {
			throw new RuntimeException( 'Injected purge failure' );
		}
		return 'false' === $GLOBALS['scenario'] ? false : null;
	}
	function rocket_clean_home() {
		++$GLOBALS['home_calls'];
	}
}
function absint( $id ) {
	return abs( (int) $id ); }
function get_post( $id ) {
	if ( 'missing_product' === $GLOBALS['scenario'] ) {
		return null; }
	return (object) array(
		'ID'          => $id,
		'post_type'   => 11 === $id ? 'product_variation' : 'product',
		'post_parent' => 10,
	);
}
function apply_filters( $hook, $urls, $post ) {
	return array_merge( $urls, array( 'https://example.test/custom/', 'https://example.test/', 'https://example.test/?post_type=product&p=14701', 'https://example.test?post_type=product&p=14701#preview', 'https://example.test/#fragment', 'https://example.test/%2F?draft=1' ) ); }
function wp_parse_url( $url ) {
	return parse_url( $url ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Isolated WordPress API double.
function get_object_taxonomies( $type ) {
	return array( 'product_cat', 'product_visibility' ); }
function get_taxonomy( $taxonomy ) {
	return (object) array( 'public' => 'product_cat' === $taxonomy ); }
function wp_get_object_terms( $id, $taxonomy, $args ) {
	return 'term_error' === $GLOBALS['scenario'] ? new RuntimeException() : array( 20 ); }
function get_ancestors( $id, $taxonomy, $type ) {
	return array( 2 ); }
function get_term_link( $id, $taxonomy ) {
	return 'https://example.test/' . $taxonomy . '/' . $id . '/'; }
function is_wp_error( $value ) {
	return $value instanceof RuntimeException; }
function wc_get_page_permalink( $page ) {
	return 'https://example.test/shop/'; }
function home_url( $path ) {
	return 'https://example.test' . $path; }
function untrailingslashit( $url ) {
	return rtrim( $url, '/' ); }
require dirname( __DIR__, 2 ) . '/includes/integrations/class-digitalogic-storefront-page-cache.php';
$result = Digitalogic_Storefront_Page_Cache::purge( array( 11, 11, 10, 12 ) );
if ( 'repeat' === $scenario ) {
	$result = Digitalogic_Storefront_Page_Cache::purge( array( 11, 10, 12 ) ) && $result;
}
echo json_encode( compact( 'result', 'planned', 'files', 'home_calls' ) );
