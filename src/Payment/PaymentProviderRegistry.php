<?php
/**
 * Filter-based registry for front-end payment providers shown on 402 pages.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Payment;

/**
 * Resolves the list of payment-button providers eligible for a given 402
 * response. Providers register via the `simple_x402_payment_providers` filter
 * and return descriptors of shape:
 *
 *   array{
 *     id:          string,                  // Stable slug, used as the slot data attribute.
 *     label:       string,                  // Human-readable name (currently informational).
 *     script_url:  string,                  // Enqueued <script src="…">; runs in the publisher origin.
 *     is_eligible: bool,                    // False suppresses the slot and its script.
 *     config?:     array<string,mixed>,     // Provider-specific data passed verbatim to the JS callback.
 *   }
 *
 * Providers receive the same filter context (requirements, resource_url,
 * request) so eligibility can depend on the network, post type, etc.
 */
final class PaymentProviderRegistry {

	/**
	 * Filter hook name. Receives `(array $providers, array $context)` and must
	 * return the (possibly extended) provider list.
	 */
	public const FILTER = 'simple_x402_payment_providers';

	/**
	 * @param array<string,mixed> $context Includes `requirements`, `resource_url`, `request`.
	 *
	 * @return array<int,array<string,mixed>> Eligible providers in registration order.
	 */
	public function eligible( array $context ): array {
		$raw = apply_filters( self::FILTER, array(), $context );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry['is_eligible'] ) ) {
				continue;
			}
			$id         = (string) ( $entry['id'] ?? '' );
			$script_url = (string) ( $entry['script_url'] ?? '' );
			if ( '' === $id || '' === $script_url ) {
				continue;
			}
			$out[] = array(
				'id'         => $id,
				'label'      => (string) ( $entry['label'] ?? $id ),
				'script_url' => $script_url,
				'config'     => is_array( $entry['config'] ?? null ) ? $entry['config'] : array(),
			);
		}
		return $out;
	}
}
