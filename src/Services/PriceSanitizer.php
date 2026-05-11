<?php
/**
 * Canonical price validation for x402 payment amounts.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Accepts only fixed decimal strings so PaymentRequirements conversion never
 * sees PHP numeric notation such as `1e3`.
 */
final class PriceSanitizer {

	private const PATTERN               = '/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/';
	private const FIXED_DECIMAL_PATTERN = '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/';

	private function __construct() {}

	public static function is_valid( mixed $raw ): bool {
		$price = trim( (string) $raw );
		if ( 1 !== preg_match( self::PATTERN, $price ) ) {
			return false;
		}
		return (float) $price > 0;
	}

	public static function is_fixed_decimal( mixed $raw ): bool {
		$price = trim( (string) $raw );
		if ( 1 !== preg_match( self::FIXED_DECIMAL_PATTERN, $price ) ) {
			return false;
		}
		return (float) $price > 0;
	}

	public static function sanitize( mixed $raw, string $fallback ): string {
		$price = trim( (string) $raw );
		return self::is_valid( $price ) ? $price : $fallback;
	}
}
