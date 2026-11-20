<?php
/**
 * Bounded owner-only replay of the existing durable shopper event queue.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 adapter contracts.

use Digitalogic\Laravel\Http\Controllers\MobileIdentityController;

/** Reuses canonical event IDs and the WebSocket privacy projection over authenticated HTTP. */
final class MobileAccountEvents {

	private const MAX_BATCH      = 100;
	private const PRIVATE_NAMES  = array(
		'cart.updated',
		'favorites.updated',
		'history.updated',
		'profile.updated',
		'order.updated',
		'ticket.updated',
		'message.created',
	);
	private const INTERNAL_NAMES = array(
		'mobile.cart.updated',
		'mobile.favorites.updated',
		'mobile.history.updated',
		'user.updated',
		'mobile.order.updated',
		'mobile.ticket.updated',
		'mobile.message.created',
	);

	/** Gate the adapter on the existing native authority and its own rollout. */
	public static function enabled(): bool {
		return (bool) get_option( 'digitalogic_mobile_account_activity', false ) && MobileIdentityController::enabled();
	}

	/**
	 * Consume at most 100 retained rows, including events hidden from this owner.
	 *
	 * @param array $events Retained canonical events, not client data.
	 * @param int   $latest Latest sequence captured before reading retained events.
	 * @param int   $cursor Last consumed global sequence.
	 * @param int   $user_id Verified canonical owner.
	 * @return array Private envelopes and replay metadata.
	 */
	public static function batch( array $events, int $latest, int $cursor, int $user_id ): array {
		$latest = max( 0, $latest );
		$rows   = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$id = absint( $event['id'] ?? 0 );
			if ( $id > 0 && $id <= $latest ) {
				$rows[ $id ] = $event;
			}
		}
		ksort( $rows, SORT_NUMERIC );
		$minimum = $rows ? (int) array_key_first( $rows ) : $latest + 1;
		if ( $cursor <= 0 || $cursor > $latest || $cursor < $minimum - 1 ) {
			return self::result( array(), $latest, false, true );
		}
		$pending = array_filter( $rows, static fn( $id ) => $id > $cursor, ARRAY_FILTER_USE_KEY );
		$output  = array();
		foreach ( array_slice( $pending, 0, self::MAX_BATCH, true ) as $id => $event ) {
			$cursor = (int) $id;
			if ( ! in_array( $event['name'] ?? '', self::INTERNAL_NAMES, true ) ) {
				continue;
			}
			$projected = $user_id > 0 ? \Digitalogic_WebSocket_Server::project_shopper_event( $event, $user_id ) : null;
			if ( is_array( $projected ) && in_array( $projected['name'] ?? '', self::PRIVATE_NAMES, true ) ) {
				$output[] = $projected;
			}
		}
		$has_more = count( $pending ) > self::MAX_BATCH;
		return self::result( $output, $has_more ? $cursor : $latest, $has_more, false );
	}

	/**
	 * Poll without extending account authority or leaking events after revocation.
	 *
	 * @param int      $cursor Last consumed global sequence.
	 * @param int      $wait Maximum seconds, from zero to twenty.
	 * @param callable $authority Rechecks the exact captured session; must return true.
	 * @param int      $user_id Verified canonical owner fixed by the controller.
	 * @return array|\WP_Error Private batch or a closed failure.
	 */
	public static function poll( int $cursor, int $wait, callable $authority, int $user_id ) {
		if ( $cursor < 0 || $wait < 0 || $wait > 20 ) {
			return self::error( 'invalid_account_events', 400 );
		}
		if ( ! self::enabled() ) {
			return self::error( 'account_events_unavailable', 503 );
		}
		$deadline = microtime( true ) + $wait;
		try {
			do {
				if ( $user_id <= 0 || true !== $authority() ) {
					return self::error( 'unauthorized', 401 );
				}
				// HTTP requests also keep an option cache; each long-poll iteration must see new commits.
				foreach ( array( 'digitalogic_panel_events', 'digitalogic_panel_event_sequence' ) as $key ) {
					wp_cache_delete( $key, 'options' );
				}
				// Bound the sequence before the queue read so a later append cannot be skipped.
				$latest = \Digitalogic_Panel::get_latest_event_id();
				$events = \Digitalogic_Panel::get_events_since( 0 );
				$result = self::batch( $events, $latest, $cursor, $user_id );
				$cursor = $result['cursor'];
				if ( $result['reset'] || $result['has_more'] || $result['events'] || microtime( true ) >= $deadline ) {
					return true === $authority() ? $result : self::error( 'unauthorized', 401 );
				}
				usleep( 500000 );
			} while ( true );
		} catch ( \Throwable $error ) {
			return self::error( 'account_events_unavailable', 503 );
		}
	}

	/**
	 * Construct the closed response shape without any account identifier.
	 *
	 * @param array $events Safe projected events.
	 * @param int   $cursor Replay sequence.
	 * @param bool  $has_more Whether unprocessed retained rows remain.
	 * @param bool  $reset Whether the client must refresh its canonical snapshots.
	 * @return array Response fields.
	 */
	private static function result( array $events, int $cursor, bool $has_more, bool $reset ): array {
		return array(
			'events'   => $events,
			'cursor'   => $cursor,
			'has_more' => $has_more,
			'reset'    => $reset,
		);
	}

	/**
	 * Produce a bounded failure without any event or account state.
	 *
	 * @param string $code Closed machine code.
	 * @param int    $status HTTP status.
	 * @return \WP_Error Error only.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, $code, array( 'status' => $status ) );
	}
}
