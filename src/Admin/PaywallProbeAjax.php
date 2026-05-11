<?php
/**
 * admin-ajax: paywall self-check descriptor for the stored settings row.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Admin;

use X402Press\Settings\SettingsRepository;

/**
 * Lets the React UI fetch `{ probe }` without persisting (used by “Test paywall response”).
 */
final class PaywallProbeAjax {

	public const ACTION = 'x402press_paywall_probe';
	public const NONCE  = 'x402press_paywall_probe_nonce';

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
