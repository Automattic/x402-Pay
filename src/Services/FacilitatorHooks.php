<?php
/**
 * Hook names shared across paywall, settings, and settlement reporting.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

/**
 * Centralises filter/action slugs so the main plugin and companions stay aligned.
 */
final class FacilitatorHooks {

	public const MANAGED_POOL_PAY_TO = 'x402press_managed_pool_pay_to';

	public const PAYMENT_SETTLED = 'x402press_payment_settled';

	public const LEDGER_REPORT_URL = 'x402press_ledger_report_url';

	/**
	 * Per-connector admin UI strings + validation rules. Filter signature:
	 * `apply_filters( CONNECTOR_ADMIN_META, array $meta, string $connector_id )`.
	 * Connectors that need API key inputs hook this to provide intro copy,
	 * docs links, placeholders, regex patterns, and error messages — keeping
	 * connector-specific text out of the generic admin React app.
	 */
	public const CONNECTOR_ADMIN_META = 'x402press_connector_admin_meta';
}
