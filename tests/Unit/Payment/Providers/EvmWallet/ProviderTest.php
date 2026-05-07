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
		// EIP-6963 detection happens client-side, so the PHP descriptor
		// carries no per-wallet config — script.js builds rows from the
		// announced provider events.
		$this->assertSame( array(), $descriptor['config'] );
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
