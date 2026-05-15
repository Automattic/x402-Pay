<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Admin\SettingsPage;
use X402Pay\Connectors\ConnectorRegistry;
use X402Pay\Settings\SettingsRepository;

final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402_pay_options']             = array();
		$GLOBALS['__x402_pay_registered_settings'] = array();
		$GLOBALS['__x402_pay_enqueued_scripts']    = array();
		$GLOBALS['__x402_pay_enqueued_styles']     = array();
		$GLOBALS['__x402_pay_localized_data']      = array();
		$GLOBALS['__x402_pay_connectors']          = array();
		$GLOBALS['__x402_pay_filters']             = array();
		$GLOBALS['__x402_pay_existing_terms']      = array(
			array( 'term_id' => 1, 'name' => 'x402paywall', 'taxonomy' => 'category' ),
			array( 'term_id' => 2, 'name' => 'News', 'taxonomy' => 'category' ),
		);
	}

	public function test_enqueue_assets_registers_script_and_bootstrap_on_plugin_page(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->enqueue_assets( 'settings_page_' . SettingsPage::MENU_SLUG );

		$this->assertArrayHasKey( SettingsPage::SCRIPT_HANDLE, $GLOBALS['__x402_pay_enqueued_scripts'] );
		$this->assertArrayHasKey( 'wp-components', $GLOBALS['__x402_pay_enqueued_styles'] );

		$boot = $GLOBALS['__x402_pay_localized_data'][ SettingsPage::SCRIPT_HANDLE ]['x402PaySettings'] ?? null;
		$this->assertIsArray( $boot );
		$this->assertSame( SettingsRepository::OPTION_NAME, $boot['option'] );
		$this->assertSame( SettingsRepository::PAYWALL_MODE_CATEGORY, $boot['modeCategory'] );
		$this->assertArrayHasKey( 'managedWalletFacilitators', $boot );
		$this->assertSame( array(), $boot['managedWalletFacilitators'] );
	}

	public function test_enqueue_assets_skips_other_admin_pages(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->enqueue_assets( 'dashboard' );
		$this->assertSame( array(), $GLOBALS['__x402_pay_enqueued_scripts'] );
	}

	public function test_sanitize_callback_returns_nested_shape_without_persisting(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$args     = $GLOBALS['__x402_pay_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ];
		$callback = $args['sanitize_callback'];

		$this->assertSame( 'array', $args['type'] );
		$this->assertIsCallable( $callback );

		$result = $callback(
			array(
				'selected_facilitator_id'  => 'x402_pay_test',
				'facilitators'             => array(
					'x402_pay_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
				),
				'default_price'            => '0.5',
				'paywall_category_term_id' => 2,
			)
		);

		$this->assertSame( 'x402_pay_test', $result['selected_facilitator_id'] );
		$this->assertSame( '0x1111111111111111111111111111111111111111', $result['facilitators']['x402_pay_test']['wallet_address'] );
		$this->assertSame( '0.5', $result['default_price'] );
		$this->assertSame( 'none', $result['paywall_mode'] );
		$this->assertSame( 'bots', $result['paywall_audience'] );
		$this->assertSame( 2, $result['paywall_category_term_id'] );
		// Regression: the callback must be pure (no persistence) so WP's
		// update_option doesn't recurse during register_setting sanitization.
		$this->assertArrayNotHasKey( SettingsRepository::OPTION_NAME, $GLOBALS['__x402_pay_options'] );
	}

	public function test_sanitize_callback_falls_back_to_default_for_bad_price(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$callback = $GLOBALS['__x402_pay_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ]['sanitize_callback'];
		$result   = $callback( array( 'default_price' => 'nope' ) );

		$this->assertSame( '0.01', $result['default_price'] );
	}

	public function test_render_emits_react_mount_point(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="x402-pay-app"', $html );
		// Per-card AJAX saves, so no classic options.php form.
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_bootstrap_data_exposes_values_categories_and_facilitators(): void {
		$GLOBALS['__x402_pay_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id'  => 'x402_pay_test',
			'facilitators'             => array(
				'x402_pay_test' => array( 'wallet_address' => '0xabc' ),
			),
			'default_price'            => '0.05',
			'paywall_mode'             => 'category',
			'paywall_category_term_id' => 2,
		);
		$GLOBALS['__x402_pay_connectors']['x402_pay_test'] = array(
			'type'        => ConnectorRegistry::FACILITATOR_TYPE,
			'name'        => 'x402.org (Test network)',
			'description' => 'Testnet',
		);

		$boot = ( new SettingsPage( new SettingsRepository() ) )->bootstrap_data();

		$this->assertSame( 'x402_pay_test', $boot['values']['selected_facilitator_id'] );
		$this->assertSame(
			array(
				'wallet_address' => '',
				'api_key_id'     => '',
			),
			$boot['values']['facilitators']['x402_pay_test']
		);
		$this->assertSame( '0.05', $boot['values']['default_price'] );
		$this->assertSame( 2, $boot['values']['paywall_category_term_id'] );

		$this->assertSame(
			array(
				array( 'term_id' => 1, 'name' => 'x402paywall' ),
				array( 'term_id' => 2, 'name' => 'News' ),
			),
			$boot['categories']
		);

		$this->assertSame(
			array(
				array(
					'id'          => 'x402_pay_test',
					'name'        => 'x402.org (Test network)',
					'description' => 'Testnet',
				),
			),
			$boot['facilitators']
		);

		$this->assertSame( SettingsRepository::PAYWALL_MODE_NONE, $boot['modes']['paywall']['none'] );
		$this->assertSame( SettingsRepository::AUDIENCE_BOTS, $boot['modes']['audience']['bots'] );

		$this->assertArrayHasKey( 'paywallProbe', $boot );
		$this->assertSame( 'x402_pay_paywall_probe', $boot['paywallProbe']['action'] );
		$this->assertNotSame( '', $boot['paywallProbe']['nonce'] );
	}

	public function test_bootstrap_data_sanitizes_connector_admin_meta(): void {
		$GLOBALS['__x402_pay_connectors']['coinbase_cdp'] = array(
			'type'           => ConnectorRegistry::FACILITATOR_TYPE,
			'name'           => '<b>Coinbase</b>',
			'description'    => '<script>alert(1)</script>CDP',
			'authentication' => array( 'method' => 'api_key' ),
		);
		add_filter(
			'x402_pay_connector_admin_meta',
			static fn ( array $meta, string $id ): array => 'coinbase_cdp' === $id
				? array(
					'introHeadline'       => '<b>Connect</b>',
					'introBody'           => 'Read the <docs/> <script>alert(1)</script>',
					'docsLinkText'        => '<b>docs</b>',
					'docsUrl'             => 'javascript:alert(1)',
					'keyIdPattern'        => '^[a-z]+$',
					'keySecretPattern'    => '^[A-Za-z0-9]+$',
					'keySecretPlaceholder' => '<i>paste</i>',
				)
				: $meta,
			10,
			2
		);

		$boot = ( new SettingsPage( new SettingsRepository() ) )->bootstrap_data();

		$this->assertSame( 'Coinbase', $boot['facilitators'][0]['name'] );
		$this->assertSame( 'alert(1)CDP', $boot['facilitators'][0]['description'] );

		$meta = $boot['connectorAdminMeta']['coinbase_cdp'];
		$this->assertSame( 'Connect', $meta['introHeadline'] );
		$this->assertSame( 'Read the <docs/> alert(1)', $meta['introBody'] );
		$this->assertSame( 'docs', $meta['docsLinkText'] );
		$this->assertSame( '', $meta['docsUrl'] );
		$this->assertSame( '^[a-z]+$', $meta['keyIdPattern'] );
		$this->assertSame( 'paste', $meta['keySecretPlaceholder'] );
	}
}
