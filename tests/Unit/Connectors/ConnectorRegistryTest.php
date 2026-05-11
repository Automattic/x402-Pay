<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use X402Press\Connectors\ConnectorRegistry;

final class ConnectorRegistryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402press_connectors'] = array();
	}

	public function test_facilitators_only_returns_x402_facilitator_type(): void {
		$GLOBALS['__x402press_connectors'] = array(
			'anthropic'       => array( 'type' => 'ai_provider' ),
			'some_facilitator' => array(
				'type' => ConnectorRegistry::FACILITATOR_TYPE,
				'name' => 'Example x402',
			),
			'another_facilitator' => array(
				'type' => ConnectorRegistry::FACILITATOR_TYPE,
				'name' => 'Another',
			),
		);

		$registry = new ConnectorRegistry();
		$found    = $registry->facilitators();

		$this->assertCount( 2, $found );
		$this->assertArrayHasKey( 'some_facilitator', $found );
		$this->assertArrayHasKey( 'another_facilitator', $found );
		$this->assertArrayNotHasKey( 'anthropic', $found );
	}

	public function test_facilitators_returns_empty_when_none_match(): void {
		$GLOBALS['__x402press_connectors'] = array(
			'openai' => array( 'type' => 'ai_provider' ),
		);

		$this->assertSame( array(), ( new ConnectorRegistry() )->facilitators() );
	}

	public function test_facilitator_returns_matching_connector(): void {
		$GLOBALS['__x402press_connectors'] = array(
			'some_facilitator' => array(
				'type' => ConnectorRegistry::FACILITATOR_TYPE,
				'name' => 'Example x402',
			),
		);

		$registry = new ConnectorRegistry();

		$this->assertSame(
			array(
				'type' => ConnectorRegistry::FACILITATOR_TYPE,
				'name' => 'Example x402',
			),
			$registry->facilitator( 'some_facilitator' )
		);
	}

	public function test_facilitator_rejects_wrong_type(): void {
		$GLOBALS['__x402press_connectors'] = array(
			'anthropic' => array(
				'type' => 'ai_provider',
				'name' => 'Anthropic',
			),
		);

		$this->assertNull( ( new ConnectorRegistry() )->facilitator( 'anthropic' ) );
	}

	public function test_facilitator_returns_null_for_unknown_id(): void {
		$GLOBALS['__x402press_connectors'] = array();

		$this->assertNull( ( new ConnectorRegistry() )->facilitator( 'missing' ) );
	}
}
