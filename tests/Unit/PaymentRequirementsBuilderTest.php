<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Services\FacilitatorProfile;
use X402Pay\Services\PaymentRequirementsBuilder;

final class PaymentRequirementsBuilderTest extends TestCase {

	public function test_builds_base_sepolia_usdc_requirements_in_test_mode(): void {
		$builder = new PaymentRequirementsBuilder(
			FacilitatorProfile::for_test()
		);
		$req     = $builder->build(
			'0x1111111111111111111111111111111111111111',
			'0.01',
			'https://example.com/article',
			'Test post'
		);

		$this->assertSame( 'exact', $req['scheme'] );
		$this->assertSame( 'base-sepolia', $req['network'] );
		$this->assertSame( '0x036CbD53842c5426634e7929541eC2318f3dCF7e', $req['asset'] );
		$this->assertSame( '0x1111111111111111111111111111111111111111', $req['payTo'] );
		$this->assertSame( '10000', $req['maxAmountRequired'] ); // 0.01 USDC at 6 decimals.
		$this->assertSame( 'https://example.com/article', $req['resource'] );
		$this->assertSame( 'Test post', $req['description'] );
		$this->assertArrayHasKey( 'maxTimeoutSeconds', $req );
		$this->assertSame( array( 'name' => 'USDC', 'version' => '2' ), $req['extra'] );
	}

	public function test_price_with_many_decimals_is_truncated_to_asset_precision(): void {
		$builder = new PaymentRequirementsBuilder(
			FacilitatorProfile::for_test()
		);
		$req = $builder->build( '0xabc', '0.1234567', 'https://example.com', '' );
		$this->assertSame( '123456', $req['maxAmountRequired'] );
	}

	public function test_whole_dollar_price(): void {
		$builder = new PaymentRequirementsBuilder(
			FacilitatorProfile::for_test()
		);
		$req = $builder->build( '0xabc', '2', 'https://example.com', '' );
		$this->assertSame( '2000000', $req['maxAmountRequired'] );
	}

	public function test_zero_or_negative_price_returns_zero(): void {
		$builder = new PaymentRequirementsBuilder(
			FacilitatorProfile::for_test()
		);
		$this->assertSame( '0', $builder->build( '0xabc', '0', 'https://example.com', '' )['maxAmountRequired'] );
		$this->assertSame( '0', $builder->build( '0xabc', '-1', 'https://example.com', '' )['maxAmountRequired'] );
	}
}
