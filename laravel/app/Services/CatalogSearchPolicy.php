<?php
/**
 * Bounded query normalization for canonical catalog discovery, never identity writes.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reusable PSR-4 query policy.

/** Equivalent query words expand candidates before SQL pagination. */
final class CatalogSearchPolicy {
	private const MAX_CONFIG_BYTES      = 65535;
	private const MAX_GROUPS            = 20;
	private const MAX_ALIASES_PER_GROUP = 24;
	private const MAX_EXPANDED_ALIASES  = 12;
	private const MAX_QUERY_TERMS       = 12;
	private const MAX_WOODMART_LINES    = 240;
	private const MAX_WOODMART_BYTES    = 524288;

	/**
	 * Normalize presentation differences while retaining meaningful model punctuation.
	 *
	 * @param string $value Customer search input.
	 * @return string Bounded comparison text, not a product identity.
	 */
	public static function normalize( string $value ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_KC );
			$value      = is_string( $normalized ) ? $normalized : $value;
		}
		$value = strtr( $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
		$value = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $value );
		$value = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', $value );
		$value = preg_replace( '/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', ' ', $value );
		return mb_substr( mb_strtolower( trim( sanitize_text_field( $value ) ), 'UTF-8' ), 0, 120 );
	}

	/**
	 * Expand configured linguistic equivalents symmetrically, with fixed work bounds.
	 *
	 * @param string     $search Normalized or raw search input.
	 * @param array|null $groups Explicit policy for fixtures; production uses the canonical WordPress setting.
	 * @return array Unique sorted query alternatives.
	 */
	public static function terms( string $search, ?array $groups = null ): array {
		$search = self::normalize( $search );
		if ( '' === $search ) {
			return array();
		}
		$groups ??= self::groups( self::configured() );
		$groups = self::mergedGroups( $groups );
		$terms  = array( $search );
		foreach ( array_slice( $groups, 0, self::MAX_GROUPS ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$aliases = self::expandedGroup( $group );
			foreach ( $aliases as $alias ) {
				$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $alias, '/' ) . '(?![\p{L}\p{N}])/u';
				if ( ! preg_match( $pattern, $search ) ) {
					continue;
				}
				foreach ( $aliases as $replacement ) {
					$terms[] = preg_replace_callback( $pattern, static fn() => $replacement, $search );
				}
			}
			$terms = array_values( array_unique( $terms ) );
			if ( count( $terms ) >= self::MAX_QUERY_TERMS ) {
				break;
			}
		}
		$expanded = $terms;
		foreach ( $terms as $term ) {
			$expanded = array_merge( $expanded, self::separatorVariants( $term ) );
		}
		$terms        = array_values( array_unique( $expanded ) );
		$alternatives = array_values( array_diff( $terms, array( $search ) ) );
		sort( $alternatives, SORT_STRING );
		$terms = array_merge( array( $search ), array_slice( $alternatives, 0, self::MAX_QUERY_TERMS - 1 ) );
		sort( $terms, SORT_STRING );
		return $terms;
	}

	/**
	 * Read the one persisted alias registry without consuming WoodMart's filtered view.
	 *
	 * @return string Canonical newline-separated groups saved by the administrator.
	 */
	public static function configured(): string {
		if ( function_exists( 'get_option' ) ) {
			$options = get_option( 'xts-woodmart-options', null );
			if ( is_array( $options ) && isset( $options['search_synonyms'] ) && is_scalar( $options['search_synonyms'] ) ) {
				return (string) $options['search_synonyms'];
			}
		}

		return function_exists( 'woodmart_get_opt' ) ? (string) woodmart_get_opt( 'search_synonyms' ) : '';
	}

	/**
	 * Stable cache fence for the raw administrator-owned setting.
	 *
	 * @param string|null $configured Explicit configuration for tests.
	 * @return string SHA-256 revision.
	 */
	public static function revision( ?string $configured = null ): string {
		return hash( 'sha256', $configured ?? self::configured() );
	}

	/**
	 * Project symmetric groups into WoodMart's one-way runtime syntax.
	 *
	 * The saved textarea remains concise and editable. This generated view is
	 * request-local and gives every normalized alias the other group members.
	 *
	 * @param string $configured Canonical saved configuration.
	 * @return string Directional WoodMart configuration.
	 */
	public static function woodmartConfig( string $configured ): string {
		static $cache = array();

		$cache_key = hash( 'sha256', $configured );
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}
		$lines = array();
		$bytes = 0;
		foreach ( self::mergedGroups( self::groups( $configured ) ) as $group ) {
			$aliases = self::expandedGroup( $group );
			if ( count( $aliases ) < 2 ) {
				continue;
			}
			foreach ( $aliases as $alias ) {
				$replacements = array_values( array_diff( $aliases, array( $alias ) ) );
				if ( ! empty( $replacements ) ) {
					$line       = $alias . ' = ' . implode( ',', $replacements );
					$line_bytes = strlen( $line ) + ( empty( $lines ) ? 0 : strlen( PHP_EOL ) );
					if ( count( $lines ) >= self::MAX_WOODMART_LINES || $bytes + $line_bytes > self::MAX_WOODMART_BYTES ) {
						break 2;
					}
					$lines[] = $line;
					$bytes  += $line_bytes;
				}
			}
		}

		$compiled = implode( PHP_EOL, array_values( array_unique( $lines ) ) );
		$cache[ $cache_key ] = $compiled;
		if ( count( $cache ) > 8 ) {
			array_shift( $cache );
		}

		return $compiled;
	}

	/**
	 * Build exact separator variants for model discovery, never fuzzy spellings.
	 *
	 * `model pro`, `model-pro` and `modelpro` are equivalent query forms;
	 * `model prototype` remains unrelated. Very short compact forms are not
	 * expanded so voltage signs and one-character model punctuation stay exact.
	 *
	 * @param string $term Normalized term.
	 * @return string[]
	 */
	public static function separatorVariants( string $term ): array {
		$term = self::normalize( $term );
		if ( '' === $term || 1 !== preg_match( '/[\p{White_Space}\p{Dash}_]/u', $term ) ) {
			return array( $term );
		}

		$compact = preg_replace( '/[\p{White_Space}\p{Dash}_]+/u', '', $term );
		if ( ! is_string( $compact ) || mb_strlen( $compact, 'UTF-8' ) < 4 ) {
			return array( $term );
		}

		return array_values(
			array_unique(
				array(
					$term,
					trim( preg_replace( '/[\p{White_Space}\p{Dash}_]+/u', ' ', $term ) ),
					trim( preg_replace( '/[\p{White_Space}\p{Dash}_]+/u', '-', $term ), '-' ),
					$compact,
				)
			)
		);
	}

	/**
	 * Read the existing Woodmart admin setting rather than creating another registry.
	 *
	 * @param string $configured Canonical newline-separated term = alias,alias setting.
	 * A bare canonical term and `canonical =` are both valid groups with zero
	 * explicit alternates. Separator variants are derived later at query time.
	 *
	 * @return array Bounded independent equivalent-term groups.
	 */
	public static function groups( string $configured ): array {
		$groups     = array();
		$configured = mb_strcut( $configured, 0, self::MAX_CONFIG_BYTES, 'UTF-8' );
		$lines      = preg_split( '/\R/u', $configured );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		foreach ( array_slice( $lines, 0, self::MAX_GROUPS ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = explode( '=', $line, 2 );
			$group = array( trim( $parts[0] ) );
			if ( isset( $parts[1] ) ) {
				$group = array_merge( $group, array_map( 'trim', explode( ',', $parts[1] ) ) );
			}
			$group = array_values( array_filter( array_slice( $group, 0, self::MAX_ALIASES_PER_GROUP ), static fn( $alias ) => '' !== $alias ) );
			if ( ! empty( $group ) ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * Normalize every configured term and derive exact separator variants.
	 *
	 * @param array $group One administrator-owned group.
	 * @return string[] Bounded aliases for matching and runtime projection.
	 */
	private static function expandedGroup( array $group ): array {
		$aliases = array();
		foreach ( array_slice( $group, 0, self::MAX_ALIASES_PER_GROUP ) as $alias ) {
			if ( ! is_string( $alias ) ) {
				continue;
			}
			$alias = self::normalize( $alias );
			if ( '' === $alias || mb_strlen( $alias, 'UTF-8' ) > 40 ) {
				continue;
			}
			$aliases = array_merge( $aliases, self::separatorVariants( $alias ) );
			$aliases = array_values( array_unique( $aliases ) );
			if ( count( $aliases ) >= self::MAX_EXPANDED_ALIASES ) {
				break;
			}
		}

		return array_slice( $aliases, 0, self::MAX_EXPANDED_ALIASES );
	}

	/**
	 * Merge overlapping groups so every search surface resolves collisions alike.
	 *
	 * @param array $groups Parsed administrator-owned groups.
	 * @return array Deterministic normalized connected components.
	 */
	private static function mergedGroups( array $groups ): array {
		$merged = array();
		foreach ( array_slice( $groups, 0, self::MAX_GROUPS ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$aliases = self::expandedGroup( $group );
			if ( empty( $aliases ) ) {
				continue;
			}

			$index = 0;
			while ( $index < count( $merged ) ) {
				$existing = $merged[ $index ];
				if ( array_intersect( $aliases, $existing ) ) {
					$aliases = array_values( array_unique( array_merge( $aliases, $existing ) ) );
					array_splice( $merged, $index, 1 );
					$index = 0;
					continue;
				}
				++$index;
			}
			$merged[] = array_slice( $aliases, 0, self::MAX_EXPANDED_ALIASES );
		}

		return $merged;
	}
}
