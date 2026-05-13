<?php
/**
 * Emits hooks (and optional HTTP) after a successful on-chain settle.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Services;

/**
 * Fires {@see FacilitatorHooks::PAYMENT_SETTLED} and optionally POSTs JSON to a
 * filterable ledger URL. Callers may invoke `notify()` more than once for the
 * same settlement (retries, concurrency); the ledger API is expected to
 * de-duplicate on `transaction` (or equivalent). Hook listeners that persist
 * data should be idempotent for the same context.
 */
final class PaymentSettlementNotifier {

	/**
	 * @param array<string,mixed> $context post_id, path, amount, transaction, network, connector_id, resource_url, …
	 */
	public function notify( array $context ): void {
		do_action( FacilitatorHooks::PAYMENT_SETTLED, $context );

		$url = (string) apply_filters( FacilitatorHooks::LEDGER_REPORT_URL, '', $context );
		if ( '' === $url ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $context ),
			)
		);
	}
}
