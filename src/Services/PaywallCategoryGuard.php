<?php
/**
 * Keeps the stored paywall category pointing at a live term.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Services;

use X402Pay\Settings\SettingsRepository;

/**
 * Reacts to WordPress's `delete_term` action: if the deleted term is the one
 * currently bound to `paywall_category_term_id`, ensure the default term
 * exists and rebind the setting to it. Hooking the *post*-deletion action
 * (rather than `pre_delete_term`) means the deleted row is already gone, so
 * `ensure()` can create a fresh replacement without colliding on slug when
 * the admin deletes the default term itself.
 */
final class PaywallCategoryGuard {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly CategoryRepository $categories,
		private readonly SettingsChangeNotifier $notifier,
	) {}

	/**
	 * Callback for the `delete_term` action.
	 *
	 * @param int    $term_id      Deleted term's ID.
	 * @param int    $tt_id        Deleted term taxonomy ID (unused).
	 * @param string $taxonomy     Taxonomy slug.
	 * @param mixed  $deleted_term The deleted term object (WP_Term in production).
	 */
	public function __invoke( int $term_id, int $tt_id, string $taxonomy, $deleted_term ): void {
		if ( 'category' !== $taxonomy ) {
			return;
		}
		if ( $term_id !== $this->settings->paywall_category_term_id() ) {
			return;
		}

		$default_id = $this->categories->ensure_default_term_id();
		$this->settings->set_paywall_category_term_id( $default_id );

		$name = is_object( $deleted_term ) && isset( $deleted_term->name )
			? (string) $deleted_term->name
			: SettingsRepository::DEFAULT_CATEGORY;
		$this->notifier->notify_paywall_category_deleted( $name );
	}
}
