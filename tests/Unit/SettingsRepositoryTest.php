<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Http\PaywallController;
use X402Press\Services\FacilitatorHooks;
use X402Press\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402press_options']            = array();
		$GLOBALS['__x402press_existing_terms']     = array();
		$GLOBALS['__x402press_filters']            = array();
		$GLOBALS['__x402press_settings_errors']    = array();
		$GLOBALS['__x402press_get_posts_return']   = null;
		$GLOBALS['__x402press_current_user_id']   = 0;
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

	public function test_getters_resanitise_direct_option_writes(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'default_price'            => '<script>alert(1)</script>',
			'selected_facilitator_id'  => 'Bad/Connector<script>',
			'facilitators'             => array(
				'Bad Slot!' => array(
					'wallet_address' => 'javascript:alert(1)',
					'api_key_id'     => str_repeat( 'x', SettingsRepository::MAX_SLOT_FIELD_BYTES + 5 ),
				),
			),
			'paywall_mode'             => '<b>all-posts</b>',
			'paywall_audience'         => 'nobody',
			'paywall_category_term_id' => -99,
		);

		$repo = new SettingsRepository();

		$this->assertSame( SettingsRepository::DEFAULT_PRICE, $repo->default_price() );
		$this->assertSame( 'badconnectorscript', $repo->selected_facilitator_id() );
		$this->assertSame( SettingsRepository::DEFAULT_PAYWALL_MODE, $repo->paywall_mode() );
		$this->assertSame( SettingsRepository::DEFAULT_AUDIENCE, $repo->paywall_audience() );
		$this->assertSame( 0, $repo->paywall_category_term_id() );

		$slots = $repo->facilitator_slots();
		$this->assertArrayHasKey( 'badslot', $slots );
		$this->assertSame( '', $slots['badslot']['wallet_address'] );
		$this->assertSame( SettingsRepository::MAX_SLOT_FIELD_BYTES, strlen( $slots['badslot']['api_key_id'] ) );
	}

	public function test_wallet_address_resolves_to_the_active_facilitators_slot(): void {
		$GLOBALS['__x402press_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id'  => 'x402press_test',
				'facilitators'             => array(
					'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0x2222222222222222222222222222222222222222' ),
				),
				'default_price'            => '0.25',
				'paywall_category_term_id' => 7,
			)
		);
		$this->assertSame( '0x1111111111111111111111111111111111111111', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
	}

	public function test_switching_selected_facilitator_recalls_its_wallet_slot(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'x402press_test',
				'facilitators'            => array(
					'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0x2222222222222222222222222222222222222222' ),
				),
			)
		);
		$this->assertSame( '0x1111111111111111111111111111111111111111', $repo->wallet_address() );

		$repo->save(
			array(
				'selected_facilitator_id' => 'coinbase_cdp',
				'facilitators'            => array(
					'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0x2222222222222222222222222222222222222222' ),
				),
			)
		);
		$this->assertSame( '0x2222222222222222222222222222222222222222', $repo->wallet_address() );
	}

	public function test_wallet_address_for_reads_arbitrary_slot_regardless_of_selection(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'x402press_test',
				'facilitators'            => array(
					'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0x2222222222222222222222222222222222222222' ),
				),
			)
		);
		$this->assertSame( '0x2222222222222222222222222222222222222222', $repo->wallet_address_for( 'coinbase_cdp' ) );
	}

	public function test_sanitize_reverts_negative_or_non_numeric_price_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'default_price' => '-1' ) );
		$this->assertSame( '0.01', $repo->default_price() );

		$repo->save( array( 'default_price' => 'free' ) );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_sanitize_rejects_scientific_notation_price_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'default_price' => '1e3' ) );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_sanitize_rejects_over_precise_price_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'default_price' => '0.1234567' ) );
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
		$GLOBALS['__x402press_existing_terms'] = array(
			array( 'term_id' => 42, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_category_term_id' => 42 ) );
		$this->assertSame( 42, $repo->paywall_category_term_id() );
	}

	public function test_sanitize_falls_back_when_term_id_points_at_nothing(): void {
		$GLOBALS['__x402press_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 7,
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_category_term_id' => 9999 ) );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_update_only_touches_keys_present_in_the_partial(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'default_price'            => '0.05',
			'selected_facilitator_id'  => 'x402press_test',
			'facilitators'             => array(
				'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
				'coinbase_cdp'     => array( 'wallet_address' => '0x2222222222222222222222222222222222222222' ),
			),
			'paywall_mode'             => 'category',
			'paywall_audience'         => 'bots',
			'paywall_category_term_id' => 3,
		);

		$merged = ( new SettingsRepository() )->update( array( 'default_price' => '1.5' ) );

		$this->assertSame( '1.5', $merged['default_price'] );
		// Everything else unchanged.
		$this->assertSame( 'x402press_test', $merged['selected_facilitator_id'] );
		$this->assertSame( '0x1111111111111111111111111111111111111111', $merged['facilitators']['x402press_test']['wallet_address'] );
		$this->assertSame( '0x2222222222222222222222222222222222222222', $merged['facilitators']['coinbase_cdp']['wallet_address'] );
		$this->assertSame( 'category', $merged['paywall_mode'] );
		$this->assertSame( 'bots', $merged['paywall_audience'] );
		$this->assertSame( 3, $merged['paywall_category_term_id'] );
	}

	public function test_update_resanitises_untouched_stored_fields_before_returning(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'default_price'           => 'free',
			'selected_facilitator_id' => 'Bad/Connector',
			'paywall_mode'            => 'weird',
			'paywall_audience'        => 'nobody',
		);

		$merged = ( new SettingsRepository() )->update( array( 'default_price' => '0.25' ) );

		$this->assertSame( '0.25', $merged['default_price'] );
		$this->assertSame( 'badconnector', $merged['selected_facilitator_id'] );
		$this->assertSame( SettingsRepository::DEFAULT_PAYWALL_MODE, $merged['paywall_mode'] );
		$this->assertSame( SettingsRepository::DEFAULT_AUDIENCE, $merged['paywall_audience'] );
	}

	public function test_update_resanitises_existing_slots_so_historical_junk_is_dropped(): void {
		// Simulate a stored option that picked up extra keys from a past
		// schema or a bad external write. After update(), the merged row
		// should only contain the sanitised shape.
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'facilitators' => array(
				'x402press_test' => array(
					'wallet_address'       => '0x3333333333333333333333333333333333333333',
					'default_price'        => '0.01',       // retired field
					'legacy_facilitator_url' => 'https://' , // unknown junk
				),
			),
		);

		$merged = ( new SettingsRepository() )->update(
			array(
				'facilitators' => array(
					'coinbase_cdp' => array( 'wallet_address' => '0x4444444444444444444444444444444444444444' ),
				),
			)
		);

		// Existing slot preserved, but only with the canonical keys.
		$this->assertSame(
			array(
				'wallet_address' => '0x3333333333333333333333333333333333333333',
				'api_key_id'     => '',
			),
			$merged['facilitators']['x402press_test']
		);
		// New slot also normalised.
		$this->assertSame(
			array(
				'wallet_address' => '0x4444444444444444444444444444444444444444',
				'api_key_id'     => '',
			),
			$merged['facilitators']['coinbase_cdp']
		);
	}

	public function test_update_merges_facilitator_slots_by_id(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'facilitators' => array(
				'x402press_test' => array( 'wallet_address' => '0x3333333333333333333333333333333333333333' ),
				'coinbase_cdp'     => array( 'wallet_address' => '0x2222222222222222222222222222222222222222' ),
			),
		);

		$merged = ( new SettingsRepository() )->update(
			array(
				'facilitators' => array(
					'x402press_test' => array( 'wallet_address' => '0x4444444444444444444444444444444444444444' ),
				),
			)
		);

		// x402press_test overwritten, coinbase_cdp preserved.
		$this->assertSame( '0x4444444444444444444444444444444444444444', $merged['facilitators']['x402press_test']['wallet_address'] );
		$this->assertSame( '0x2222222222222222222222222222222222222222', $merged['facilitators']['coinbase_cdp']['wallet_address'] );
	}

	public function test_update_leaves_invalid_term_id_alone_instead_of_clobbering(): void {
		$GLOBALS['__x402press_existing_terms'] = array(
			array( 'term_id' => 5, 'name' => 'Valid', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 5,
		);

		$merged = ( new SettingsRepository() )->update( array( 'paywall_category_term_id' => 9999 ) );
		$this->assertSame( 5, $merged['paywall_category_term_id'] );
	}

	public function test_set_paywall_category_term_id_preserves_other_fields(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id'  => 'x402press_test',
			'facilitators'             => array(
				'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
			),
			'default_price'            => '0.50',
			'paywall_category_term_id' => 3,
		);
		$repo = new SettingsRepository();
		$repo->set_paywall_category_term_id( 99 );
		$this->assertSame( 99, $repo->paywall_category_term_id() );
		$this->assertSame( '0x1111111111111111111111111111111111111111', $repo->wallet_address() );
		$this->assertSame( '0.50', $repo->default_price() );
		$this->assertSame( 'x402press_test', $repo->selected_facilitator_id() );
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
		$GLOBALS['__x402press_get_posts_return'] = array();
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
		$GLOBALS['__x402press_get_posts_return'] = array( 42 );
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
		$GLOBALS['__x402press_get_posts_return'] = array( 9 );
		$GLOBALS['__x402press_current_user_id']  = 1;
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
		$GLOBALS['__x402press_get_posts_return'] = array();
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
			$slots[ 'facilitator_' . $i ] = array( 'wallet_address' => '0x1111111111111111111111111111111111111111' );
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
					'x402press_test' => array(
						'wallet_address' => $long_blob,
						'api_key_id'     => $long_blob,
					),
				),
			)
		);

		$slot = $merged['facilitators']['x402press_test'];
		$this->assertSame( '', $slot['wallet_address'] );
		$this->assertSame( SettingsRepository::MAX_SLOT_FIELD_BYTES, strlen( $slot['api_key_id'] ) );
	}

	public function test_invalid_wallet_address_is_cleared_server_side(): void {
		$repo   = new SettingsRepository();
		$merged = $repo->update(
			array(
				'facilitators' => array(
					'x402press_test' => array( 'wallet_address' => '0xnot-a-wallet' ),
				),
			)
		);

		$this->assertSame( '', $merged['facilitators']['x402press_test']['wallet_address'] );
	}

	public function test_resolved_pay_to_prefers_managed_pool_from_filter(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'x402press_test',
				'facilitators'            => array(
					'x402press_test' => array( 'wallet_address' => '0x1111111111111111111111111111111111111111' ),
				),
			)
		);
		add_filter(
			FacilitatorHooks::MANAGED_POOL_PAY_TO,
			static fn ( string $p, string $id ): string => 'x402press_test' === $id ? '0x9999999999999999999999999999999999999999' : $p,
			10,
			2
		);
		$this->assertSame( '0x9999999999999999999999999999999999999999', $repo->resolved_pay_to_address() );
	}
}
