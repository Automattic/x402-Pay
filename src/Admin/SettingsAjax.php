<?php
/**
 * admin-ajax handler for per-card settings saves.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Admin;

use X402Press\Connectors\ConnectorRegistry;
use X402Press\Services\ConnectorCredentialStore;
use X402Press\Settings\SettingsRepository;

/**
 * Powers the React Settings → x402press per-card "Save changes" buttons.
 *
 * Registered on `wp_ajax_x402press_save_settings`. Admin-only,
 * nonce-checked. Accepts a partial `fields` payload and forwards it to
 * SettingsRepository::update(), which merges into the stored option without
 * clobbering unrelated keys. Returns the merged row so the React state can
 * reset its per-card `saved` snapshot.
 */
final class SettingsAjax {

	public const ACTION = 'x402press_save_settings';
	public const NONCE  = 'x402press_save_settings_nonce';

	/**
	 * Max raw `fields` JSON length, in bytes. Larger payloads are rejected
	 * before json_decode runs — admins shouldn't be sending us 1 MB blobs,
	 * and stopping here keeps a stolen-session attacker from inflating the
	 * autoloaded settings option.
	 */
	public const MAX_FIELDS_BYTES = 65536;

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ConnectorCredentialStore $credentials = new ConnectorCredentialStore(),
		private readonly ConnectorRegistry $connectors = new ConnectorRegistry(),
	) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$raw = isset( $_POST['fields'] )
			? wp_unslash( (string) $_POST['fields'] )
			: '';
		if ( strlen( $raw ) > self::MAX_FIELDS_BYTES ) {
			wp_send_json_error( array( 'error' => 'fields_too_large' ), 413 );
			return;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'error' => 'invalid_fields' ), 400 );
			return;
		}

		$registered_ids = array_keys( $this->connectors->facilitators() );

		// Restrict the facilitators map to currently-registered connector IDs
		// so a stolen session can't seed the option row with junk slots
		// (sanitize_facilitators caps the size separately).
		if ( isset( $decoded['facilitators'] ) && is_array( $decoded['facilitators'] ) ) {
			$decoded['facilitators'] = array_intersect_key(
				$decoded['facilitators'],
				array_flip( $registered_ids )
			);
		}

		$secrets = array();
		if ( isset( $decoded['connector_secrets'] ) && is_array( $decoded['connector_secrets'] ) ) {
			$secrets = $decoded['connector_secrets'];
			unset( $decoded['connector_secrets'] );
		}
		$accepted_secret_ids = array();
		foreach ( $secrets as $connector_id => $value ) {
			if ( ! is_string( $connector_id ) || ( ! is_string( $value ) && null !== $value ) ) {
				continue;
			}
			// Drop connector IDs that aren't currently registered, or that
			// don't authenticate with an api_key. Without this an admin
			// could write `connectors_x402_facilitator_<arbitrary>_api_key`
			// option rows just by submitting the right JSON.
			if ( ! $this->is_api_key_connector( $connector_id ) ) {
				continue;
			}
			// Empty string from the UI means "no change"; explicit `null`
			// clears the stored secret. This way normal saves can omit the
			// field without wiping a previously-stored value.
			if ( '' === $value ) {
				continue;
			}
			$this->credentials->set_secret( $connector_id, is_string( $value ) ? $value : '' );
			$accepted_secret_ids[] = $connector_id;
		}

		$merged = $this->settings->update( $decoded );

		$data          = array( 'values' => $merged );
		$scope_changed = array_key_exists( 'paywall_mode', $decoded )
			|| array_key_exists( 'paywall_category_term_id', $decoded );
		if ( $scope_changed ) {
			$data = array_merge( $data, $this->settings->build_paywall_probe_for_merged_row( $merged ) );
		}

		if ( array() !== $accepted_secret_ids ) {
			$data['connectorCredentials'] = array();
			foreach ( $accepted_secret_ids as $connector_id ) {
				$data['connectorCredentials'][ $connector_id ] = $this->credentials->status( $connector_id );
			}
		}

		wp_send_json_success( $data );
	}

	/**
	 * True only when $connector_id names a registered facilitator that
	 * declares `authentication.method = api_key`. Any other ID — unknown,
	 * invalid charset, wrong type, no-auth — is rejected so the secret
	 * store never grows option rows for connectors it can't actually use.
	 */
	private function is_api_key_connector( string $connector_id ): bool {
		if ( '' === $connector_id ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $connector_id ) ) {
			return false;
		}
		$connector = $this->connectors->facilitator( $connector_id );
		if ( null === $connector ) {
			return false;
		}
		return 'api_key' === ( $connector['authentication']['method'] ?? '' );
	}
}
