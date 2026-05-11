<?php
/**
 * admin-ajax handler for probing a connector's facilitator.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Admin;

use X402Press\Facilitator\FacilitatorResolver;

/**
 * Powers the Settings → x402press "Test connection" button.
 *
 * Registered on `wp_ajax_x402press_test_connector`. Admin-only,
 * nonce-checked. Resolves the posted connector_id through FacilitatorResolver
 * and returns the TestResult as JSON.
 */
final class TestConnectionAjax {

	public const ACTION = 'x402press_test_connector';
	public const NONCE  = 'x402press_test_connector_nonce';

	public function __construct( private readonly FacilitatorResolver $resolver ) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$id = isset( $_POST['connector_id'] )
			? (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) wp_unslash( $_POST['connector_id'] ) ) )
			: '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'error' => 'missing_connector_id' ), 400 );
			return;
		}

		$client = $this->resolver->resolve( $id );
		if ( null === $client ) {
			wp_send_json_error(
				array(
					'error'        => 'unknown_connector',
					'connector_id' => $id,
				),
				404
			);
			return;
		}

		$probe = $client->test_connection();
		wp_send_json_success(
			array(
				'connector_id' => $id,
				'ok'           => $probe->ok,
				'http_code'    => $probe->http_code,
				'duration_ms'  => $probe->duration_ms,
				'error'        => $probe->error,
			)
		);
	}
}
