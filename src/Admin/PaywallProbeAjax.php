<?php
/**
 * admin-ajax: paywall self-check descriptor for the stored settings row.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Admin;

use X402Pay\Settings\SettingsRepository;

/**
 * Lets the React UI fetch `{ probe }` without persisting (used by “Test paywall response”).
 */
final class PaywallProbeAjax {

	public const ACTION = 'x402_pay_paywall_probe';
	public const NONCE  = 'x402_pay_paywall_probe_nonce';

	public function __construct( private readonly SettingsRepository $settings ) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$merged = get_option( SettingsRepository::OPTION_NAME, array() );
		if ( ! is_array( $merged ) ) {
			$merged = array();
		}

		wp_send_json_success( $this->settings->build_paywall_probe_for_merged_row( $merged ) );
	}
}
