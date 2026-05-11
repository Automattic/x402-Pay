<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\RuleResolver;

final class RuleResolverTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_filters'] = array();
	}

	public function test_returns_null_when_no_filter_matches(): void {
		$resolver = new RuleResolver();
		$this->assertNull(
			$resolver->resolve(
				array( 'path' => '/x', 'method' => 'GET', 'post_id' => 0 )
			)
		);
	}

	public function test_returns_rule_from_filter_with_defaults_applied(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.25' ), 10, 2 );
		$resolver = new RuleResolver();
		$rule     = $resolver->resolve(
			array( 'path' => '/x', 'method' => 'GET', 'post_id' => 0 )
		);

		$this->assertSame( '0.25', $rule['price'] );
		$this->assertSame( 86400, $rule['ttl'] );
		$this->assertSame( '', $rule['description'] );
	}

	public function test_rejects_rule_with_invalid_price(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => 'free' ), 10, 2 );
		$resolver = new RuleResolver();
		$this->assertNull(
			$resolver->resolve(
				array( 'path' => '/x', 'method' => 'GET', 'post_id' => 0 )
			)
		);
	}

	public function test_rejects_rule_with_scientific_notation_price(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '1e3' ), 10, 2 );
		$resolver = new RuleResolver();
		$this->assertNull(
			$resolver->resolve(
				array( 'path' => '/x', 'method' => 'GET', 'post_id' => 0 )
			)
		);
	}

	public function test_non_positive_ttl_falls_back_to_default(): void {
		add_filter(
			'simple_x402_rule_for_request',
			static fn () => array( 'price' => '0.1', 'ttl' => 0 ),
			10,
			2
		);
		$resolver = new RuleResolver();
		$rule     = $resolver->resolve(
			array( 'path' => '/x', 'method' => 'GET', 'post_id' => 0 )
		);
		$this->assertSame( 86400, $rule['ttl'] );
	}
}
