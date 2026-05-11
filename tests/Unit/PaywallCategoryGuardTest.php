<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\CategoryRepository;
use X402Press\Services\PaywallCategoryGuard;
use X402Press\Services\SettingsChangeNotifier;
use X402Press\Settings\SettingsRepository;

final class PaywallCategoryGuardTest extends TestCase {

	private PaywallCategoryGuard $guard;

	protected function setUp(): void {
		$GLOBALS['__x402press_options']         = array();
		$GLOBALS['__x402press_existing_terms']  = array();
		$GLOBALS['__x402press_inserted_terms']  = array();
		$GLOBALS['__x402press_settings_errors'] = array();

		$this->guard = new PaywallCategoryGuard(
			new SettingsRepository(),
			new CategoryRepository(),
			new SettingsChangeNotifier()
		);
	}

	/**
	 * Simulate WordPress's `delete_term` action firing — the term is already
	 * gone from __x402press_existing_terms by the time the hook runs.
	 */
	private function fire_delete_term( int $term_id, string $name, string $taxonomy = 'category' ): void {
		$deleted = new \WP_Term( $term_id, $name, $taxonomy );
		( $this->guard )( $term_id, $term_id * 10, $taxonomy, $deleted );
	}

	public function test_resets_binding_to_default_when_bound_term_is_deleted(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.25',
			'paywall_mode'             => 'category',
			'paywall_category_term_id' => 55,
		);
		$this->fire_delete_term( 55, 'News' );

		$stored = $GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ];
		// Default term was freshly inserted; the guard bound to it.
		$this->assertCount( 1, $GLOBALS['__x402press_inserted_terms'] );
		$default_id = $GLOBALS['__x402press_inserted_terms'][0]['term_id'];
		$this->assertSame( $default_id, $stored['paywall_category_term_id'] );
		// Unrelated fields untouched.
		$this->assertSame( '0xabc', $stored['wallet_address'] );
		$this->assertSame( '0.25', $stored['default_price'] );
		$this->assertSame( 'category', $stored['paywall_mode'] );
	}

	public function test_ensures_default_term_exists_after_reset(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 55,
		);
		$this->fire_delete_term( 55, 'News' );

		$this->assertCount( 1, $GLOBALS['__x402press_inserted_terms'] );
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$GLOBALS['__x402press_inserted_terms'][0]['name']
		);
	}

	public function test_rebinds_to_preexisting_default_when_it_survives(): void {
		// Default term already exists; bound term (different id) is being deleted.
		// Guard must reuse the existing default_id, not insert a duplicate.
		$GLOBALS['__x402press_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => SettingsRepository::DEFAULT_CATEGORY, 'taxonomy' => 'category' ),
		);
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 55,
		);
		$this->fire_delete_term( 55, 'News' );

		$stored = $GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ];
		$this->assertSame( 1, $stored['paywall_category_term_id'] );
		$this->assertSame( array(), $GLOBALS['__x402press_inserted_terms'] );
	}

	public function test_recreates_default_term_when_default_itself_is_deleted(): void {
		// Bound to id=1 (the default term), that term is the one being deleted.
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 1,
		);
		$this->fire_delete_term( 1, SettingsRepository::DEFAULT_CATEGORY );

		$this->assertCount( 1, $GLOBALS['__x402press_inserted_terms'] );
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$GLOBALS['__x402press_inserted_terms'][0]['name']
		);
		$new_default_id = $GLOBALS['__x402press_inserted_terms'][0]['term_id'];
		$this->assertSame(
			$new_default_id,
			$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ]['paywall_category_term_id']
		);
	}

	public function test_emits_warning_notice_with_deleted_term_name(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 55,
		);
		$this->fire_delete_term( 55, 'News' );

		$codes = array_column( $GLOBALS['__x402press_settings_errors'], 'code' );
		$this->assertContains( 'x402press_category_deleted', $codes );
		$messages = array_column( $GLOBALS['__x402press_settings_errors'], 'message' );
		$this->assertTrue(
			(bool) array_filter( $messages, fn( $m ) => str_contains( (string) $m, 'News' ) ),
			'Notice must name the deleted category so the admin knows what happened.'
		);
	}

	public function test_ignores_deletion_of_unrelated_term(): void {
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 55,
		);
		$this->fire_delete_term( 77, 'Sports' );

		$stored = $GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ];
		$this->assertSame( 55, $stored['paywall_category_term_id'] );
		$this->assertSame( array(), $GLOBALS['__x402press_inserted_terms'] );
		$this->assertSame( array(), $GLOBALS['__x402press_settings_errors'] );
	}

	public function test_ignores_non_category_taxonomies(): void {
		// Someone deletes a post_tag with the same term_id — wrong taxonomy,
		// shouldn't touch the paywall binding.
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 55,
		);
		$this->fire_delete_term( 55, 'anything', 'post_tag' );

		$this->assertSame( array(), $GLOBALS['__x402press_inserted_terms'] );
		$this->assertSame( array(), $GLOBALS['__x402press_settings_errors'] );
	}

	public function test_tolerates_non_object_deleted_term_arg(): void {
		// Defensive: WP passes WP_Term but some stacks wire hooks with wrong
		// accepted_args. Guard should still heal the binding; notice falls back
		// to the default name.
		$GLOBALS['__x402press_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 55,
		);
		( $this->guard )( 55, 550, 'category', null );

		$this->assertCount( 1, $GLOBALS['__x402press_inserted_terms'] );
	}
}
