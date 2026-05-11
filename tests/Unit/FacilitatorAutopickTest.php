<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Connectors\ConnectorRegistry;
use X402Press\Connectors\TestConnectorRegistrar;
use X402Press\Plugin;
use X402Press\Settings\SettingsRepository;

final class FacilitatorAutopickTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402press_options']    = array();
		$GLOBALS['__x402press_connectors'] = array();
		$GLOBALS['__x402press_filters']   = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['__x402press_options']['x402press_facilitator_autopicked'],
			$GLOBALS['__x402press_options']['x402press_settings']
		);
		parent::tearDown();
	}

	public function test_autopick_selects_test_connector_when_row_empty(): void {
		$GLOBALS['__x402press_options']                      = array();
		$GLOBALS['__x402press_connectors']                   = array(
			TestConnectorRegistrar::ID => array( 'type' => ConnectorRegistry::FACILITATOR_TYPE ),
		);
		$GLOBALS['__x402press_options']['x402press_settings'] = array();

		$ref = new \ReflectionMethod( Plugin::class, 'maybe_autopick_facilitator' );
		$ref->setAccessible( true );
		$ref->invoke( null, new SettingsRepository() );

		$repo = new SettingsRepository();
		$this->assertSame( TestConnectorRegistrar::ID, $repo->selected_facilitator_id() );
		$this->assertNotEmpty( get_option( 'x402press_facilitator_autopicked', false ) );
	}

	public function test_autopick_skips_when_flag_already_set(): void {
		$GLOBALS['__x402press_options'] = array(
			'x402press_facilitator_autopicked' => '1',
			'x402press_settings'               => array(
				'selected_facilitator_id' => '',
			),
		);
		$GLOBALS['__x402press_connectors'] = array(
			TestConnectorRegistrar::ID => array( 'type' => ConnectorRegistry::FACILITATOR_TYPE ),
		);

		$ref = new \ReflectionMethod( Plugin::class, 'maybe_autopick_facilitator' );
		$ref->setAccessible( true );
		$ref->invoke( null, new SettingsRepository() );

		$this->assertSame( '', ( new SettingsRepository() )->selected_facilitator_id() );
	}

	public function test_autopick_skips_when_test_connector_missing(): void {
		$GLOBALS['__x402press_options']    = array( 'x402press_settings' => array() );
		$GLOBALS['__x402press_connectors'] = array();

		$ref = new \ReflectionMethod( Plugin::class, 'maybe_autopick_facilitator' );
		$ref->setAccessible( true );
		$ref->invoke( null, new SettingsRepository() );

		$this->assertSame( '', ( new SettingsRepository() )->selected_facilitator_id() );
		$this->assertFalse( get_option( 'x402press_facilitator_autopicked', false ) );
	}

}
