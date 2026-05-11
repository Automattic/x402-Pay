<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Admin\PaywallProbeAjax;
use X402Press\Http\PaywallController;
use X402Press\Settings\SettingsRepository;

final class PaywallProbeAjaxTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['action'], $_POST['nonce'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		$GLOBALS['__x402press_options']           = array();
		$GLOBALS['__x402press_json_success']      = null;
		$GLOBALS['__x402press_get_posts_return']  = array( 11 );
		$GLOBALS['__x402press_current_user_id']   = 1;
		$GLOBALS['__x402press_current_user_caps'] = array( 'manage_options' );
	}

	public function test_returns_probe_descriptor_from_stored_option(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'paywall_category_term_id' => 2,
			'default_price'            => '0.01',
		);

		$_POST['action'] = PaywallProbeAjax::ACTION;
		$_POST['nonce']  = 'x';

		( new PaywallProbeAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__x402press_json_success'];
		$this->assertSame( 'https://example.test/p/11/', $data['probe']['url'] );
		$this->assertSame(
			wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
			$data['probe']['nonce']
		);
	}
}
