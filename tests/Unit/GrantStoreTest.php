<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Services\GrantStore;

final class GrantStoreTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402_pay_transients'] = array();
	}

	public function test_redeem_false_for_unknown_token(): void {
		$store = new GrantStore();
		$this->assertFalse( $store->redeem( 'not-a-real-token', '/foo' ) );
	}

	public function test_issue_then_redeem_with_matching_path(): void {
		$store = new GrantStore();
		$token = $store->issue( '/foo', 60, array( 'tx' => '0x1' ) );
		$this->assertNotSame( '', $token );
		$this->assertTrue( $store->redeem( $token, '/foo' ) );
	}

	public function test_redeem_rejects_path_mismatch(): void {
		$store = new GrantStore();
		$token = $store->issue( '/foo', 60, array() );
		// A token leaked from one URL must not redeem against another, even
		// while it's still live in the store.
		$this->assertFalse( $store->redeem( $token, '/bar' ) );
	}

	public function test_redeem_rejects_empty_token(): void {
		$store = new GrantStore();
		$store->issue( '/foo', 60, array() );
		$this->assertFalse( $store->redeem( '', '/foo' ) );
	}

	public function test_non_positive_ttl_does_not_issue(): void {
		$store = new GrantStore();
		$this->assertSame( '', $store->issue( '/foo', 0, array() ) );
		$this->assertSame( '', $store->issue( '/foo', -5, array() ) );
	}

	public function test_each_issue_returns_a_fresh_token(): void {
		$store  = new GrantStore();
		$first  = $store->issue( '/foo', 60, array() );
		$second = $store->issue( '/foo', 60, array() );
		$this->assertNotSame( $first, $second );
		$this->assertTrue( $store->redeem( $first, '/foo' ) );
		$this->assertTrue( $store->redeem( $second, '/foo' ) );
	}

	public function test_stored_value_does_not_contain_the_raw_token(): void {
		$store = new GrantStore();
		$token = $store->issue( '/foo', 60, array( 'transaction' => '0xdead' ) );

		// The transient blob the DB holds should never reveal the redeemable
		// secret — anyone with read access to wp_options/wp_transients
		// shouldn't be able to bypass paywalls.
		$serialised = serialize( $GLOBALS['__x402_pay_transients'] );
		$this->assertStringNotContainsString( $token, $serialised );
	}
}
