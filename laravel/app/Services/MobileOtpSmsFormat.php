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

	const OPTION        = 'digitalogic_mobile_android_sms';
	const WEBOTP_OPTION = 'digitalogic_mobile_webotp_sms';
	const WEBOTP_ORIGIN = 'https://pwa.digitalogic.ir';

	/**
	 * Validate only a closed format selector; routing material is server-owned.
	 *
	 * @param array $input Native request body.
	 * @return string Delivery format.
	 * @throws \InvalidArgumentException When client routing or an unknown format is supplied.
	 */
	public static function requested( array $input ): string {
		foreach ( array( 'app_hash', 'sms_hash', 'template', 'template_id', 'templateId', 'parameters', 'origin', 'host', 'domain', 'sms_origin', 'webotp_origin' ) as $forbidden ) {
			if ( array_key_exists( $forbidden, $input ) ) {
				throw new \InvalidArgumentException( 'invalid_sms_format' );
			}
		}
		if ( ! array_key_exists( 'sms_format', $input ) ) {
			return 'default';
		}
		if ( ! in_array( $input['sms_format'], array( 'android_hash', 'web_otp' ), true ) ) {
			throw new \InvalidArgumentException( 'invalid_sms_format' );
		}
		return $input['sms_format'];
	}

	/**
	 * Validate the explicit operator-approved provider configuration without sending.
	 *
	 * @param mixed  $value Stored server configuration.
	 * @param string $format Closed delivery format; omitted preserves the Android contract.
	 * @return array|null Fixed template metadata, or unavailable.
	 */
	public static function configuration( $value, string $format = 'android_hash' ): ?array {
		if ( 'web_otp' === $format ) {
			if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'enabled', 'template_id', 'origin' ) )
				|| true !== ( $value['enabled'] ?? false ) || ! is_int( $value['template_id'] ?? null )
				|| $value['template_id'] < 1 || $value['template_id'] > 2147483647
				|| self::WEBOTP_ORIGIN !== ( $value['origin'] ?? null ) ) {
				return null;
			}
			return array(
				'template_id' => $value['template_id'],
				'origin'      => self::WEBOTP_ORIGIN,
			);
		}
		if ( 'android_hash' !== $format ) {
			return null;
		}
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

	/**
	 * Require explicit schema, approved configuration and existing provider credentials.
	 *
	 * @param string $format Closed delivery format; omitted preserves the Android contract.
	 * @return bool Whether this separately configured format can be sent.
	 */
	public static function available( string $format = 'android_hash' ): bool {
		if ( ! in_array( $format, array( 'android_hash', 'web_otp' ), true ) ) {
			return false;
		}
		$option = 'web_otp' === $format ? self::WEBOTP_OPTION : self::OPTION;
		$config = self::configuration( get_option( $option, array() ), $format );
		if ( '1' !== get_option( 'digitalogic_mobile_otp_format_schema' ) || null === $config
			|| ! \Digitalogic_Smsir_Integration::native_otp_configured() ) {
			return false;
		}
		if ( 'web_otp' === $format ) {
			$default = get_option( 'digit_smsir2', array() );
			$android = get_option( self::OPTION, array() );
			// A distinct template bypasses default-only Shatel rendering and cannot reuse the Android body.
			if ( (int) ( is_array( $default ) ? ( $default['template'] ?? 0 ) : 0 ) === $config['template_id']
				|| (int) ( is_array( $android ) ? ( $android['template_id'] ?? 0 ) : 0 ) === $config['template_id'] ) {
				return false;
			}
		}
		return true;
	}
}
