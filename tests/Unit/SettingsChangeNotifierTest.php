<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Services\SettingsChangeNotifier;
use X402Pay\Settings\SettingsRepository;

final class SettingsChangeNotifierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402_pay_settings_errors'] = array();
	}

	public function test_notify_paywall_category_deleted_emits_warning(): void {
		( new SettingsChangeNotifier() )->notify_paywall_category_deleted( 'News' );
		$this->assertCount( 1, $GLOBALS['__x402_pay_settings_errors'] );
		$err = $GLOBALS['__x402_pay_settings_errors'][0];
		$this->assertSame( SettingsRepository::OPTION_NAME, $err['setting'] );
		$this->assertSame( 'warning', $err['type'] );
		$this->assertStringContainsString( 'News', $err['message'] );
		$this->assertStringContainsString( 'default', $err['message'] );
	}

	public function test_notify_mode_switched_to_all_posts_emits_info(): void {
		( new SettingsChangeNotifier() )->notify_mode_switched_to_all_posts();
		$this->assertCount( 1, $GLOBALS['__x402_pay_settings_errors'] );
		$err = $GLOBALS['__x402_pay_settings_errors'][0];
		$this->assertSame( SettingsRepository::OPTION_NAME, $err['setting'] );
		$this->assertSame( 'info', $err['type'] );
		$this->assertStringContainsString( 'Every published post', $err['message'] );
	}

}
