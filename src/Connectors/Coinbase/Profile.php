<?php
/**
 * Coinbase-specific FacilitatorProfile factory.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Connectors\Coinbase;

use X402Press\Services\FacilitatorProfile;

/**
 * Constructs the Base-mainnet-USDC profile for Coinbase's CDP x402 facilitator
 * and pairs it with the EdDSA {@see JwtSigner} so every outbound request is
 * signed per CDP spec. Lives next to the rest of the Coinbase code so the
 * core `FacilitatorProfile` class stays connector-agnostic.
 */
final class Profile {

	public static function for_cdp( string $api_key_id = '', string $api_key_secret = '' ): FacilitatorProfile {
		return new FacilitatorProfile(
			network: 'base',
			// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- EVM contract address literal.
			asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
			asset_decimals: 6,
			facilitator_url: 'https://api.cdp.coinbase.com/platform/v2/x402/',
			eip712_name: 'USD Coin',
			eip712_version: '2',
			environment_label: 'Mainnet',
			signer: new JwtSigner( $api_key_id, $api_key_secret ),
		);
	}
}
