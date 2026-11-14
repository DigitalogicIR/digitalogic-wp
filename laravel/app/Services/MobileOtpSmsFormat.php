<?php
/**
 * Server-owned native SMS delivery formats; verification authority is unchanged.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 service contract.

/** Select a separately approved provider template, never a client-supplied app hash. */
final class MobileOtpSmsFormat {

	const OPTION = 'digitalogic_mobile_android_sms';

	/**
	 * Validate only a closed format selector; routing material is server-owned.
	 *
	 * @param array $input Native request body.
	 * @return string Delivery format.
	 * @throws \InvalidArgumentException When client routing or an unknown format is supplied.
	 */
	public static function requested( array $input ): string {
		foreach ( array( 'app_hash', 'sms_hash', 'template', 'template_id', 'parameters' ) as $forbidden ) {
			if ( array_key_exists( $forbidden, $input ) ) {
				throw new \InvalidArgumentException( 'invalid_sms_format' );
			}
		}
		if ( ! array_key_exists( 'sms_format', $input ) ) {
			return 'default';
		}
		if ( 'android_hash' !== $input['sms_format'] ) {
			throw new \InvalidArgumentException( 'invalid_sms_format' );
		}
		return 'android_hash';
	}

	/**
	 * Validate the explicit operator-approved provider configuration without sending.
	 *
	 * @param mixed $value Stored server configuration.
	 * @return array|null Fixed template metadata, or unavailable.
	 */
	public static function configuration( $value ): ?array {
		if ( ! is_array( $value ) || true !== ( $value['enabled'] ?? false )
			|| ! is_int( $value['template_id'] ?? null ) || $value['template_id'] < 1
			|| ! is_string( $value['app_hash'] ?? null )
			|| ! preg_match( '/\A[A-Za-z0-9+\/]{11}\z/D', $value['app_hash'] ) ) {
			return null;
		}
		return array(
			'template_id' => $value['template_id'],
			'app_hash'    => $value['app_hash'],
		);
	}

	/** Require the explicit format schema and existing provider credentials. */
	public static function available(): bool {
		return '1' === get_option( 'digitalogic_mobile_otp_format_schema' )
			&& null !== self::configuration( get_option( self::OPTION, array() ) )
			&& \Digitalogic_Smsir_Integration::native_otp_configured();
	}
}
