<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Admin\PaywallIndicator;
use X402Press\Admin\SettingsPage;
use X402Press\Services\RuleResolver;
use X402Press\Settings\SettingsRepository;
use WP_Admin_Bar;

final class PaywallIndicatorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402press_is_admin']           = false;
		$GLOBALS['__x402press_is_singular']        = true;
		$GLOBALS['__x402press_queried_object_id']  = 42;
		$GLOBALS['__x402press_request_uri']        = '/post-slug/';
		$GLOBALS['__x402press_current_user_caps']  = array( 'manage_options' );
		$GLOBALS['__x402press_filters']            = array();
		$GLOBALS['__x402press_options']           = array();
	}

	private function register_rule( ?array $rule ): void {
		$GLOBALS['__x402press_filters'][ RuleResolver::HOOK ] = array(
			static fn ( $existing, array $ctx ) => $rule,
		);
	}

	private function indicator(): PaywallIndicator {
		return new PaywallIndicator( new RuleResolver(), new SettingsRepository() );
	}

	public function test_adds_node_when_rule_resolves(): void {
		$this->register_rule( array( 'price' => '0.25', 'ttl' => 86400 ) );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertCount( 1, $bar->nodes );
		$this->assertSame( PaywallIndicator::NODE_ID, $bar->nodes[0]['id'] );
		$this->assertSame( 'Paywalled (bots only, $0.25)', $bar->nodes[0]['title'] );
	}

	public function test_adds_node_title_uses_everyone_label_when_set(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_audience' => SettingsRepository::AUDIENCE_EVERYONE,
		);
		$this->register_rule( array( 'price' => '0.01', 'ttl' => 86400 ) );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertCount( 1, $bar->nodes );
		$this->assertSame( 'Paywalled (everyone, $0.01)', $bar->nodes[0]['title'] );
	}

	public function test_node_links_to_settings_page(): void {
		$this->register_rule( array( 'price' => '0.25', 'ttl' => 86400 ) );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertStringContainsString(
			'options-general.php?page=' . SettingsPage::MENU_SLUG,
			$bar->nodes[0]['href']
		);
	}

	public function test_skips_when_rule_is_null(): void {
		$this->register_rule( null );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertSame( array(), $bar->nodes );
	}

	public function test_skips_on_wp_admin_screens(): void {
		$GLOBALS['__x402press_is_admin'] = true;
		$this->register_rule( array( 'price' => '0.25', 'ttl' => 86400 ) );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertSame( array(), $bar->nodes );
	}

	public function test_skips_when_user_lacks_manage_options(): void {
		$GLOBALS['__x402press_current_user_caps'] = array();
		$this->register_rule( array( 'price' => '0.25', 'ttl' => 86400 ) );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertSame( array(), $bar->nodes );
	}

	public function test_skips_when_not_singular(): void {
		$GLOBALS['__x402press_is_singular'] = false;
		$this->register_rule( array( 'price' => '0.25', 'ttl' => 86400 ) );
		$bar = new WP_Admin_Bar();

		$this->indicator()->add_node( $bar );

		$this->assertSame( array(), $bar->nodes );
	}

	public function test_passes_current_post_and_path_to_resolver(): void {
		$captured = null;
		$GLOBALS['__x402press_filters'][ RuleResolver::HOOK ] = array(
			static function ( $existing, array $ctx ) use ( &$captured ) {
				$captured = $ctx;
				return array( 'price' => '0.25', 'ttl' => 86400 );
			},
		);
		$GLOBALS['__x402press_queried_object_id'] = 99;
		$GLOBALS['__x402press_request_uri']       = '/deep/slug/';

		$this->indicator()->add_node( new WP_Admin_Bar() );

		$this->assertSame( 99, $captured['post_id'] );
		$this->assertSame( '/deep/slug/', $captured['path'] );
		$this->assertTrue( $captured['singular'] );
	}
}
