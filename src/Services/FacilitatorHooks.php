<?php
/**
 * Hook names shared across paywall, settings, and settlement reporting.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Centralises filter/action slugs so the main plugin and companions stay aligned.
 */
final class FacilitatorHooks {

	public const MANAGED_POOL_PAY_TO = 'simple_x402_managed_pool_pay_to';

	public const PAYMENT_SETTLED = 'simple_x402_payment_settled';

	public const LEDGER_REPORT_URL = 'simple_x402_ledger_report_url';
}
