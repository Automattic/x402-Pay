<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\FacilitatorProfile;

final class FacilitatorProfileTest extends TestCase {

	public function test_test_profile_matches_x402_org_base_sepolia(): void {
		$profile = FacilitatorProfile::for_test();

		$this->assertSame( 'base-sepolia', $profile->network );
		$this->assertSame( '0x036CbD53842c5426634e7929541eC2318f3dCF7e', $profile->asset );
		$this->assertSame( 6, $profile->asset_decimals );
		$this->assertSame( 'https://x402.org/facilitator/', $profile->facilitator_url );
		$this->assertSame( 'USDC', $profile->eip712_name );
		$this->assertSame( '2', $profile->eip712_version );
		$this->assertSame( 'Testnet', $profile->environment_label );
		$this->assertNull( $profile->signer );
	}
}
