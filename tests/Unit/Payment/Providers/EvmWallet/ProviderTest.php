<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit\Payment\Providers\EvmWallet;

use PHPUnit\Framework\TestCase;
use SimpleX402\Payment\PaymentProviderRegistry;
use SimpleX402\Payment\Providers\EvmWallet\Provider;

final class ProviderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_filters'] = array();
	}

	public function test_register_adds_an_eligible_evm_wallet_descriptor(): void {
		Provider::register();
		$out = apply_filters( PaymentProviderRegistry::FILTER, array(), array() );

		$this->assertCount( 1, $out );
		$descriptor = $out[0];
		$this->assertSame( Provider::PROVIDER_ID, $descriptor['id'] );
		$this->assertTrue( $descriptor['is_eligible'] );
	}

	public function test_config_carries_suggestion_icon_urls(): void {
		Provider::register();
		$out = apply_filters( PaymentProviderRegistry::FILTER, array(), array() );

		// EIP-6963 detection itself is purely client-side, but the
		// install-suggestion rows show real official-brand SVGs bundled
		// with the plugin. Their URLs need PHP's `plugins_url()` to
		// resolve, so the keys (rdns) and URLs ride down via config.
		$icons = $out[0]['config']['suggestionIcons'];
		$this->assertArrayHasKey( 'io.metamask', $icons );
		$this->assertArrayHasKey( 'me.rainbow', $icons );
		$this->assertArrayHasKey( 'com.coinbase.wallet', $icons );
		$this->assertStringEndsWith( 'metamask.svg', $icons['io.metamask'] );
		$this->assertStringEndsWith( 'rainbow.svg', $icons['me.rainbow'] );
		$this->assertStringEndsWith( 'coinbase-wallet.svg', $icons['com.coinbase.wallet'] );
	}

	public function test_script_url_points_at_the_co_located_runtime(): void {
		Provider::register();
		$out = apply_filters( PaymentProviderRegistry::FILTER, array(), array() );

		// Each provider's PHP + JS live together under the same folder; the
		// filename is part of the contract with PaywallController, which
		// drops a <script src="…"> on the 402 page.
		$this->assertStringEndsWith(
			'src/Payment/Providers/EvmWallet/script.js',
			$out[0]['script_url']
		);
	}
}
