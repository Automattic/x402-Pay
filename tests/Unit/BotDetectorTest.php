<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Services\BotDetector;

final class BotDetectorTest extends TestCase {

	public function test_googlebot_user_agent_is_bot(): void {
		$d = new BotDetector( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
		$this->assertTrue( $d->is_bot() );
	}

	public function test_typical_browser_is_not_bot(): void {
		$d = new BotDetector(
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
		);
		$this->assertFalse( $d->is_bot() );
	}

	public function test_empty_user_agent_is_not_bot(): void {
		$d = new BotDetector( '' );
		$this->assertFalse( $d->is_bot() );
	}
}
