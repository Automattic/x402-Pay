<?php
/**
 * x402 facilitator + network + asset configuration.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Facilitator\RequestSigner;

/**
 * Bundles every constant needed to produce PaymentRequirements and talk to a
 * facilitator: network, ERC-20 token + decimals, facilitator endpoint, the
 * EIP-712 domain, and an optional {@see RequestSigner} for facilitators that
 * authenticate per request.
 *
 * Auth is opaque to this class — connectors supply whatever signer they need
 * (or `null` for unauthenticated facilitators like x402.org). Connector
 * authors construct an instance directly; `for_test()` is the built-in
 * x402.org/Base Sepolia profile for the dev test connector.
 */
final class FacilitatorProfile {

	public function __construct(
		public readonly string $network,
		public readonly string $asset,
		public readonly int $asset_decimals,
		public readonly string $facilitator_url,
		public readonly string $eip712_name,
		public readonly string $eip712_version,
		public readonly string $environment_label,
		public readonly ?RequestSigner $signer = null,
	) {}

	/**
	 * Profile for Base Sepolia USDC via the public x402.org facilitator.
	 */
	public static function for_test(): self {
		return new self(
			network: 'base-sepolia',
			// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- EVM contract address literal.
			asset: '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
			asset_decimals: 6,
			facilitator_url: 'https://x402.org/facilitator/',
			eip712_name: 'USDC',
			eip712_version: '2',
			environment_label: 'Testnet',
		);
	}
}
