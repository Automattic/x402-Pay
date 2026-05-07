<?php
/**
 * Gravatar Hosted Wallet payment provider registration.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Payment\Providers\GravatarWallet;

use SimpleX402\Payment\PaymentProviderRegistry;

/**
 * Reference provider that ships with simple-x402. Hooks into the
 * `simple_x402_payment_providers` filter to advertise a "Pay with Gravatar
 * Wallet" button for any 402 response. The popup signs USDC on Base
 * mainnet only — JS surfaces a console.warn for other networks rather than
 * hiding the button, so testing flows aren't blocked.
 *
 * Co-located with `script.js` (browser side) so the provider's PHP +
 * client-side runtime live in one folder.
 */
final class Provider {

	/** Empty string disables the provider. */
	public const WALLET_ORIGIN_FILTER = 'simple_x402_gravatar_wallet_origin';

	public const DEFAULT_WALLET_ORIGIN = 'https://gravatar.com';

	public const PROVIDER_ID = 'gravatar-wallet';

	public static function register(): void {
		add_filter(
			PaymentProviderRegistry::FILTER,
			array( self::class, 'register_provider' ),
			10,
			2
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $providers Existing provider list.
	 * @param array<string,mixed>            $context   Filter context (requirements, resource_url, request).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_provider( array $providers, array $context ): array {
		$origin = self::wallet_origin();
		if ( '' === $origin ) {
			return $providers;
		}
		$providers[] = array(
			'id'          => self::PROVIDER_ID,
			'label'       => __( 'Pay with Gravatar Wallet', 'simple-x402' ),
			'script_url'  => plugins_url( 'src/Payment/Providers/GravatarWallet/script.js', SIMPLE_X402_FILE ),
			'is_eligible' => true,
			'config'      => array( 'gravatarOrigin' => $origin ),
		);
		return $providers;
	}

	/**
	 * Resolved popup origin. Trimmed of trailing slashes so URL assembly
	 * doesn't produce `https://gravatar.com//wallet/authorize`.
	 */
	public static function wallet_origin(): string {
		$raw = (string) apply_filters( self::WALLET_ORIGIN_FILTER, self::DEFAULT_WALLET_ORIGIN );
		return rtrim( trim( $raw ), '/' );
	}
}
