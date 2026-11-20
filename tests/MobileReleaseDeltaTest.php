<?php
/**
 * Optional delta metadata cannot weaken the canonical full APK contract.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileRelease as Release;
use PHPUnit\Framework\TestCase;

/** Pure manifest validation; no release publication, network or artifact writes. */
final class MobileReleaseDeltaTest extends TestCase {

	/** Return a complete full-download manifest with one optional patch. */
	private function manifest(): array {
		return array(
			'package'             => 'ir.digitalogic.app',
			'version_code'        => 27,
			'version_name'        => '0.1.0',
			'min_sdk'             => 24,
			'apk_url'             => Release::url( 27, 'apk' ),
			'sha256'              => str_repeat( 'a', 64 ),
			'bytes'               => 100000,
			'signing_cert_sha256' => Release::CERTIFICATE,
			'source_commit'       => str_repeat( 'c', 40 ),
			'deltas'              => array( $this->delta() ),
		);
	}

	/** Return one exact same-origin, base-bound immutable patch. */
	private function delta(): array {
		return array(
			'algorithm'         => 'digitalogic-copy-v1+gzip',
			'from_version_code' => 26,
			'from_sha256'       => str_repeat( 'b', 64 ),
			'url'               => home_url( '/apps/digitalogic/digitalogic-26-27-bbbbbbbbbbbb-arm64.delta.gz', 'https' ),
			'sha256'            => str_repeat( 'd', 64 ),
			'bytes'             => 1000,
		);
	}

	/** Known metadata round-trips while unknown fields never become public. */
	public function test_delta_round_trip_keeps_full_fallback_and_only_public_fields(): void {
		$data = $this->manifest();
		self::assertSame( $data, Release::validate( $data, 27 ) );
		$data['deltas'][0]['internal_path'] = '/private/not-public';
		$data['untrusted_field']            = 'discard';
		self::assertSame( $this->manifest(), Release::validate( $data, 27 ) );
		unset( $data['deltas'], $data['untrusted_field'] );
		self::assertSame( $data, Release::validate( $data, 27 ) );
		$data['deltas'] = array();
		self::assertSame( $data, Release::validate( $data, 27 ) );
	}

	/** Every path, identity and type must match the target/base contract. */
	public function test_invalid_delta_fields_are_rejected_before_artifact_work(): void {
		$changes = array(
			array( 'algorithm' => 'bsdiff' ),
			array( 'from_version_code' => 0 ),
			array( 'from_version_code' => 27 ),
			array( 'from_version_code' => 28 ),
			array( 'from_version_code' => '26' ),
			array( 'from_sha256' => str_repeat( 'B', 64 ) ),
			array( 'from_sha256' => str_repeat( 'a', 64 ) ),
			array( 'sha256' => str_repeat( 'g', 64 ) ),
			array( 'bytes' => 0 ),
			array( 'bytes' => '1000' ),
			array( 'bytes' => 100000 ),
			array( 'url' => 'https://attacker.test/delta.gz' ),
			array( 'url' => $this->delta()['url'] . '?override=1' ),
			array( 'url' => str_replace( '26-27-', '25-27-', $this->delta()['url'] ) ),
			array( 'url' => str_replace( '26-27-', '26-28-', $this->delta()['url'] ) ),
			array( 'url' => str_replace( '/apps/', '/private/../apps/', $this->delta()['url'] ) ),
			array( 'url' => str_replace( 'arm64', 'x86', $this->delta()['url'] ) ),
			array( 'url' => str_replace( 'https:', 'http:', $this->delta()['url'] ) ),
		);
		foreach ( $changes as $change ) {
			$data           = $this->manifest();
			$data['deltas'] = array( array_replace( $this->delta(), $change ) );
			$this->assertRejected( $data, 'invalid_release_deltas' );
		}
		foreach ( array_keys( $this->delta() ) as $required ) {
			$data = $this->manifest();
			unset( $data['deltas'][0][ $required ] );
			$this->assertRejected( $data, 'invalid_release_deltas' );
		}
	}

	/** Bounded list and duplicate prevention limit local verification work. */
	public function test_delta_list_is_bounded_sequential_and_unique(): void {
		foreach ( array( null, false, 'not-a-list', array( 'named' => $this->delta() ), array( $this->delta(), $this->delta() ), array_fill( 0, 9, $this->delta() ) ) as $invalid ) {
			$data           = $this->manifest();
			$data['deltas'] = $invalid;
			$this->assertRejected( $data, 'invalid_release_deltas' );
		}
		$data           = $this->manifest();
		$data['deltas'] = array();
		for ( $base = 19; $base < 27; ++$base ) {
			$delta                      = $this->delta();
			$delta['from_version_code'] = $base;
			$delta['url']               = home_url( '/apps/digitalogic/digitalogic-' . $base . '-27-bbbbbbbbbbbb-arm64.delta.gz', 'https' );
			$data['deltas'][]           = $delta;
		}
		self::assertSame( $data, Release::validate( $data, 27 ) );
	}

	/** Optional patch metadata never supplies a substitute for a valid signed APK. */
	public function test_delta_cannot_replace_required_full_apk_or_signer(): void {
		foreach ( array( 'apk_url', 'bytes', 'sha256', 'signing_cert_sha256' ) as $required ) {
			$data = $this->manifest();
			unset( $data[ $required ] );
			$this->assertRejected( $data, 'invalid_release_manifest' );
		}
	}

	/** Publisher, server and client share the same finite full-download ceiling. */
	public function test_full_apk_limit_is_exactly_256_mib(): void {
		$data          = $this->manifest();
		$data['bytes'] = 268435456;
		self::assertSame( $data, Release::validate( $data, 27 ) );
		$data['bytes'] = 268435457;
		$this->assertRejected( $data, 'invalid_release_manifest' );
	}

	/**
	 * Assert exact validation failure without triggering publication.
	 *
	 * @param array  $data Candidate metadata.
	 * @param string $code Expected bounded failure.
	 */
	private function assertRejected( array $data, string $code ): void {
		try {
			Release::validate( $data, 27 );
			self::fail( 'Malformed delta manifest was accepted.' );
		} catch ( UnexpectedValueException $error ) {
			self::assertSame( $code, $error->getMessage() );
		}
	}
}
