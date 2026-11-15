<?php
/**
 * Opt-in, expiring Android compatibility contributions without device identifiers.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 service contract.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Session authority, locks and bounded telemetry retention require current SQL state.

/** One pseudonymous contribution per authenticated session and UTC day. */
final class MobileDeviceReport {

	const RETENTION_DAYS = 30;
	const CLEANUP_HOOK   = 'digitalogic_mobile_device_reports_cleanup';
	const FIELDS         = array( 'version_code', 'sdk_int', 'manufacturer', 'model', 'abi', 'ram_class', 'form_factor' );

	/** Resolve only the adapter-owned report table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_device_reports';
	}

	/**
	 * Explicit installation does not enable collection or collect any report.
	 *
	 * @throws \RuntimeException When transactional storage is not available.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			contribution_key char(64) NOT NULL,
			day date NOT NULL,
			version_code int unsigned NOT NULL,
			sdk_int smallint unsigned NOT NULL,
			manufacturer varchar(64) NOT NULL,
			model varchar(64) NOT NULL,
			abi varchar(20) NOT NULL,
			ram_class varchar(8) NOT NULL,
			form_factor varchar(6) NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (contribution_key),
			KEY expires_at (expires_at),
			KEY report_day (day)
		) ENGINE=InnoDB {$charset};"
		);
		foreach ( array( $table, MobileIdentityStore::table( 'sessions' ), $wpdb->users, $wpdb->usermeta, $wpdb->options ) as $required ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $required ), ARRAY_A );
			if ( ! $row || 0 !== strcasecmp( (string) ( $row['Engine'] ?? '' ), 'InnoDB' ) ) {
				throw new \RuntimeException( 'Device report storage is not transactional.' );
			}
		}
		foreach ( array_merge( array( 'contribution_key', 'day', 'expires_at' ), self::FIELDS ) as $column ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) ) ) {
				throw new \RuntimeException( 'Device report schema is incomplete.' );
			}
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			if ( ! wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK ) ) {
				throw new \RuntimeException( 'Device report retention cleanup is not scheduled.' );
			}
		}
		update_option( 'digitalogic_mobile_device_report_schema', '1', false );
	}

	/** Collection is unavailable until both schema and explicit operator enablement exist. */
	public static function enabled(): bool {
		return '1' === get_option( 'digitalogic_mobile_device_report_schema' )
			&& in_array( get_option( 'digitalogic_mobile_device_reports', false ), array( true, 1, '1' ), true )
			&& in_array( get_option( 'digitalogic_mobile_native_auth', false ), array( true, 1, '1' ), true );
	}

	/**
	 * Accept only consent plus the seven declared compatibility fields.
	 *
	 * @param array $input Explicit client report.
	 * @return array|\WP_Error Normalized metadata or a definitive pre-write error.
	 */
	public static function normalize( array $input ) {
		if ( array_diff( array_keys( $input ), array_merge( array( 'consent' ), self::FIELDS ) )
			|| array_diff( array_merge( array( 'consent' ), self::FIELDS ), array_keys( $input ) )
			|| true !== ( $input['consent'] ?? null ) ) {
			return self::error( 'invalid_device_report', 400 );
		}
		if ( ! is_int( $input['version_code'] ) || $input['version_code'] < 1 || $input['version_code'] > 2147483647
			|| ! is_int( $input['sdk_int'] ) || $input['sdk_int'] < 21 || $input['sdk_int'] > 100 ) {
			return self::error( 'invalid_device_report', 400 );
		}
		foreach ( array(
			'manufacturer' => 64,
			'model'        => 64,
		) as $field => $limit ) {
			if ( ! is_string( $input[ $field ] ) || ! preg_match( '/\A[\p{L}\p{N} ._()+-]+\z/uD', $input[ $field ] ) ) {
				return self::error( 'invalid_device_report', 400 );
			}
			$input[ $field ] = preg_replace( '/ +/', ' ', trim( $input[ $field ] ) );
			if ( '' === $input[ $field ] || mb_strlen( $input[ $field ] ) > $limit ) {
				return self::error( 'invalid_device_report', 400 );
			}
		}
		$input['manufacturer'] = mb_strtolower( $input['manufacturer'] );
		foreach ( array(
			'abi'         => array( 'armeabi-v7a', 'arm64-v8a', 'x86', 'x86_64', 'unknown' ),
			'ram_class'   => array( 'low', 'standard' ),
			'form_factor' => array( 'phone', 'tablet' ),
		) as $field => $allowed ) {
			if ( ! is_string( $input[ $field ] ) || ! in_array( $input[ $field ], $allowed, true ) ) {
				return self::error( 'invalid_device_report', 400 );
			}
		}
		unset( $input['consent'] );
		return array_replace( array_fill_keys( self::FIELDS, null ), $input );
	}

	/**
	 * Daily keys deduplicate without retaining or exposing native session identifiers.
	 *
	 * @param string $session_id Existing native session identifier.
	 * @param string $day UTC calendar date.
	 */
	public static function contributionKey( string $session_id, string $day ): string {
		return MobileIdentityStore::hash( 'device-report:' . $session_id . ':' . $day );
	}

	/**
	 * Replace this session's daily contribution after current native authority is locked.
	 *
	 * @param array $session Previously authenticated native session, never persisted here.
	 * @param array $input Explicit consent and bounded compatibility metadata.
	 * @return array|\WP_Error Acceptance receipt or failure without provider side effects.
	 */
	public static function submit( array $session, array $input ) {
		global $wpdb;
		if ( ! self::enabled() ) {
			return self::error( 'device_reporting_unavailable', 503 );
		}
		$data = self::normalize( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_string( $session['id'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $session['id'] ) || (int) ( $session['user_id'] ?? 0 ) < 1 ) {
			return self::error( 'unauthorized', 401 );
		}
		$lock = 'dgl-device-report-' . substr( MobileIdentityStore::hash( $session['id'] ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) ) {
			return self::error( 'device_report_busy', 409 );
		}
		$transaction = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return self::error( 'device_report_not_saved', 503 );
			}
			$transaction = true;
			// Lock both canonical rows before rechecking expiry, password epoch and retired identity.
			$user   = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID=%d FOR UPDATE', $wpdb->users, (int) $session['user_id'] ) );
			$active = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%s AND user_id=%d AND expires_at>%d FOR UPDATE', MobileIdentityStore::table( 'sessions' ), $session['id'], (int) $session['user_id'], time() ) );
			if ( '' !== $wpdb->last_error ) {
				return self::error( 'device_report_not_saved', 503 );
			}
			if ( $active !== $session['id'] || (int) $user !== (int) $session['user_id'] || ! MobileIdentityStore::sessionIsCurrent( $session['id'], (int) $session['user_id'] ) ) {
				return self::error( 'unauthorized', 401 );
			}
			if ( ! MobileIdentityStore::admit( 'device-report:' . $session['id'], 6, HOUR_IN_SECONDS ) ) {
				return self::error( 'device_report_rate_limited', 429 );
			}
			$day = gmdate( 'Y-m-d' );
			$row = array_merge(
				array(
					'contribution_key' => self::contributionKey( $session['id'], $day ),
					'day'              => $day,
				),
				$data,
				array( 'expires_at' => strtotime( $day . ' 00:00:00 UTC' ) + self::RETENTION_DAYS * DAY_IN_SECONDS )
			);
			if ( false === $wpdb->replace( self::table(), $row ) || false === $wpdb->query( 'COMMIT' ) ) {
				return self::error( 'device_report_not_saved', 503 );
			}
			$transaction = false;
			return array(
				'accepted'       => true,
				'retention_days' => self::RETENTION_DAYS,
			);
		} finally {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Expired reports are excluded immediately and physically removed by the scheduled cleanup. */
	public static function cleanup(): void {
		global $wpdb;
		if ( '1' !== get_option( 'digitalogic_mobile_device_report_schema' ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<=%d', self::table(), time() ) );
	}

	/**
	 * Administrators see cohorts only, never a contribution key or individual report.
	 *
	 * @return array|\WP_Error Bounded aggregate session-day counts.
	 */
	public static function report() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::error( 'forbidden', 403 );
		}
		if ( '1' !== get_option( 'digitalogic_mobile_device_report_schema' ) ) {
			return self::error( 'device_reporting_unavailable', 503 );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT version_code,sdk_int,manufacturer,model,abi,ram_class,form_factor,COUNT(*) AS session_days FROM %i WHERE expires_at>%d GROUP BY version_code,sdk_int,manufacturer,model,abi,ram_class,form_factor HAVING COUNT(*)>=5 ORDER BY session_days DESC,version_code DESC,sdk_int DESC,manufacturer,model,abi,ram_class,form_factor LIMIT 100',
				self::table(),
				time()
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return self::error( 'device_report_unavailable', 503 );
		}
		foreach ( $rows as &$row ) {
			foreach ( array( 'version_code', 'sdk_int', 'session_days' ) as $field ) {
				$row[ $field ] = (int) $row[ $field ];
			}
		}
		unset( $row );
		return array(
			'retention_days'       => self::RETENTION_DAYS,
			'minimum_session_days' => 5,
			'items'                => $rows,
		);
	}

	/**
	 * Keep private diagnostic details out of public error messages.
	 *
	 * @param string $code Closed machine-readable error code.
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'Device reporting request could not be completed.', array( 'status' => $status ) );
	}
}
