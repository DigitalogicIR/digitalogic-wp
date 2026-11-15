<?php
/**
 * Explicit issuance of a publisher-owned immutable Android release.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 service and callback contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Adapter-owned issuance metadata is serialized under a database lock and transaction.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- OS flock handles require native close; WordPress file transports cannot release them.

/** No APK upload, arbitrary URL, remote command or installation authority. */
final class MobileRelease {
	const OPTION      = 'digitalogic_mobile_issued_release';
	const CERTIFICATE = 'b61bb5303cbffc3be265ce1c1a7805e9a64a680fff0d6e877cd731f5dd98eb5c';

	/**
	 * Validate the fixed public manifest contract.
	 *
	 * @param array $data Publisher manifest.
	 * @param int   $version Exact selected build.
	 * @param bool  $require_certificate Require publisher's permanent-certificate metadata for newly issued releases.
	 * @return array Public, validated manifest fields.
	 * @throws \UnexpectedValueException On malformed or cross-origin release metadata.
	 */
	public static function validate( array $data, int $version, bool $require_certificate = true ): array {
		$url = self::url( $version, 'apk' );
		if ( $version < 1 || $version > 2147483647 || ( $data['package'] ?? null ) !== 'ir.digitalogic.app'
			|| ( $data['version_code'] ?? null ) !== $version || ( $data['apk_url'] ?? null ) !== $url
			|| ! is_string( $data['version_name'] ?? null ) || ! preg_match( '/\A[0-9A-Za-z.+_-]{1,64}\z/D', $data['version_name'] )
			|| ! is_int( $data['min_sdk'] ?? null ) || $data['min_sdk'] < 24 || $data['min_sdk'] > 100
			|| ! is_int( $data['bytes'] ?? null ) || $data['bytes'] < 1024 || $data['bytes'] > 209715200
			|| ! is_string( $data['sha256'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $data['sha256'] )
			|| ( $require_certificate && ( $data['signing_cert_sha256'] ?? null ) !== self::CERTIFICATE ) ) {
			throw new \UnexpectedValueException( 'invalid_release_manifest' );
		}
		return array_intersect_key( $data, array_flip( array( 'package', 'version_code', 'version_name', 'min_sdk', 'apk_url', 'sha256', 'bytes', 'signing_cert_sha256' ) ) );
	}

	/**
	 * Fixed package URL, independent of user-provided paths.
	 *
	 * @param int    $version Release build.
	 * @param string $extension Internal literal extension.
	 * @return string Same-origin URL.
	 */
	public static function url( int $version, string $extension = 'json' ): string {
		return home_url( '/apps/digitalogic/digitalogic-' . $version . '-arm64.' . $extension, 'https' );
	}

	/**
	 * Require a verified APK with exactly one expected signing certificate.
	 *
	 * @param string $output Fixed apksigner verification output.
	 * @param int    $exit_code Successful verification exit code.
	 * @return bool Expected permanent signer.
	 */
	public static function signatureMatches( string $output, int $exit_code ): bool {
		preg_match_all( '/^Signer #[0-9]+ certificate SHA-256 digest:\s*([0-9a-fA-F]{64})\s*$/m', $output, $matches );
		return 0 === $exit_code && count( $matches[1] ) === 1 && hash_equals( self::CERTIFICATE, strtolower( $matches[1][0] ) );
	}

	/**
	 * Match the signed APK's actual package/build requirements to its manifest.
	 *
	 * @param string $output Fixed aapt badging output.
	 * @param int    $exit_code Verifier exit status.
	 * @param array  $data Validated publisher metadata.
	 * @return bool Exact package, build, version name and minimum SDK.
	 */
	public static function packageMatches( string $output, int $exit_code, array $data ): bool {
		return 0 === $exit_code
			&& 1 === preg_match( "/^package: name='([^']+)' versionCode='([0-9]+)' versionName='([^']*)'/m", $output, $package )
			&& 1 === preg_match( "/^sdkVersion:'([0-9]+)'$/m", $output, $sdk )
			&& $package[1] === $data['package'] && (int) $package[2] === $data['version_code']
			&& $package[3] === $data['version_name'] && (int) $sdk[1] === $data['min_sdk'];
	}

	/**
	 * Refuse stale activation, including a newer already-published latest manifest.
	 *
	 * @param int $candidate Selected build.
	 * @param int $issued Currently issued build.
	 * @param int $latest Current publisher latest build.
	 * @return bool Monotonic release selection.
	 */
	public static function mayIssue( int $candidate, int $issued, int $latest ): bool {
		return $candidate >= max( $issued, $latest );
	}

	/** Return only the last explicitly issued public release, without filesystem work. */
	public static function advertised(): ?array {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || ! is_int( $state['version_code'] ?? null ) ) {
			return null;
		}
		try {
			$data = self::validate( $state, $state['version_code'] );
		} catch ( \UnexpectedValueException $error ) {
			return null;
		}
		$data['manifest_url'] = self::url( $data['version_code'] );
		$data['issued_at']    = $state['issued_at'] ?? null;
		return $data;
	}

	/**
	 * Read only the fixed local published release and verify all APK bytes/signature.
	 *
	 * @param int $version Selected build.
	 * @return array Verified public metadata plus manifest digest.
	 * @throws \RuntimeException When immutable release or verifier is unavailable.
	 */
	private static function load( int $version ): array {
		$root = realpath( ABSPATH . 'apps/digitalogic' );
		if ( false === $root ) {
			throw new \RuntimeException( 'release_not_published' );
		}
		$manifest = self::local( $root, 'digitalogic-' . $version . '-arm64.json' );
		if ( filesize( $manifest ) > 32768 ) {
			throw new \RuntimeException( 'invalid_release_manifest' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixed local publisher artifact; no network URL or client path.
		$bytes = file_get_contents( $manifest );
		$data  = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
		$data  = self::validate( $data, $version );
		$apk   = self::local( $root, 'digitalogic-' . $version . '-arm64.apk' );
		if ( filesize( $apk ) !== $data['bytes'] || ! hash_equals( $data['sha256'], hash_file( 'sha256', $apk ) ) ) {
			throw new \RuntimeException( 'release_apk_mismatch' );
		}
		self::verifySignature( $apk );
		$tool = realpath( '/opt/android-sdk/build-tools/34.0.0/aapt' );
		if ( ! $tool || ! is_executable( $tool ) ) {
			throw new \RuntimeException( 'release_package_verifier_unavailable' );
		}
		list( $output, $exit_code ) = self::runVerifier( array( $tool, 'dump', 'badging', $apk ) );
		if ( ! self::packageMatches( $output, $exit_code, $data ) ) {
			throw new \RuntimeException( 'release_package_mismatch' );
		}
		$data['manifest_sha256'] = hash( 'sha256', $bytes );
		return $data;
	}

	/**
	 * Bound a local artifact to the publisher directory, rejecting symlink traversal.
	 *
	 * @param string $root Existing canonical directory.
	 * @param string $name Internally derived filename.
	 * @return string Resolved file.
	 * @throws \RuntimeException On absent or unexpected files.
	 */
	private static function local( string $root, string $name ): string {
		$path = realpath( $root . '/' . $name );
		if ( false === $path || dirname( $path ) !== $root || ! is_file( $path ) || is_link( $root . '/' . $name ) ) {
			throw new \RuntimeException( 'release_not_published' );
		}
		return $path;
	}

	/**
	 * Run one read-only verifier command with fixed arguments and a strict deadline.
	 *
	 * @param string $apk Already validated local APK path.
	 * @throws \RuntimeException If verification cannot prove the permanent signature.
	 */
	private static function verifySignature( string $apk ): void {
		$tool = realpath( defined( 'DIGITALOGIC_APP_APKSIGNER' ) ? DIGITALOGIC_APP_APKSIGNER : '/opt/android-sdk/build-tools/34.0.0/apksigner' );
		if ( ! $tool || ! is_file( $tool ) || ! is_executable( $tool ) || ! function_exists( 'proc_open' ) ) {
			throw new \RuntimeException( 'release_signature_verifier_unavailable' );
		}
		list( $output, $exit_code ) = self::runVerifier( array( $tool, 'verify', '--print-certs', $apk ) );
		if ( ! self::signatureMatches( $output, $exit_code ) ) {
			throw new \RuntimeException( 'release_signature_verification_failed' );
		}
	}

	/**
	 * Run a fixed server tool with bounded output and a hard process deadline.
	 *
	 * @param array $arguments Internal argv only; no caller-selected commands.
	 * @return array Captured output and exit code.
	 * @throws \RuntimeException When verifier output exceeds its bound.
	 */
	private static function runVerifier( array $arguments ): array {
		// Symfony enforces its timeout with bounded TERM then KILL; array argv bypasses a shell.
		$process = new \Symfony\Component\Process\Process( $arguments );
		$process->setTimeout( 15 );
		$output = '';
		try {
			$exit = $process->run(
				static function ( $type, $chunk ) use ( &$output ): void {
					$output .= $chunk;
					if ( strlen( $output ) > 32768 ) {
						throw new \RuntimeException( 'release_verifier_output_limit' );
					}
				}
			);
		} finally {
			if ( $process->isRunning() ) {
				$process->stop( 0 );
			}
		}
		return array( $output, $exit );
	}

	/**
	 * Read current metadata directly while holding the global issuance lock.
	 *
	 * @param string $name Adapter-owned option key.
	 * @return mixed Current authoritative value.
	 * @throws \RuntimeException When the authoritative metadata cannot be read.
	 */
	private static function read( string $name ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'release_storage_unavailable' );
		}
		return null === $value ? null : maybe_unserialize( $value );
	}

	/** Heal cache after durable issue or replay without changing the active release. */
	private static function invalidate(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'digitalogic_mobile_update_manifest', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Issue exactly one verified immutable release; retries never downgrade a later issue.
	 *
	 * @param int    $version Selected released build.
	 * @param string $operation_id Stable administrator operation UUID.
	 * @return array|\WP_Error Durable receipt or bounded error.
	 * @throws \UnexpectedValueException Internally caught and converted to a bounded conflict response.
	 * @throws \RuntimeException Internally caught and converted to a bounded storage or verification error.
	 */
	public static function issue( int $version, string $operation_id ) {
		global $wpdb;
		$owner_cli = defined( 'WP_CLI' ) && WP_CLI && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
		if ( ! $owner_cli && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', 'Release issuance requires WordPress administrator authority.', array( 'status' => 403 ) );
		}
		if ( $version < 1 || $version > 2147483647 || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $operation_id ) ) {
			return new \WP_Error( 'invalid_release_request', 'Select a published build and a stable request ID.', array( 'status' => 400 ) );
		}
		$key = '_digitalogic_release_issue_' . hash( 'sha256', $operation_id );
		// The publisher takes this same stable inode lock before replacing latest.json.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only access to a fixed local coordination lock, not a client path.
		$publication_lock = fopen( defined( 'DIGITALOGIC_APP_RELEASE_LOCK' ) ? DIGITALOGIC_APP_RELEASE_LOCK : '/run/lock/digitalogic-app-release.lock', 'r' );
		if ( ! is_resource( $publication_lock ) ) {
			return new \WP_Error( 'release_publication_lock_unavailable', 'Release publisher coordination is not configured.', array( 'status' => 503 ) );
		}
		if ( ! flock( $publication_lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $publication_lock );
			return new \WP_Error( 'release_busy', 'A release publication is in progress.', array( 'status' => 409 ) );
		}
		$lock = 'digitalogic-mobile-release-issue';
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) {
			flock( $publication_lock, LOCK_UN );
			fclose( $publication_lock );
			return new \WP_Error( 'release_busy', 'Another release operation is in progress.', array( 'status' => 409 ) );
		}
		$transaction = false;
		try {
			$receipt = self::read( $key );
			if ( is_array( $receipt ) ) {
				if ( ( $receipt['release']['version_code'] ?? null ) !== $version ) {
					throw new \UnexpectedValueException( 'release_operation_conflict' );
				}
				self::invalidate();
				return $receipt;
			}
			$data        = self::load( $version );
			$current     = self::read( self::OPTION );
			$root        = realpath( ABSPATH . 'apps/digitalogic' );
			$latest_path = self::local( $root, 'latest.json' );
			if ( filesize( $latest_path ) > 32768 ) {
				throw new \UnexpectedValueException( 'invalid_release_manifest' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Canonical fixed local latest manifest, never a client URL.
			$latest = json_decode( file_get_contents( $latest_path ), true, 32, JSON_THROW_ON_ERROR );
			$latest = self::validate( $latest, $latest['version_code'] ?? 0, false );
			if ( ! self::mayIssue( $version, (int) ( $current['version_code'] ?? 0 ), $latest['version_code'] ) ) {
				throw new \UnexpectedValueException( 'release_downgrade_rejected' );
			}
			if ( is_array( $current ) && ( $current['version_code'] ?? null ) === $version && ( $current['manifest_sha256'] ?? '' ) !== $data['manifest_sha256'] ) {
				throw new \UnexpectedValueException( 'release_immutable_conflict' );
			}
			$engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->options ) ), ARRAY_A );
			if ( ! $engine || strtoupper( $engine['Engine'] ?? '' ) !== 'INNODB' || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'release_storage_unavailable' );
			}
			$transaction          = true;
			$data['issued_at']    = ( $current['version_code'] ?? null ) === $version ? ( $current['issued_at'] ?? gmdate( 'c' ) ) : gmdate( 'c' );
			$data['manifest_url'] = self::url( $version );
			$receipt              = array(
				'operation_id' => $operation_id,
				'issued'       => true,
				'release'      => $data,
			);
			foreach ( array(
				self::OPTION                         => $data,
				'digitalogic_mobile_update_manifest' => $data['manifest_url'],
				$key                                 => $receipt,
			) as $name => $value ) {
				if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'", $name, maybe_serialize( $value ) ) ) ) {
					throw new \RuntimeException( 'release_storage_unavailable' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'release_result_uncertain' );
			}
			$transaction = false;
			self::invalidate();
			wp_cache_delete( $key, 'options' );
			return $receipt;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$known = array( 'release_not_published', 'release_apk_mismatch', 'release_signature_verifier_unavailable', 'release_signature_verification_failed', 'release_package_verifier_unavailable', 'release_package_mismatch', 'release_storage_unavailable', 'release_result_uncertain' );
			$code  = $error instanceof \UnexpectedValueException || in_array( $error->getMessage(), $known, true ) ? $error->getMessage() : 'release_not_issued';
			if ( 'release_result_uncertain' === $code ) {
				self::invalidate();
			}
			return new \WP_Error( $code, 'Release verification or storage was not confirmed; retry only the same operation ID.', array( 'status' => $error instanceof \UnexpectedValueException ? 409 : 503 ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			flock( $publication_lock, LOCK_UN );
			fclose( $publication_lock );
		}
	}

	/** Authenticated existing WordPress admin action; no native bearer handoff. */
	public static function adminIssue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'digitalogic_mobile_release_issue' );
		$version = sanitize_text_field( wp_unslash( $_POST['version_code'] ?? '' ) );
		if ( ! ctype_digit( $version ) ) {
			wp_die( 'Invalid release build', '', array( 'response' => 400 ) );
		}
		$result = self::issue( (int) $version, sanitize_text_field( wp_unslash( $_POST['operation_id'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) $result->get_error_data()['status'] ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=digitalogic-mobile&release_issued=' . $result['release']['version_code'] ) );
		exit;
	}

	/**
	 * Owner CLI issues only a selected immutable build, never uploads or arbitrary commands.
	 *
	 * @param array $args Command tokens.
	 * @param array $assoc Named options.
	 */
	public static function cli( array $args, array $assoc ): void {
		if ( ( $args[0] ?? '' ) !== 'issue' || ! ctype_digit( (string) ( $args[1] ?? '' ) ) ) {
			\WP_CLI::error( 'Usage: wp digitalogic-mobile-release issue <version> --request-id=<uuid>' );
		}
		$result = self::issue( (int) $args[1], (string) ( $assoc['request-id'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_code() );
		}
		\WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) );
	}
}
