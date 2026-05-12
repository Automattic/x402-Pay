<?php
/**
 * Registers the built-in "x402.org (Test network)" facilitator connector.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Connectors;

use X402Press\Facilitator\Facilitator;
use X402Press\Services\FacilitatorProfile;
use X402Press\Services\X402FacilitatorClient;

/**
 * Registers `x402press_test` — the out-of-the-box facilitator that routes
 * through the public x402.org service on Base Sepolia. Every install gets it
 * so the paywall is usable without any third-party plugin or signup; it's the
 * "try the paywall on testnet" default.
 *
 * Also provides the `Facilitator` client for that connector ID via the
 * `x402press_facilitator_for_connector` filter — core strips unknown
 * fields from the registration payload, so the client mapping lives here
 * rather than in the connector metadata.
 */
final class TestConnectorRegistrar {

	public const ID = 'x402press_test';

	/**
	 * Hooked to `wp_connectors_init`.
	 */
	public function __invoke( \WP_Connector_Registry $registry ): void {
		$registry->register( self::ID, self::payload() );
	}

	/**
	 * `x402press_facilitator_for_connector` filter callback. Returns an
	 * X402FacilitatorClient pointing at x402.org/base-sepolia when asked
	 * about our own connector ID; otherwise forwards the existing value so
	 * other plugins can take over for their IDs.
	 */
	public function provide_facilitator( ?Facilitator $existing, string $id ): ?Facilitator {
		if ( self::ID !== $id || null !== $existing ) {
			return $existing;
		}
		return new X402FacilitatorClient( FacilitatorProfile::for_test() );
	}

	/**
	 * Registration payload.
	 *
	 * Core only preserves a fixed whitelist of fields (name, description,
	 * type, authentication, plugin). x402-specific capabilities are delivered
	 * separately through the `x402press_facilitator_for_connector` filter —
	 * confirmed against WordPress 7.0-RC2.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		return array(
			'name'           => 'x402.org (Test network)',
			'description'    => 'Built-in test facilitator. Routes through x402.org on Base Sepolia — no real funds move.',
			'type'           => ConnectorRegistry::FACILITATOR_TYPE,
			'authentication' => array( 'method' => 'none' ),
			'plugin'         => array( 'file' => 'x402-paywall/x402press.php' ),
		);
	}
}
