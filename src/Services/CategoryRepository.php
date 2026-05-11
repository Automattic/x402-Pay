<?php
/**
 * Taxonomy side-effects for the paywall category.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Settings\SettingsRepository;

/**
 * Manages the WordPress `category` term used by the paywall.
 */
final class CategoryRepository {

	/**
	 * Ensure a category term with the given name exists. No-op on empty input
	 * or when the term already exists.
	 */
	public function ensure( string $term ): void {
		$term = trim( $term );
		if ( '' === $term ) {
			return;
		}
		if ( ! term_exists( $term, 'category' ) ) {
			wp_insert_term( $term, 'category' );
		}
	}

	/**
	 * Resolve the default paywall term's id, creating it if missing. Always
	 * returns a positive id on success; returns 0 only if `wp_insert_term`
	 * itself fails (which in production indicates a broken taxonomy state).
	 */
	public function ensure_default_term_id(): int {
		$name     = SettingsRepository::DEFAULT_CATEGORY;
		$existing = term_exists( $name, 'category' );
		if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
			return (int) $existing['term_id'];
		}
		$result = wp_insert_term( $name, 'category' );
		return is_array( $result ) && isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;
	}
}
