<?php
/**
 * Admin-facing notices for plugin settings changes.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Settings\SettingsRepository;

/**
 * Emits `add_settings_error` notices for settings events that benefit from an
 * explanation beyond the default "Settings saved."
 */
final class SettingsChangeNotifier {

	/**
	 * The stored paywall category was deleted outside the settings page. The
	 * guard has already reset the setting to the default so gating keeps
	 * working — this notice explains why.
	 */
	public function notify_paywall_category_deleted( string $name ): void {
		$this->emit(
			'x402press_category_deleted',
			'warning',
			sprintf(
				/* translators: %s: deleted paywall category name. */
				__( 'The paywall category "%s" was deleted. x402press has switched to the default paywall category so gating keeps working; update your paywall category in Settings → x402press if you want a different one.', 'x402press' ),
				$name
			)
		);
	}

	/**
	 * Mode was switched to `all-posts` — every published post is now gated.
	 */
	public function notify_mode_switched_to_all_posts(): void {
		$this->emit(
			'x402press_all_posts_mode',
			'info',
			__( 'Every published post is now paywalled.', 'x402press' )
		);
	}

	private function emit( string $code, string $type, string $message ): void {
		add_settings_error( SettingsRepository::OPTION_NAME, $code, $message, $type );
	}
}
