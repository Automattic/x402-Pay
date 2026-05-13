<?php
/**
 * EIP-6963 EVM wallet provider registration.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Payment\Providers\EvmWallet;

use X402Press\Payment\PaymentProviderRegistry;

/**
 * Renders one row per browser-extension wallet that announces itself via the
 * EIP-6963 "Multi Injected Provider Discovery" protocol (MetaMask, Rainbow,
 * Coinbase Wallet extension, Trust, etc.). The PHP side just registers a
 * single slot — all the discovery + per-wallet button rendering happens in
 * `script.js` on the client. The slot expands into 0..N wallet buttons
 * depending on what the visitor has installed.
 *
 * Co-located with `script.js` (browser side) so the provider's PHP +
 * client-side runtime live in one folder.
 */
final class Provider {

	public const PROVIDER_ID = 'evm-wallet';

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
		unset( $context );

		$providers[] = array(
			'id'          => self::PROVIDER_ID,
			'label'       => __( 'Pay with a browser wallet', 'x402-pay' ),
			'script_url'  => plugins_url( 'src/Payment/Providers/EvmWallet/script.js', X402PRESS_FILE ),
			'is_eligible' => true,
		);
		return $providers;
	}
}
