<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Http\PaywallController;
use SimpleX402\Services\FacilitatorHooks;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']            = array();
		$GLOBALS['__sx402_existing_terms']     = array();
		$GLOBALS['__sx402_filters']            = array();
		$GLOBALS['__sx402_settings_errors']    = array();
		$GLOBALS['__sx402_get_posts_return']   = null;
		$GLOBALS['__sx402_current_user_id']   = 0;
	}

	public function test_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( '', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
		$this->assertSame( '', $repo->selected_facilitator_id() );
		$this->assertSame( 0, $repo->paywall_category_term_id() );
		$this->assertSame( SettingsRepository::DEFAULT_PAYWALL_MODE, $repo->paywall_mode() );
		$this->assertSame( SettingsRepository::DEFAULT_AUDIENCE, $repo->paywall_audience() );
		$this->assertSame( array(), $repo->facilitator_slots() );
	}

	public function test_wallet_address_resolves_to_the_active_facilitators_slot(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id'  => 'simple_x402_test',
				'facilitators'             => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive' ),
				),
				'default_price'            => '0.25',
				'paywall_category_term_id' => 7,
			)
		);
		$this->assertSame( '0xTest', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
	}

	public function test_switching_selected_facilitator_recalls_its_wallet_slot(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive' ),
				),
			)
		);
		$this->assertSame( '0xTest', $repo->wallet_address() );

		$repo->save(
			array(
				'selected_facilitator_id' => 'coinbase_cdp',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive' ),
				),
			)
		);
		$this->assertSame( '0xLive', $repo->wallet_address() );
	}

	public function test_wallet_address_for_reads_arbitrary_slot_regardless_of_selection(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive' ),
				),
			)
		);
		$this->assertSame( '0xLive', $repo->wallet_address_for( 'coinbase_cdp' ) );
	}

	public function test_sanitize_reverts_negative_or_non_numeric_price_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'default_price' => '-1' ) );
		$this->assertSame( '0.01', $repo->default_price() );

		$repo->save( array( 'default_price' => 'free' ) );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_sanitize_strips_invalid_chars_from_facilitator_id(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'selected_facilitator_id' => 'Simple/X402 Test!' ) );
		$this->assertSame( 'simplex402test', $repo->selected_facilitator_id() );
	}

	public function test_sanitize_drops_invalid_facilitator_keys_in_slots(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'facilitators' => array(
					'valid_id'     => array( 'wallet_address' => '0xA' ),
					'Bad ID!'      => array( 'wallet_address' => '0xB' ),
					'also/invalid' => array( 'wallet_address' => '0xC' ),
				),
			)
		);
		$slots = $repo->facilitator_slots();
		$this->assertArrayHasKey( 'valid_id', $slots );
		$this->assertArrayHasKey( 'badid', $slots );       // "Bad ID!" → "badid"
		$this->assertArrayHasKey( 'alsoinvalid', $slots ); // "also/invalid" → "alsoinvalid"
	}

	public function test_sanitize_rejects_invalid_audience_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_audience' => 'nobody' ) );
		$this->assertSame( SettingsRepository::DEFAULT_AUDIENCE, $repo->paywall_audience() );
	}

	public function test_sanitize_rejects_invalid_paywall_mode_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_mode' => 'weird' ) );
		$this->assertSame( SettingsRepository::DEFAULT_PAYWALL_MODE, $repo->paywall_mode() );
	}

	public function test_sanitize_keeps_valid_term_id(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 42, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_category_term_id' => 42 ) );
		$this->assertSame( 42, $repo->paywall_category_term_id() );
	}

	public function test_sanitize_falls_back_when_term_id_points_at_nothing(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 7,
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_category_term_id' => 9999 ) );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_update_only_touches_keys_present_in_the_partial(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'default_price'            => '0.05',
			'selected_facilitator_id'  => 'simple_x402_test',
			'facilitators'             => array(
				'simple_x402_test' => array( 'wallet_address' => '0xTest' ),
				'coinbase_cdp'     => array( 'wallet_address' => '0xLive' ),
			),
			'paywall_mode'             => 'category',
			'paywall_audience'         => 'bots',
			'paywall_category_term_id' => 3,
		);

		$merged = ( new SettingsRepository() )->update( array( 'default_price' => '1.5' ) );

		$this->assertSame( '1.5', $merged['default_price'] );
		// Everything else unchanged.
		$this->assertSame( 'simple_x402_test', $merged['selected_facilitator_id'] );
		$this->assertSame( '0xTest', $merged['facilitators']['simple_x402_test']['wallet_address'] );
		$this->assertSame( '0xLive', $merged['facilitators']['coinbase_cdp']['wallet_address'] );
		$this->assertSame( 'category', $merged['paywall_mode'] );
		$this->assertSame( 'bots', $merged['paywall_audience'] );
		$this->assertSame( 3, $merged['paywall_category_term_id'] );
	}

	public function test_update_resanitises_existing_slots_so_historical_junk_is_dropped(): void {
		// Simulate a stored option that picked up extra keys from a past
		// schema or a bad external write. After update(), the merged row
		// should only contain the sanitised shape.
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'facilitators' => array(
				'simple_x402_test' => array(
					'wallet_address'       => '0xOld',
					'default_price'        => '0.01',       // retired field
					'legacy_facilitator_url' => 'https://' , // unknown junk
				),
			),
		);

		$merged = ( new SettingsRepository() )->update(
			array(
				'facilitators' => array(
					'coinbase_cdp' => array( 'wallet_address' => '0xNew' ),
				),
			)
		);

		// Existing slot preserved, but only with the canonical keys.
		$this->assertSame(
			array(
				'wallet_address' => '0xOld',
				'api_key_id'     => '',
			),
			$merged['facilitators']['simple_x402_test']
		);
		// New slot also normalised.
		$this->assertSame(
			array(
				'wallet_address' => '0xNew',
				'api_key_id'     => '',
			),
			$merged['facilitators']['coinbase_cdp']
		);
	}

	public function test_update_merges_facilitator_slots_by_id(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'facilitators' => array(
				'simple_x402_test' => array( 'wallet_address' => '0xOld' ),
				'coinbase_cdp'     => array( 'wallet_address' => '0xLive' ),
			),
		);

		$merged = ( new SettingsRepository() )->update(
			array(
				'facilitators' => array(
					'simple_x402_test' => array( 'wallet_address' => '0xNew' ),
				),
			)
		);

		// simple_x402_test overwritten, coinbase_cdp preserved.
		$this->assertSame( '0xNew', $merged['facilitators']['simple_x402_test']['wallet_address'] );
		$this->assertSame( '0xLive', $merged['facilitators']['coinbase_cdp']['wallet_address'] );
	}

	public function test_update_leaves_invalid_term_id_alone_instead_of_clobbering(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 5, 'name' => 'Valid', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 5,
		);

		$merged = ( new SettingsRepository() )->update( array( 'paywall_category_term_id' => 9999 ) );
		$this->assertSame( 5, $merged['paywall_category_term_id'] );
	}

	public function test_set_paywall_category_term_id_preserves_other_fields(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id'  => 'simple_x402_test',
			'facilitators'             => array(
				'simple_x402_test' => array( 'wallet_address' => '0xabc' ),
			),
			'default_price'            => '0.50',
			'paywall_category_term_id' => 3,
		);
		$repo = new SettingsRepository();
		$repo->set_paywall_category_term_id( 99 );
		$this->assertSame( 99, $repo->paywall_category_term_id() );
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.50', $repo->default_price() );
		$this->assertSame( 'simple_x402_test', $repo->selected_facilitator_id() );
	}

	public function test_sample_paywalled_post_permalink_returns_null_for_mode_none(): void {
		$repo = new SettingsRepository();
		$this->assertNull(
			$repo->sample_paywalled_post_permalink(
				array( 'paywall_mode' => SettingsRepository::PAYWALL_MODE_NONE )
			)
		);
	}

	public function test_sample_paywalled_post_permalink_returns_null_when_no_posts(): void {
		$GLOBALS['__sx402_get_posts_return'] = array();
		$repo                                = new SettingsRepository();
		$this->assertNull(
			$repo->sample_paywalled_post_permalink(
				array(
					'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
					'paywall_category_term_id' => 5,
				)
			)
		);
	}

	public function test_sample_paywalled_post_permalink_uses_first_matching_post_id(): void {
		$GLOBALS['__sx402_get_posts_return'] = array( 42 );
		$repo                                = new SettingsRepository();
		$this->assertSame(
			'https://example.test/p/42/',
			$repo->sample_paywalled_post_permalink(
				array(
					'paywall_mode'             => SettingsRepository::PAYWALL_MODE_ALL_POSTS,
					'paywall_category_term_id' => 1,
				)
			)
		);
	}

	public function test_build_paywall_probe_for_merged_row_none(): void {
		$repo = new SettingsRepository();
		$this->assertSame(
			array( 'probe' => null ),
			$repo->build_paywall_probe_for_merged_row(
				array( 'paywall_mode' => SettingsRepository::PAYWALL_MODE_NONE )
			)
		);
	}

	public function test_build_paywall_probe_for_merged_row_includes_nonce_and_url(): void {
		$GLOBALS['__sx402_get_posts_return'] = array( 9 );
		$GLOBALS['__sx402_current_user_id']  = 1;
		$repo                                = new SettingsRepository();
		$out                                 = $repo->build_paywall_probe_for_merged_row(
			array(
				'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
				'paywall_category_term_id' => 3,
			)
		);
		$this->assertSame( 'https://example.test/p/9/', $out['probe']['url'] );
		$this->assertSame(
			wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
			$out['probe']['nonce']
		);
	}

	public function test_build_paywall_probe_for_merged_row_no_matching_post(): void {
		$GLOBALS['__sx402_get_posts_return'] = array();
		$repo                                = new SettingsRepository();
		$this->assertSame(
			array( 'probe' => array( 'reason' => 'no_matching_post' ) ),
			$repo->build_paywall_probe_for_merged_row(
				array(
					'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
					'paywall_category_term_id' => 3,
				)
			)
		);
	}

	public function test_facilitators_map_is_capped_at_max_slot_count(): void {
		$repo  = new SettingsRepository();
		$slots = array();
		for ( $i = 0; $i < SettingsRepository::MAX_FACILITATOR_SLOTS + 5; $i++ ) {
			$slots[ 'facilitator_' . $i ] = array( 'wallet_address' => '0xabc' );
		}

		$merged = $repo->update( array( 'facilitators' => $slots ) );

		$this->assertCount(
			SettingsRepository::MAX_FACILITATOR_SLOTS,
			$merged['facilitators']
		);
	}

	public function test_slot_field_lengths_are_truncated(): void {
		$repo      = new SettingsRepository();
		$long_blob = str_repeat( 'x', SettingsRepository::MAX_SLOT_FIELD_BYTES + 100 );

		$merged = $repo->update(
			array(
				'facilitators' => array(
					'simple_x402_test' => array(
						'wallet_address' => $long_blob,
						'api_key_id'     => $long_blob,
					),
				),
			)
		);

		$slot = $merged['facilitators']['simple_x402_test'];
		$this->assertSame( SettingsRepository::MAX_SLOT_FIELD_BYTES, strlen( $slot['wallet_address'] ) );
		$this->assertSame( SettingsRepository::MAX_SLOT_FIELD_BYTES, strlen( $slot['api_key_id'] ) );
	}

	public function test_resolved_pay_to_prefers_managed_pool_from_filter(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xFromSlot' ),
				),
			)
		);
		add_filter(
			FacilitatorHooks::MANAGED_POOL_PAY_TO,
			static fn ( string $p, string $id ): string => 'simple_x402_test' === $id ? '0xManagedPool' : $p,
			10,
			2
		);
		$this->assertSame( '0xManagedPool', $repo->resolved_pay_to_address() );
	}
}
