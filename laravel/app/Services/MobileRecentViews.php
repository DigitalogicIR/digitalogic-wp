<?php
/**
 * Shared customer history projected into the existing Woo and Woodmart cookies.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 adapter contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Current owner metadata and merge locks must bypass stale object caches.

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;

/** One canonical WordPress customer record, never a parallel mobile customer. */
final class MobileRecentViews {

	const META         = '_digitalogic_recently_viewed';
	const LIMIT        = 30;
	const RETENTION    = 90 * DAY_IN_SECONDS;
	const CLEANUP_HOOK = 'digitalogic_customer_history_cleanup';

	/** Whether the reviewed account activity rollout is available. */
	public static function enabled(): bool {
		return (bool) get_option( 'digitalogic_mobile_account_activity', false );
	}

	/**
	 * Strict client ordering hints; no account, stock or product authority comes from time.
	 *
	 * @param mixed $input Incremental view array.
	 * @param int   $now Current UTC epoch.
	 * @return array|null Product-to-epoch map or invalid input.
	 */
	public static function normalize( $input, int $now ): ?array {
		if ( ! is_array( $input ) || ! array_is_list( $input ) || count( $input ) > self::LIMIT ) {
			return null;
		}
		$views = array();
		foreach ( $input as $view ) {
			if ( ! is_array( $view ) || count( $view ) !== 2 || array_diff( array_keys( $view ), array( 'product_id', 'viewed_at' ) ) || ! is_int( $view['product_id'] ?? null ) || $view['product_id'] < 1 || ! is_string( $view['viewed_at'] ?? null ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)\z/D', $view['viewed_at'] ) ) {
				return null;
			}
			try {
				$date   = new \DateTimeImmutable( $view['viewed_at'] );
				$errors = \DateTimeImmutable::getLastErrors();
				if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
					return null;
				}
				$time = $date->getTimestamp();
			} catch ( \Throwable $error ) {
				return null;
			}
			if ( $time > $now + 300 ) {
				continue;
			}
			if ( $time >= $now - self::RETENTION ) {
				$id           = $view['product_id'];
				$views[ $id ] = max( $views[ $id ] ?? 0, min( $time, $now ) );
			}
		}
		return $views;
	}

	/**
	 * Merge retries and concurrent device intent monotonically, with a stable tie-break.
	 *
	 * @param array $current Existing product-to-epoch map.
	 * @param array $incoming Incremental map.
	 * @param int   $now Current epoch.
	 * @return array Bounded newest-first map.
	 */
	public static function combine( array $current, array $incoming, int $now ): array {
		$merged = array();
		foreach ( array( $current, $incoming ) as $rows ) {
			foreach ( $rows as $id => $time ) {
				if ( ctype_digit( (string) $id ) && (int) $id > 0 && is_int( $time ) && $time >= $now - self::RETENTION && $time <= $now + 300 ) {
					$merged[ (int) $id ] = max( $merged[ (int) $id ] ?? 0, min( $time, $now ) );
				}
			}
		}
		uksort( $merged, static fn( $left, $right ) => $merged[ $right ] === $merged[ $left ] ? ( $left <=> $right ) : ( $merged[ $right ] <=> $merged[ $left ] ) );
		return array_slice( $merged, 0, self::LIMIT, true );
	}

	/**
	 * Current canonical metadata, also used by the reviewed identity merge snapshot.
	 *
	 * @param int $user_id Exact WordPress owner.
	 * @return array Current retained map.
	 * @throws \RuntimeException If SQL cannot provide an authoritative read.
	 */
	public static function read( int $user_id ): array {
		return self::combine( self::raw( $user_id ), array(), time() );
	}

	/**
	 * Read only this adapter's own metadata, including expired entries for cleanup.
	 *
	 * @param int $user_id Exact owner.
	 * @return array Stored map.
	 * @throws \RuntimeException If the provider read is unavailable or ambiguous.
	 */
	private static function raw( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s", $user_id, self::META ) );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error || count( $rows ) > 1 ) {
			throw new \RuntimeException( 'History metadata is unavailable.' );
		}
		$data = $rows ? maybe_unserialize( $rows[0] ) : array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Project only published public product identities; unavailable stock is still a view.
	 *
	 * @param array $views Canonical ordered map.
	 * @return array Wire snapshot.
	 */
	public static function snapshot( array $views ): array {
		$items   = array();
		$catalog = new MobileCatalogController();
		foreach ( $views as $id => $time ) {
			if ( $catalog->isPublicProductId( (int) $id ) ) {
				$items[] = array(
					'product_id' => (int) $id,
					'viewed_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
				);
			}
		}
		return array(
			'items'    => $items,
			'revision' => hash( 'sha256', wp_json_encode( $items ) ),
		);
	}

	/**
	 * Persist only a changed record; caller holds the canonical identity owner lock.
	 *
	 * @param int   $user_id Exact owner.
	 * @param array $views Reviewed new map.
	 * @throws \RuntimeException When readback cannot confirm persistence.
	 */
	public static function save( int $user_id, array $views ): void {
		if ( self::raw( $user_id ) === $views ) {
			return;
		}
		wp_cache_delete( $user_id, 'user_meta' );
		update_user_meta( $user_id, self::META, $views );
		if ( self::read( $user_id ) !== $views ) {
			throw new \RuntimeException( 'History readback differs.' );
		}
		self::emit( $user_id, 'history', 'recently_viewed' );
	}

	/** Schedule bounded cleanup only while account history is enabled. */
	public static function schedule(): void {
		if ( self::enabled() && ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/** Remove expired history in small owner-locked batches, never reading other metadata. */
	public static function cleanup(): void {
		global $wpdb;
		$cursor = (int) get_option( 'digitalogic_customer_history_cleanup_cursor', 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id,user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND umeta_id>%d ORDER BY umeta_id LIMIT 200", self::META, $cursor ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return;
		}
		foreach ( $rows as $row ) {
			$owner = (int) $row['user_id'];
			$lock  = 'dgl-link-user-' . $owner;
			if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
				break;
			}
			try {
				self::save( $owner, self::read( $owner ) );
				$cursor = (int) $row['umeta_id'];
			} catch ( \Throwable $error ) {
				break;
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
		update_option( 'digitalogic_customer_history_cleanup_cursor', count( $rows ) < 200 ? 0 : $cursor, false );
	}

	/**
	 * Emit reduced owner-only invalidation, deferring during canonical account merges.
	 *
	 * @param int    $user_id Exact owner.
	 * @param string $kind Closed event kind.
	 * @param string $scope Closed client scope.
	 */
	public static function emit( int $user_id, string $kind, string $scope ): void {
		if ( $user_id < 1 || ! in_array( $kind, array( 'history', 'favorites' ), true ) || ! class_exists( 'Digitalogic_Panel' ) ) {
			return;
		}
		$args = array(
			'mobile.' . $kind . '.updated',
			array(
				'user_id'  => $user_id,
				'scope'    => $scope,
				'audience' => array( 'users' => array( $user_id ) ),
			),
		);
		if ( ! MobileMergeEffects::defer( 'panel', $args ) ) {
			\Digitalogic_Panel::record_event( ...$args );
		}
	}

	/**
	 * Incremental account update, serialized with merge/link operations.
	 *
	 * @param int           $user_id Exact owner.
	 * @param array         $views Validated map.
	 * @param callable|null $authority Recheck the initiating session after waiting for the lock.
	 * @return array|\WP_Error Wire snapshot or explicit error.
	 */
	public static function sync( int $user_id, array $views, ?callable $authority = null ) {
		global $wpdb;
		$lock = 'dgl-link-user-' . $user_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) ) {
			return new \WP_Error( 'history_busy', 'کمی بعد دوباره تلاش کنید.', array( 'status' => 409 ) );
		}
		try {
			wp_cache_delete( $user_id, 'user_meta' );
			if ( $user_id < 1 || get_user_meta( $user_id, '_digitalogic_merged_into', true ) || ( $authority && ! $authority() ) ) {
				return new \WP_Error( 'unauthorized', 'دوباره وارد حساب شوید.', array( 'status' => 401 ) );
			}
			$catalog = new MobileCatalogController();
			foreach ( $views as $id => $time ) {
				if ( ! $catalog->isPublicProductId( (int) $id ) ) {
					unset( $views[ $id ] );
				}
			}
			$merged = self::combine( self::read( $user_id ), $views, time() );
			self::save( $user_id, $merged );
			return self::snapshot( $merged );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'history_unavailable', 'بازدیدهای اخیر موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
