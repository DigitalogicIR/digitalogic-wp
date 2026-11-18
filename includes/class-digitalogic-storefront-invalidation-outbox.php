<?php
/**
 * Transaction-owned, metadata-only fallback for public catalog invalidation.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable, coalesced invalidation whose publication follows canonical COMMIT. */
final class Digitalogic_Storefront_Invalidation_Outbox {

	public const OPTION       = 'digitalogic_storefront_invalidation_outbox_v1';
	public const RETRY_HOOK   = 'digitalogic_storefront_invalidation_retry_v1';
	private const RETRY_DELAY = 2;
	/**
	 * Prevent recursive listener delivery in the same request.
	 *
	 * @var bool
	 */
	private static $dispatching = false;

	/** Register recovery without a recurring idle poll. */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'recover' ), 30 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * Stage inside the caller's open SQL transaction and ordered writer locks.
	 *
	 * Never starts/commits a transaction or publishes a provisional event. The
	 * caller MUST roll back on WP_Error. A newer full invalidation subsumes the
	 * previous pending marker. Keep the acknowledged generation to avoid reuse.
	 *
	 * @return true|WP_Error
	 */
	public static function stage() {
		global $wpdb;
		$row = self::read_row( true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$previous = null === $row ? null : self::decode( $row );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}
		try {
			$token = 'sha256:' . hash( 'sha256', random_bytes( 32 ) );
		} catch ( Throwable $exception ) {
			return self::error( 'token_failed' );
		}
		$record = array(
			'generation' => (int) ( $previous['generation'] ?? 0 ) + 1,
			'token'      => $token,
			'staged_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'pending'    => true,
		);
		$raw    = maybe_serialize( $record );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Same SQL transaction as the canonical owner; no WordPress option hooks or precommit cache publication.
		$written = null === $row
			? $wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => self::OPTION,
					'option_value' => $raw,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			)
			: $wpdb->update(
				$wpdb->options,
				array(
					'option_value' => $raw,
					'autoload'     => 'no',
				),
				array( 'option_name' => self::OPTION ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( false === $written || self::read_row() !== $raw ) {
			return self::error( 'stage_failed' );
		}
		return true;
	}

	/** Repair a lost one-shot schedule only when durable work exists. */
	public static function recover() {
		$row = self::read_row();
		if ( null === $row || is_wp_error( $row ) ) {
			return;
		}
		$record = self::decode( $row );
		if ( ! is_wp_error( $record ) && $record['pending'] ) {
			self::schedule_retry();
		}
	}

	/**
	 * Immediately deliver after COMMIT and both writer locks have released.
	 *
	 * Two attempts bound immediate work; a pending marker repairs lost cron
	 * scheduling on the next normal init. Transport failures never change the
	 * canonical transaction's success. Concurrent dispatchers are harmless:
	 * Panel deduplicates, and ACK compares the complete observed marker.
	 *
	 * @return bool Whether no observed invalidation remains pending.
	 */
	public static function dispatch() {
		if ( self::$dispatching || self::writer_is_locked() ) {
			return false;
		}
		self::$dispatching = true;
		try {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			for ( $attempt = 0; $attempt < 2; ++$attempt ) {
				$row = self::read_row();
				if ( null === $row ) {
					return true;
				}
				if ( is_wp_error( $row ) ) {
					break;
				}
				$record = self::decode( $row );
				if ( is_wp_error( $record ) ) {
					break;
				}
				if ( ! $record['pending'] ) {
					return true;
				}
				try {
					$result = Digitalogic_Panel::record_event_result(
						'catalog.updated',
						array(
							'idempotency_key' => $record['token'],
							'generation'      => $record['generation'],
							'staged_at'       => $record['staged_at'],
						)
					);
				} catch ( Throwable $exception ) {
					continue;
				}
				if ( is_wp_error( $result ) || true !== ( $result['wake_guaranteed'] ?? false ) || empty( $result['event']['id'] ) ) {
					continue;
				}
				if ( ! self::acknowledge( $row, $record ) ) {
					continue;
				}
				// Re-read: a concurrent transaction may already have staged again.
			}
			$row    = self::read_row();
			$record = is_string( $row ) ? self::decode( $row ) : $row;
			if ( null === $record || ( is_array( $record ) && ! $record['pending'] ) ) {
				return true;
			}
			self::schedule_retry();
			return false;
		} finally {
			self::$dispatching = false;
		}
	}

	/**
	 * ACK only the marker whose downstream queue and wake were verified.
	 *
	 * @param string $raw Exact observed SQL value.
	 * @param array  $record Decoded observed marker.
	 */
	private static function acknowledge( $raw, $record ) {
		global $wpdb;
		$record['pending'] = false;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact-value CAS protects a newer committed generation.
		$written = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $record ) ),
			array(
				'option_name'  => self::OPTION,
				'option_value' => $raw,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return false !== $written;
	}

	/**
	 * Read SQL directly so object-cache or rollback residue cannot emit work.
	 *
	 * @param bool $for_update Lock the row inside the caller's transaction.
	 */
	private static function read_row( $for_update = false ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return self::error( 'storage_unavailable' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Trusted wpdb table, fixed optional locking suffix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s" . ( $for_update ? ' FOR UPDATE' : '' ), self::OPTION ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return self::error( 'read_failed' );
		}
		return is_array( $row ) ? (string) $row['option_value'] : null;
	}

	/**
	 * Reject corruption rather than silently dropping an invalidation.
	 *
	 * @param string $raw Serialized marker from SQL.
	 */
	private static function decode( $raw ) {
		$record = maybe_unserialize( $raw );
		if ( ! is_array( $record ) || ! is_int( $record['generation'] ?? null ) || $record['generation'] < 1 || ! is_bool( $record['pending'] ?? null ) || 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $record['token'] ?? '' ) ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', (string) ( $record['staged_at'] ?? '' ) ) ) {
			return self::error( 'record_invalid' );
		}
		return $record;
	}

	/** Avoid publishing even if an unrelated caller invokes recovery early. */
	private static function writer_is_locked() {
		return ( class_exists( 'Digitalogic_Pricing_Service', false ) && Digitalogic_Pricing_Service::instance()->source_delivery_lock_is_owned() )
			|| ( class_exists( 'Digitalogic_Product_Sync_Receiver', false ) && Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
	}

	/** A single pending action suffices because the marker coalesces safely. */
	private static function schedule_retry() {
		if ( false !== wp_next_scheduled( self::RETRY_HOOK, array() ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK, array() );
	}

	/**
	 * Return a bounded diagnostic without business or source values.
	 *
	 * @param string $suffix Internal error classification.
	 */
	private static function error( $suffix ) {
		return new WP_Error( 'digitalogic_storefront_invalidation_' . $suffix, 'The durable storefront invalidation could not be verified.' );
	}
}
