<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Services\AllPostsModeNoticeEmitter;
use X402Pay\Services\SettingsChangeNotifier;

final class AllPostsModeNoticeEmitterTest extends TestCase {

	private AllPostsModeNoticeEmitter $emitter;

	protected function setUp(): void {
		$GLOBALS['__x402_pay_settings_errors'] = array();
		$this->emitter = new AllPostsModeNoticeEmitter( new SettingsChangeNotifier() );
	}

	public function test_emits_notice_when_mode_flips_to_all_posts(): void {
		( $this->emitter )(
			array( 'paywall_mode' => 'category' ),
			array( 'paywall_mode' => 'all-posts' )
		);
		$codes = array_column( $GLOBALS['__x402_pay_settings_errors'], 'code' );
		$this->assertContains( 'x402_pay_all_posts_mode', $codes );
	}

	public function test_does_not_emit_when_mode_already_all_posts(): void {
		( $this->emitter )(
			array( 'paywall_mode' => 'all-posts' ),
			array( 'paywall_mode' => 'all-posts' )
		);
		$this->assertSame( array(), $GLOBALS['__x402_pay_settings_errors'] );
	}

	public function test_does_not_emit_when_mode_stays_category(): void {
		( $this->emitter )(
			array( 'paywall_mode' => 'category' ),
			array( 'paywall_mode' => 'category' )
		);
		$this->assertSame( array(), $GLOBALS['__x402_pay_settings_errors'] );
	}

	public function test_ignores_non_array_new_value(): void {
		( $this->emitter )( array( 'paywall_mode' => 'category' ), 'garbage' );
		$this->assertSame( array(), $GLOBALS['__x402_pay_settings_errors'] );
	}

	public function test_treats_non_array_old_value_as_first_save(): void {
		// First save sets mode=all-posts — old_value is `false` from WP.
		( $this->emitter )( false, array( 'paywall_mode' => 'all-posts' ) );
		$codes = array_column( $GLOBALS['__x402_pay_settings_errors'], 'code' );
		$this->assertContains( 'x402_pay_all_posts_mode', $codes );
	}
}
