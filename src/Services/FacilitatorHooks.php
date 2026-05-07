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

	/**
	 * Per-connector admin UI strings + validation rules. Filter signature:
	 * `apply_filters( CONNECTOR_ADMIN_META, array $meta, string $connector_id )`.
	 * Connectors that need API key inputs hook this to provide intro copy,
	 * docs links, placeholders, regex patterns, and error messages — keeping
	 * connector-specific text out of the generic admin React app.
	 */
	public const CONNECTOR_ADMIN_META = 'simple_x402_connector_admin_meta';
}
