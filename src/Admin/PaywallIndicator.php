<?php
/**
 * Admin Bar indicator for paywalled frontend views.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Admin;

use X402Press\Admin\SettingsPage;
use X402Press\Services\DefaultPaywallRule;
use X402Press\Services\RuleResolver;
use X402Press\Settings\SettingsRepository;

/**
 * Adds a top admin-bar node when an admin is viewing a singular post in the
 * configured paywall scope (mode: all published posts, or paywall category).
 *
 * The resolve context sets {@see DefaultPaywallRule::CTX_KEY_ADMIN_BAR_SCOPE}
 * so the admin bar can show in-scope posts even when audience is "only bots"
 * (human guests are not 402 in that case, but the editor still needs to see
 * which posts the plugin targets). {@see \X402Press\Http\PaywallController}
 * does not set that key; real 402s are unchanged. The controller still applies
 * the `x402press_bypass_paywall` filter for the actual request.
 */
final class PaywallIndicator {

	public const NODE_ID = 'x402press-paywalled';

	public function __construct(
		private readonly RuleResolver $rules,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
	}

	/**
	 * Admin-bar callback. Adds the indicator node when the current frontend
	 * view would trigger a paywall.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 */
	public function add_node( $bar ): void {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$post_id = is_singular() ? (int) get_queried_object_id() : 0;
		if ( $post_id <= 0 ) {
			return;
		}
		$path = (string) ( wp_parse_url(
			home_url( add_query_arg( array() ) ),
			PHP_URL_PATH
		) ?? '/' );

		$rule = $this->rules->resolve(
			array(
				'path'     => $path,
				'method'   => 'GET',
				'post_id'  => $post_id,
				'singular' => true,
				DefaultPaywallRule::CTX_KEY_ADMIN_BAR_SCOPE => true,
			)
		);
		if ( null === $rule ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => $this->node_title( $rule ),
				'href'  => admin_url( 'options-general.php?page=' . SettingsPage::MENU_SLUG ),
				'meta'  => array(
					'title' => esc_attr__(
						'This post is in your paywall scope. Open x402 Pay settings for audience, mode, and price.',
						'x402-pay'
					),
				),
			)
		);
	}

	/**
	 * @param array{price:string,ttl:int,description:string} $rule Rule normalised by {@see RuleResolver::resolve()}.
	 */
	private function node_title( array $rule ): string {
		$price = (string) $rule['price'];
		if ( SettingsRepository::AUDIENCE_BOTS === $this->settings->paywall_audience() ) {
			return esc_html(
				sprintf(
					/* translators: %s: decimal USDC amount (e.g. 0.01). */
					__( 'Paywalled (bots only, $%s)', 'x402-pay' ),
					$price
				)
			);
		}
		return esc_html(
			sprintf(
				/* translators: %s: decimal USDC amount (e.g. 0.01). */
				__( 'Paywalled (everyone, $%s)', 'x402-pay' ),
				$price
			)
		);
	}
}
