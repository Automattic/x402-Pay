<?php
/**
 * x402 facilitator + network + asset configuration.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Bundles every constant needed to produce PaymentRequirements and talk to a
 * facilitator: network, ERC-20 token + decimals, facilitator endpoint, optional
 * bearer auth, and the EIP-712 domain fields.
 *
 * Each Facilitator client exposes an instance via describe(); connector authors
 * construct it directly for their own facilitator. `for_test()` is the built-in
 * x402.org/Base Sepolia profile for the dev test connector.
 */
final class FacilitatorProfile {

	public const AUTH_NONE          = 'none';
	public const AUTH_STATIC_BEARER = 'static_bearer';
	public const AUTH_CDP_JWT       = 'cdp_jwt';

	public function __construct(
		public readonly string $network,
		public readonly string $asset,
		public readonly int $asset_decimals,
		public readonly string $facilitator_url,
		public readonly string $eip712_name,
		public readonly string $eip712_version,
		public readonly string $environment_label,
		public readonly string $api_key = '',
		public readonly string $api_key_id = '',
		public readonly string $api_key_secret = '',
		public readonly string $auth_scheme = self::AUTH_NONE,
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

	/**
	 * Profile for Base mainnet USDC via Coinbase CDP's x402 facilitator.
	 *
	 * Note: Coinbase CDP authenticates via per-request JWTs, not Bearer tokens.
	 * X402FacilitatorClient currently only supplies a static Bearer header, so
	 * verify/settle won't succeed until the client learns to sign JWTs. The
	 * profile is wired up so the connector appears in the picker and
	 * test_connection can probe reachability.
	 */
	public static function for_coinbase_cdp( string $api_key_id = '', string $api_key_secret = '' ): self {
		return new self(
			network: 'base',
			// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- EVM contract address literal.
			asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
			asset_decimals: 6,
			facilitator_url: 'https://api.cdp.coinbase.com/platform/v2/x402/',
			eip712_name: 'USD Coin',
			eip712_version: '2',
			environment_label: 'Mainnet',
			api_key_id: $api_key_id,
			api_key_secret: $api_key_secret,
			auth_scheme: self::AUTH_CDP_JWT,
		);
	}
}
