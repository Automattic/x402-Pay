<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Services\FacilitatorHooks;
use X402Pay\Services\PaymentSettlementNotifier;

final class PaymentSettlementNotifierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402_pay_actions'] = array();
		$GLOBALS['__x402_pay_filters'] = array();
		$GLOBALS['__x402_pay_http']    = null;
	}

	public function test_each_notify_fires_action_even_when_transaction_repeated(): void {
		$hits = 0;
		add_action(
			FacilitatorHooks::PAYMENT_SETTLED,
			static function () use ( &$hits ): void {
				++$hits;
			}
		);
		$ctx = array(
			'transaction' => 'tx_test_repeatable',
			'post_id'     => 1,
			'amount'      => '0.01',
		);
		$n   = new PaymentSettlementNotifier();
		$n->notify( $ctx );
		$n->notify( $ctx );
		$this->assertSame( 2, $hits );
	}

	public function test_posts_json_to_ledger_url_when_filter_returns_non_empty_url(): void {
		$ledger = 'https://ledger.example/v1/events';
		add_filter(
			FacilitatorHooks::LEDGER_REPORT_URL,
			static function () use ( $ledger ): string {
				return $ledger;
			},
			10,
			2
		);
		$ctx = array(
			'transaction' => 'tx_test_ledger_post',
			'post_id'     => 7,
			'amount'      => '0.05',
		);
		( new PaymentSettlementNotifier() )->notify( $ctx );

		$this->assertIsArray( $GLOBALS['__x402_pay_http'] );
		$this->assertSame( $ledger, $GLOBALS['__x402_pay_http']['url'] );
		$args = $GLOBALS['__x402_pay_http']['args'];
		$this->assertSame( 5, $args['timeout'] );
		$this->assertFalse( $args['blocking'] );
		$this->assertSame( array( 'Content-Type' => 'application/json' ), $args['headers'] );
		$this->assertSame( wp_json_encode( $ctx ), $args['body'] );
	}

	public function test_skips_wp_remote_post_when_ledger_url_filter_returns_empty(): void {
		$GLOBALS['__x402_pay_http'] = null;
		( new PaymentSettlementNotifier() )->notify( array( 'post_id' => 1 ) );
		$this->assertNull( $GLOBALS['__x402_pay_http'] );
	}
}
