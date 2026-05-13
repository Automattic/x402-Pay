<?php
/**
 * Resolves a per-request payment rule via filter.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Services;

/**
 * Thin wrapper around the `x402_pay_rule_for_request` filter.
 *
 * Callers pass a request context array; we apply the filter, validate the
 * returned shape, and return either a normalised rule or null.
 *
 * Rule shape: [
 *     'price'       => string  // decimal USDC, positive
 *     'ttl'         => int     // grant lifetime in seconds, positive
 *     'description' => string  // optional, defaults to ''
 * ]
 */
final class RuleResolver {

	public const HOOK        = 'x402_pay_rule_for_request';
	public const DEFAULT_TTL = 86400;

	/**
	 * Resolve the rule for a request.
	 *
	 * @param array $ctx Request context: path, method, post_id, singular (bool),
	 *                   paywall_probe (bool, optional), plus any filter extensions.
	 */
	public function resolve( array $ctx ): ?array {
		$raw = apply_filters( self::HOOK, null, $ctx );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$price = isset( $raw['price'] ) ? (string) $raw['price'] : '';
		if ( ! PriceSanitizer::is_valid( $price ) ) {
			return null;
		}

		$ttl = isset( $raw['ttl'] ) ? (int) $raw['ttl'] : self::DEFAULT_TTL;
		if ( $ttl <= 0 ) {
			$ttl = self::DEFAULT_TTL;
		}

		return array(
			'price'       => $price,
			'ttl'         => $ttl,
			'description' => isset( $raw['description'] ) ? (string) $raw['description'] : '',
		);
	}
}
