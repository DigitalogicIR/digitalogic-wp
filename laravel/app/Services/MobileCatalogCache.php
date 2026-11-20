<?php
/**
 * Bounded public catalog responses in the existing WordPress object cache.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 adapter contract.

/**
 * Direct-mapped cache: at most 256 payloads, each at most 256 KiB of JSON.
 *
 * The 64 MiB payload ceiling excludes PHP/Redis serialization and key overhead.
 * Every complete caller key is retained for collision checking. Keys must be
 * public catalog/generation keys and data must already be a public projection;
 * this adapter never reads cookies, credentials, sessions or request headers.
 * The configured WordPress cache owns expiry and Redis persistence. There is
 * no Laravel cache, registry, global invalidation or key-space scan here.
 */
final class MobileCatalogCache {
	public const GROUP             = 'digitalogic-mobile-v2';
	public const SLOT_COUNT        = 256;
	public const TTL_SECONDS       = 60;
	public const MAX_PAYLOAD_BYTES = 262144;

	/**
	 * Read only an exact-key array; another key in the same slot is a miss.
	 *
	 * @param string $key Complete public catalog/generation key.
	 * @return array|null Unchanged public payload or cache miss.
	 */
	public static function get( string $key ): ?array {
		try {
			$envelope = wp_cache_get( self::slot( $key ), self::GROUP );
			if ( ! is_array( $envelope ) || ! is_string( $envelope['key'] ?? null ) || $key !== $envelope['key'] || ! is_array( $envelope['data'] ?? null ) ) {
				return null;
			}
			return $envelope['data'];
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Admit a complete small public projection, or leave the existing slot intact.
	 *
	 * @param string $key Complete public catalog/generation key.
	 * @param array  $data Public JSON-compatible catalog projection only.
	 * @return bool Whether the canonical object cache accepted the entire payload.
	 */
	public static function put( string $key, array $data ): bool {
		if ( ! self::jsonData( $data ) ) {
			return false;
		}
		$json = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Admission must reject invalid UTF-8 instead of repairing a different payload.
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return false;
		}
		try {
			return (bool) wp_cache_set(
				self::slot( $key ),
				array(
					'key'  => $key,
					'data' => $data,
				),
				self::GROUP,
				self::TTL_SECONDS
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * The first full-hash byte selects exactly one of the 256 fixed slots.
	 *
	 * @param string $key Complete caller key, never truncated before hashing.
	 * @return string Fixed-size cache slot name.
	 */
	private static function slot( string $key ): string {
		return 'slot:' . substr( hash( 'sha256', $key ), 0, 2 );
	}

	/**
	 * Reject hidden serialized object state and recursive/non-JSON structures.
	 *
	 * @param mixed $value Public JSON value.
	 * @param int   $depth Bounded nesting depth.
	 * @return bool Whether the entire value contains only plain JSON data.
	 */
	private static function jsonData( $value, int $depth = 0 ): bool {
		if ( $depth > 64 ) {
			return false;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( ! self::jsonData( $child, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || is_string( $value ) || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
	}
}
