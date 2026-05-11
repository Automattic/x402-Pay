<?php
/**
 * Default rule: paywall posts based on the configured audience and mode.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Settings\SettingsRepository;

/**
 * Callback for the `x402press_rule_for_request` filter at priority 10.
 *
 * Respects an earlier filter's answer if one is already set; otherwise returns
 * a paywall rule based on two settings:
 *
 *  - `paywall_mode`     — which posts qualify (`none`, `category`, `all-posts`).
 *                         `none` disables gating entirely.
 *  - `paywall_audience` — who the paywall targets (`everyone`, `bots`).
 *                         `bots` requires the request's User-Agent to match a
 *                         known crawler, unless `paywall_probe` is set in
 *                         context (valid settings self-check — same trust as
 *                         the probe nonce header on the controller) or
 *                         {@see self::CTX_KEY_ADMIN_BAR_SCOPE} is set (admin bar
 *                         only: "is this post in the configured paywall mode?").
 *
 * Mode is checked first so the disabled state short-circuits before any
 * audience or post lookup.
 */
final class DefaultPaywallRule {

	/**
	 * Request context key: when set (truthy), `bots` audience does not
	 * short-circuit humans — used by the admin bar so editors can see
	 * in-scope posts while audience remains "only bots" for real requests.
	 */
	public const CTX_KEY_ADMIN_BAR_SCOPE = 'admin_bar_scope';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly BotDetector $bots,
	) {}

	/**
	 * @param array|null $rule Rule returned by a higher-priority filter, if any.
	 * @param array      $ctx  Request context including `post_id`.
	 */
	public function __invoke( $rule, array $ctx ): ?array {
		if ( is_array( $rule ) ) {
			return $rule;
		}
		if ( SettingsRepository::PAYWALL_MODE_NONE === $this->settings->paywall_mode() ) {
			return null;
		}
		if ( SettingsRepository::AUDIENCE_BOTS === $this->settings->paywall_audience()
			&& empty( $ctx['paywall_probe'] )
			&& ! $this->bots->is_bot()
			&& empty( $ctx[ self::CTX_KEY_ADMIN_BAR_SCOPE ] )
		) {
			return null;
		}
		$post_id = (int) ( $ctx['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return null;
		}
		if ( ! $this->matches( $post_id ) ) {
			return null;
		}
		return array(
			'price' => $this->settings->default_price(),
			'ttl'   => RuleResolver::DEFAULT_TTL,
		);
	}

	/**
	 * Does the selected mode say this post should be gated?
	 */
	private function matches( int $post_id ): bool {
		return match ( $this->settings->paywall_mode() ) {
			SettingsRepository::PAYWALL_MODE_ALL_POSTS => 'post' === get_post_type( $post_id )
				&& 'publish' === get_post_status( $post_id ),
			default => has_term( $this->settings->paywall_category_term_id(), 'category', $post_id ),
		};
	}
}
