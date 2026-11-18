<?php
/**
 * Shared decorative storefront icons.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep customer-facing icon geometry consistent across PHP and JavaScript. */
final class Digitalogic_Storefront_Icons {
	/** Linear barcode used only to identify Product Code / SKU values. */
	private const BARCODE_PATH = 'M3 5v14M7 5v14M10 5v14M14 5v14M17 5v14M21 5v14';

	/**
	 * Return the canonical barcode path for client-rendered storefront controls.
	 */
	public static function barcode_path(): string {
		return self::BARCODE_PATH;
	}

	/**
	 * Render the canonical decorative barcode icon.
	 *
	 * @param string $additional_class Optional trusted presentation class.
	 */
	public static function barcode_svg( string $additional_class = '' ): string {
		$class_name = trim( 'digitalogic-barcode-icon ' . $additional_class );

		return '<svg class="' . esc_attr( $class_name ) . '" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="butt" stroke-linejoin="miter"><path d="' . self::BARCODE_PATH . '"/></svg>';
	}
}
