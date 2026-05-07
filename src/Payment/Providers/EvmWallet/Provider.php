<?php
/**
 * EIP-6963 EVM wallet provider registration.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Payment\Providers\EvmWallet;

use SimpleX402\Payment\PaymentProviderRegistry;

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
		$providers[] = array(
			'id'          => self::PROVIDER_ID,
			'label'       => __( 'Pay with a browser wallet', 'simple-x402' ),
			'script_url'  => plugins_url( 'src/Payment/Providers/EvmWallet/script.js', SIMPLE_X402_FILE ),
			'is_eligible' => true,
			// EIP-6963 detection itself is purely client-side, but the
			// install-suggestion rows show official brand SVGs for popular
			// wallets that aren't installed. Those files live next to the
			// script and need plugin-aware URLs to resolve, so PHP hands
			// the URL map down via config.
			'config'      => array(
				'suggestionIcons' => self::suggestion_icon_urls(),
			),
		);
		return $providers;
	}

	/**
	 * Map of suggested-wallet `rdns` → public icon URL. Keys must match the
	 * SUGGESTED_WALLETS list in script.js; missing keys render that row
	 * iconless.
	 *
	 * @return array<string,string>
	 */
	private static function suggestion_icon_urls(): array {
		$base = plugins_url( 'src/Payment/Providers/EvmWallet/icons/', SIMPLE_X402_FILE );
		return array(
			'io.metamask'         => $base . 'metamask.svg',
			'me.rainbow'          => $base . 'rainbow.svg',
			'com.coinbase.wallet' => $base . 'coinbase-wallet.svg',
		);
	}
}
