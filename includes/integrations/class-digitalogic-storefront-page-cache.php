<?php
/**
 * Committed product HTML cache invalidation.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Purge one committed batch without Rocket's per-request clean_post guard. */
final class Digitalogic_Storefront_Page_Cache {

	/**
	 * Purge product, parent and shared storefront pages once per delivery.
	 *
	 * Rocket's purge APIs return void; success means the provider completed without
	 * an exception or explicit failure, not a filesystem deletion receipt.
	 *
	 * @param int[] $product_ids Exact committed product/variation IDs.
	 * @return bool Whether the delivery may proceed.
	 */
	public static function purge( array $product_ids ): bool {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		if ( ! $product_ids ) {
			return true;
		}
		if ( ! function_exists( 'rocket_get_purge_urls' ) || ! function_exists( 'rocket_clean_files' ) || ! function_exists( 'rocket_clean_home' ) ) {
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				error_log( 'Digitalogic storefront page cache: required purge provider unavailable; delivery retained.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Bounded operational delivery failure.
			}
			return ! defined( 'WP_CACHE' ) || ! WP_CACHE;
		}

		try {
			$posts = array();
			foreach ( $product_ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
					return false;
				}
				$posts[ $id ] = $post;
				if ( 'product_variation' === $post->post_type ) {
					$parent = get_post( $post->post_parent );
					if ( ! $parent || 'product' !== $parent->post_type ) {
						return false;
					}
					$posts[ $parent->ID ] = $parent;
				}
			}

			$urls = array();
			foreach ( $posts as $id => $post ) {
				$urls = array_merge( $urls, (array) apply_filters( 'rocket_post_purge_urls', rocket_get_purge_urls( $id, $post ), $post ) );
				if ( 'product' !== $post->post_type ) {
					continue;
				}
				foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
					$tax = get_taxonomy( $taxonomy );
					if ( ! $tax || ! $tax->public ) {
						continue;
					}
					$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( is_wp_error( $terms ) ) {
						return false;
					}
					$term_ids = $terms;
					foreach ( $terms as $term_id ) {
						$term_ids = array_merge( $term_ids, get_ancestors( $term_id, $taxonomy, 'taxonomy' ) );
					}
					foreach ( array_unique( $term_ids ) as $term_id ) {
						$url = get_term_link( (int) $term_id, $taxonomy );
						if ( is_wp_error( $url ) ) {
							return false;
						}
						// Rocket recursively removes the archive, including pagination/query caches.
						$urls[] = $url;
					}
				}
			}
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$urls[] = wc_get_page_permalink( 'shop' );
			}
			$urls[] = home_url( '/catalog/' );
			// Rocket resolves the directory from path alone, ignoring query/fragment.
			// Draft/plain permalinks must never turn into recursive homepage purges.
			// Its dedicated API removes only homepage files, parameters and pagination.
			$home = wp_parse_url( home_url( '/' ) );
			$urls = array_values(
				array_unique(
					array_filter(
						$urls,
						static function ( $url ) use ( $home ) {
							if ( ! is_string( $url ) || '' === $url ) {
								return false;
							}
							$parsed = wp_parse_url( $url );
							if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
								return false;
							}
							$path = rtrim( rawurldecode( $parsed['path'] ?? '/' ), '/' );
							if ( '' === $path ) {
								return false;
							}
							return strtolower( $parsed['host'] ) !== strtolower( $home['host'] ?? '' )
								|| $path !== rtrim( rawurldecode( $home['path'] ?? '/' ), '/' );
						}
					)
				)
			);
			if ( false === rocket_clean_files( $urls ) ) {
				return false;
			}
			return false !== rocket_clean_home();
		} catch ( \Throwable $error ) {
			error_log( 'Digitalogic storefront page cache: provider exception; delivery retained.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Bounded operational delivery failure.
			return false;
		}
	}
}
