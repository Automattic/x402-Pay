<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Connectors\TestConnectorRegistrar;
use SimpleX402\Plugin;
use SimpleX402\Settings\SettingsRepository;

final class FacilitatorAutopickTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']    = array();
		$GLOBALS['__sx402_connectors'] = array();
		$GLOBALS['__sx402_filters']   = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['__sx402_options']['simple_x402_facilitator_autopicked'],
			$GLOBALS['__sx402_options']['simple_x402_settings']
		);
		parent::tearDown();
	}

	public function test_autopick_selects_test_connector_when_row_empty(): void {
		$GLOBALS['__sx402_options']                      = array();
		$GLOBALS['__sx402_connectors']                   = array(
			TestConnectorRegistrar::ID => array( 'type' => ConnectorRegistry::FACILITATOR_TYPE ),
		);
		$GLOBALS['__sx402_options']['simple_x402_settings'] = array();

		$ref = new \ReflectionMethod( Plugin::class, 'maybe_autopick_facilitator' );
		$ref->setAccessible( true );
		$ref->invoke( null, new SettingsRepository() );

		$repo = new SettingsRepository();
		$this->assertSame( TestConnectorRegistrar::ID, $repo->selected_facilitator_id() );
		$this->assertNotEmpty( get_option( 'simple_x402_facilitator_autopicked', false ) );
	}

	public function test_autopick_skips_when_flag_already_set(): void {
		$GLOBALS['__sx402_options'] = array(
			'simple_x402_facilitator_autopicked' => '1',
			'simple_x402_settings'               => array(
				'selected_facilitator_id' => '',
			),
		);
		$GLOBALS['__sx402_connectors'] = array(
			TestConnectorRegistrar::ID => array( 'type' => ConnectorRegistry::FACILITATOR_TYPE ),
		);

		$ref = new \ReflectionMethod( Plugin::class, 'maybe_autopick_facilitator' );
		$ref->setAccessible( true );
		$ref->invoke( null, new SettingsRepository() );

		$this->assertSame( '', ( new SettingsRepository() )->selected_facilitator_id() );
	}

	public function test_autopick_skips_when_test_connector_missing(): void {
		$GLOBALS['__sx402_options']    = array( 'simple_x402_settings' => array() );
		$GLOBALS['__sx402_connectors'] = array();

		$ref = new \ReflectionMethod( Plugin::class, 'maybe_autopick_facilitator' );
		$ref->setAccessible( true );
		$ref->invoke( null, new SettingsRepository() );

		$this->assertSame( '', ( new SettingsRepository() )->selected_facilitator_id() );
		$this->assertFalse( get_option( 'simple_x402_facilitator_autopicked', false ) );
	}

}
