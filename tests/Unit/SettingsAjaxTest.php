<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Admin\SettingsAjax;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Http\PaywallController;
use SimpleX402\Services\ConnectorCredentialStore;
use SimpleX402\Settings\SettingsRepository;

final class SettingsAjaxTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['action'], $_POST['nonce'], $_POST['fields'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		$GLOBALS['__sx402_options']             = array();
		$GLOBALS['__sx402_json_success']        = null;
		$GLOBALS['__sx402_json_error']          = null;
		$GLOBALS['__sx402_json_error_status_code'] = 0;
		$GLOBALS['__sx402_get_posts_return']    = null;
		$GLOBALS['__sx402_current_user_id']     = 1;
		$GLOBALS['__sx402_current_user_caps']   = array( 'manage_options' );
		$GLOBALS['__sx402_connectors']          = array();
		$GLOBALS['__sx402_existing_terms']      = array(
			array( 'term_id' => 3, 'name' => 'Wall', 'taxonomy' => 'category' ),
		);
	}

	public function test_paywall_scope_save_includes_probe_when_sample_post_exists(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_NONE,
			'paywall_category_term_id' => 3,
			'default_price'            => '0.01',
		);
		$GLOBALS['__sx402_get_posts_return'] = array( 9 );

		$_POST['action']  = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
				'paywall_category_term_id' => 3,
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'values', $data );
		$this->assertArrayHasKey( 'probe', $data );
		$this->assertIsArray( $data['probe'] );
		$this->assertSame( 'https://example.test/p/9/', $data['probe']['url'] );
		$this->assertSame(
			wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
			$data['probe']['nonce']
		);
	}

	public function test_scope_save_without_matching_post_returns_probe_reason(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_NONE,
			'paywall_category_term_id' => 3,
			'default_price'            => '0.01',
		);
		$GLOBALS['__sx402_get_posts_return'] = array();

		$_POST['action']  = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
				'paywall_category_term_id' => 3,
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertSame( 'no_matching_post', $data['probe']['reason'] ?? '' );
	}

	public function test_non_scope_save_omits_probe_key(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'paywall_category_term_id' => 3,
			'default_price'            => '0.01',
		);

		$_POST['action']  = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode( array( 'default_price' => '0.02' ) );

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'values', $data );
		$this->assertArrayNotHasKey( 'probe', $data );
	}

	public function test_oversized_fields_payload_is_rejected_before_decode(): void {
		$_POST['action'] = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = str_repeat( 'a', SettingsAjax::MAX_FIELDS_BYTES + 1 );

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$this->assertNull( $GLOBALS['__sx402_json_success'] );
		$this->assertSame( 'fields_too_large', $GLOBALS['__sx402_json_error']['error'] ?? '' );
		$this->assertSame( 413, $GLOBALS['__sx402_json_error_status_code'] );
	}

	public function test_unregistered_facilitator_slots_are_dropped(): void {
		$GLOBALS['__sx402_connectors'] = array(
			'simple_x402_test' => array( 'type' => ConnectorRegistry::FACILITATOR_TYPE ),
		);

		$_POST['action'] = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'facilitators' => array(
					'simple_x402_test' => array( 'wallet_address' => '0xabc' ),
					'totally_unknown'  => array( 'wallet_address' => '0xdef' ),
				),
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertArrayHasKey( 'simple_x402_test', $data['values']['facilitators'] );
		$this->assertArrayNotHasKey( 'totally_unknown', $data['values']['facilitators'] );
	}

	public function test_connector_secrets_only_accepts_api_key_facilitators(): void {
		$GLOBALS['__sx402_connectors'] = array(
			'no_auth_connector' => array(
				'type'           => ConnectorRegistry::FACILITATOR_TYPE,
				'authentication' => array( 'method' => 'none' ),
			),
			'cdp_connector'     => array(
				'type'           => ConnectorRegistry::FACILITATOR_TYPE,
				'authentication' => array( 'method' => 'api_key' ),
			),
		);

		$_POST['action'] = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'connector_secrets' => array(
					'no_auth_connector' => 'should-be-ignored',
					'cdp_connector'     => 'real-secret',
					'totally_unknown'   => 'also-ignored',
					'BAD!ID'            => 'rejected-by-charset',
				),
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$store = new ConnectorCredentialStore();
		$this->assertSame( 'real-secret', $store->secret( 'cdp_connector' ) );
		$this->assertSame( '', $store->secret( 'no_auth_connector' ) );
		$this->assertSame( '', $store->secret( 'totally_unknown' ) );
		$this->assertSame( '', $store->secret( 'BAD!ID' ) );

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertSame(
			array( 'cdp_connector' ),
			array_keys( $data['connectorCredentials'] ?? array() )
		);
	}

	public function test_connector_secret_null_clears_stored_api_key(): void {
		$GLOBALS['__sx402_connectors'] = array(
			'cdp_connector' => array(
				'type'           => ConnectorRegistry::FACILITATOR_TYPE,
				'authentication' => array( 'method' => 'api_key' ),
			),
		);

		$store = new ConnectorCredentialStore();
		$store->set_secret( 'cdp_connector', 'old-secret' );

		$_POST['action'] = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'connector_secrets' => array(
					'cdp_connector' => null,
				),
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$this->assertSame( '', $store->secret( 'cdp_connector' ) );
		$data = $GLOBALS['__sx402_json_success'];
		$this->assertSame(
			array( 'cdp_connector' ),
			array_keys( $data['connectorCredentials'] ?? array() )
		);
		$this->assertFalse( $data['connectorCredentials']['cdp_connector']['has_secret'] );
	}
}
