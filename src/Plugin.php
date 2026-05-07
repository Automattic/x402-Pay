<?php
/**
 * Plugin bootstrap and hook wiring.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402;

use SimpleX402\Admin\PaywallIndicator;
use SimpleX402\Admin\PaywallProbeAjax;
use SimpleX402\Admin\SettingsAjax;
use SimpleX402\Admin\SettingsPage;
use SimpleX402\Admin\TestConnectionAjax;
use SimpleX402\Connectors\CoinbaseConnectorRegistrar;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Connectors\TestConnectorRegistrar;
use SimpleX402\Facilitator\FacilitatorResolver;
use SimpleX402\Http\PaywallController;
use SimpleX402\Payment\Providers\GravatarWallet;
use SimpleX402\Services\AllPostsModeNoticeEmitter;
use SimpleX402\Services\BotDetector;
use SimpleX402\Services\CategoryRepository;
use SimpleX402\Services\DefaultPaywallRule;
use SimpleX402\Services\GrantStore;
use SimpleX402\Services\PaywallCategoryGuard;
use SimpleX402\Services\RuleResolver;
use SimpleX402\Services\SettingsChangeNotifier;
use SimpleX402\Settings\SettingsRepository;

/**
 * Wires services to WordPress hooks.
 *
 * This is the only class that touches WordPress globals or side-effectful
 * functions like add_action / exit. Everything else is a testable,
 * dependency-injected plain PHP class.
 */
final class Plugin {

	/**
	 * One-shot: if nothing is selected yet, pick the best default connector so
	 * new installs work without an extra settings save.
	 */
	private const FACILITATOR_AUTOPICKED_OPTION = 'simple_x402_facilitator_autopicked';

	/**
	 * Bootstrap the plugin. Idempotent — safe to call at most once per request.
	 */
	public static function boot(): void {
		$notifier     = new SettingsChangeNotifier();
		$settings     = new SettingsRepository();
		$rules        = new RuleResolver();
		$connectors   = new ConnectorRegistry();
		$resolver     = new FacilitatorResolver( $connectors );
		$controller   = new PaywallController(
			$rules,
			new GrantStore(),
			$settings,
			$resolver,
		);
		$bots         = new BotDetector( self::current_user_agent() );
		$default_rule = new DefaultPaywallRule( $settings, $bots );
		$categories   = new CategoryRepository();
		$guard        = new PaywallCategoryGuard( $settings, $categories, $notifier );
		$mode_note    = new AllPostsModeNoticeEmitter( $notifier );
		$indicator    = new PaywallIndicator( $rules, $settings );

		add_filter( RuleResolver::HOOK, $default_rule, 10, 2 );

		GravatarWallet::register();

		$indicator->register();

		add_action(
			'update_option_' . SettingsRepository::OPTION_NAME,
			$mode_note,
			10,
			2
		);

		// Heal the setting when the stored paywall category is deleted from
		// outside the plugin (e.g. via the Categories admin screen). Without
		// this, the paywall silently disables itself.
		add_action( 'delete_term', $guard, 10, 4 );

		$test_connector = new TestConnectorRegistrar();
		add_action( 'wp_connectors_init', $test_connector );
		add_filter(
			'simple_x402_facilitator_for_connector',
			array( $test_connector, 'provide_facilitator' ),
			10,
			2
		);

		$coinbase_connector = new CoinbaseConnectorRegistrar();
		add_action( 'wp_connectors_init', $coinbase_connector );
		add_filter(
			'simple_x402_facilitator_for_connector',
			array( $coinbase_connector, 'provide_facilitator' ),
			10,
			2
		);
		// After all `wp_connectors_init` callbacks (ours registers at default 10),
		// so ConnectorRegistry sees the built-in test connector before we read it.
		add_action(
			'wp_connectors_init',
			static function (): void {
				self::maybe_autopick_facilitator( new SettingsRepository() );
			},
			999
		);

		if ( is_admin() ) {
			( new SettingsPage( $settings, $connectors ) )->register();
			( new TestConnectionAjax( $resolver ) )->register();
			( new SettingsAjax( $settings ) )->register();
			( new PaywallProbeAjax( $settings ) )->register();
		}

		add_action(
			'template_redirect',
			static function () use ( $controller ): void {
				$post_id = is_singular() ? (int) get_queried_object_id() : 0;
				$path    = (string) ( wp_parse_url(
					home_url( add_query_arg( array() ) ),
					PHP_URL_PATH
				) ?? '/' );
				$method  = isset( $_SERVER['REQUEST_METHOD'] )
					? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
					: 'GET';

				$controller->handle(
					array(
						'path'     => $path,
						'method'   => $method,
						'post_id'  => $post_id,
						'singular' => is_singular(),
						'headers'  => self::collect_headers(),
					)
				);

				// Success-path headers (e.g. X-Payment-Grant + Set-Cookie after
				// a paid request) are flushed even when the controller hands
				// off to WordPress to render the page normally.
				foreach ( $GLOBALS['__sx402_response']['success_headers'] ?? array() as $line ) {
					header( (string) $line, false );
				}

				if ( ! empty( $GLOBALS['__sx402_response']['exited'] ) ) {
					foreach ( $GLOBALS['__sx402_response']['headers'] as $name => $value ) {
						header( $name . ': ' . $value );
					}
					echo (string) $GLOBALS['__sx402_response']['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body generated by wp_json_encode.
					exit;
				}
			}
		);
	}

	/**
	 * Activation hook: ensure the default paywall category exists and that
	 * the stored setting binds to it (idempotent — preserves any existing
	 * admin-chosen binding across reactivations).
	 */
	public static function activate(): void {
		$categories = new CategoryRepository();
		$default_id = $categories->ensure_default_term_id();

		$settings = new SettingsRepository();
		if ( $settings->paywall_category_term_id() <= 0 ) {
			$settings->set_paywall_category_term_id( $default_id );
		}
	}

	/**
	 * Persist a default facilitator once when the row is still empty.
	 */
	private static function maybe_autopick_facilitator( SettingsRepository $settings ): void {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return;
		}
		if ( get_option( self::FACILITATOR_AUTOPICKED_OPTION, false ) ) {
			return;
		}
		if ( '' !== $settings->selected_facilitator_id() ) {
			update_option( self::FACILITATOR_AUTOPICKED_OPTION, '1', false );
			return;
		}
		$connectors = new ConnectorRegistry();
		$preferred  = self::preferred_autopick_connector_id( $connectors );
		if ( '' === $preferred ) {
			return;
		}
		$settings->update( array( 'selected_facilitator_id' => $preferred ) );
		update_option( self::FACILITATOR_AUTOPICKED_OPTION, '1', false );
	}

	/**
	 * Use the built-in test facilitator when registered.
	 */
	private static function preferred_autopick_connector_id( ConnectorRegistry $connectors ): string {
		$map = $connectors->facilitators();
		if ( array_key_exists( TestConnectorRegistrar::ID, $map ) ) {
			return TestConnectorRegistrar::ID;
		}
		return '';
	}

	/**
	 * HTTP User-Agent for this request, unslashed (WordPress convention).
	 */
	private static function current_user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] )
			: '';
	}

	/**
	 * Collect inbound HTTP headers from $_SERVER.
	 *
	 * Always includes `Accept`, `Sec-Fetch-Mode`, and `Sec-Fetch-Dest` (empty
	 * string when the client did not send them) so paywall code can read a
	 * stable shape without `isset` guards.
	 *
	 * @return array<string,string>
	 */
	private static function collect_headers(): array {
		$out = array();
		foreach ( $_SERVER as $key => $value ) {
			if ( str_starts_with( (string) $key, 'HTTP_' ) ) {
				// $_SERVER delivers HTTP_X_WALLET_ADDRESS — upper, underscored.
				// Convert to canonical HTTP title-case (X-Wallet-Address) so
				// the controller's mixed-case lookups match real traffic, not
				// just what tests construct by hand.
				$raw_name     = str_replace( '_', '-', substr( (string) $key, 5 ) );
				$name         = ucwords( strtolower( $raw_name ), '-' );
				$out[ $name ] = sanitize_text_field( (string) wp_unslash( $value ) );
			}
		}
		return array_merge(
			array(
				'Accept'         => '',
				'Sec-Fetch-Mode' => '',
				'Sec-Fetch-Dest' => '',
			),
			$out
		);
	}
}
