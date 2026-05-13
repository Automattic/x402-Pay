<?php
/**
 * Admin: Settings → x402 Pay page.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Admin;

use X402Press\Admin\SettingsAjax;
use X402Press\Admin\PaywallProbeAjax;
use X402Press\Admin\TestConnectionAjax;
use X402Press\Connectors\ConnectorRegistry;
use X402Press\Services\ConnectorCredentialStore;
use X402Press\Services\FacilitatorHooks;
use X402Press\Settings\SettingsRepository;

/**
 * Settings → x402 Pay admin page.
 *
 * Renders a mount point + JSON bootstrap; the React app in
 * assets/build/admin/index.js handles the form UI. Form submission still
 * uses the classic options.php POST flow, so the React inputs include
 * hidden <input name="..."> fields with the values WP expects.
 */
final class SettingsPage {

	public const MENU_SLUG     = 'x402-pay';
	public const GROUP         = 'x402press_settings_group';
	public const SCRIPT_HANDLE = 'x402press-admin';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ConnectorRegistry $connectors = new ConnectorRegistry(),
		private readonly ConnectorCredentialStore $credentials = new ConnectorCredentialStore(),
	) {}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Tag <body> on our screen so the bundle CSS can override #wpcontent gutters.
	 */
	public function admin_body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . self::MENU_SLUG === $screen->id ) {
			$classes .= ' x402press-screen';
		}
		return $classes;
	}

	/**
	 * Register admin JS for this page only.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_path = X402PRESS_DIR . 'assets/build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => X402PRESS_VERSION,
			);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/index.js', X402PRESS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );

		$style_path = X402PRESS_DIR . 'assets/build/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'assets/build/style-index.css', X402PRESS_FILE ),
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'x402pressSettings',
			$this->bootstrap_data()
		);
	}

	/**
	 * Add the Settings → x402 Pay menu item.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'x402 Pay', 'x402-pay' ),
			__( 'x402 Pay', 'x402-pay' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the single option that backs the entire form.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			SettingsRepository::OPTION_NAME,
			array(
				'sanitize_callback' => fn ( $input ): array => $this->settings->sanitize(
					is_array( $input ) ? $input : array()
				),
			)
		);
	}

	/**
	 * Render the settings page shell. The React app paints itself into #x402press-app.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<header class="x402press-page__header">
				<h1 class="x402press-page__header-title">
					<?php esc_html_e( 'x402 Pay', 'x402-pay' ); ?>
				</h1>
				<p class="x402press-page__header-subtitle">
					<?php
					esc_html_e(
						'Configure how the x402 paywall protects your content and where payments go.',
						'x402-pay'
					);
					?>
				</p>
			</header>
			<div id="x402press-app"></div>
		</div>
		<?php
	}

	/**
	 * Build the JSON payload the React app reads on boot.
	 *
	 * @return array<string,mixed>
	 */
	public function bootstrap_data(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}
		$categories = array_map(
			static fn ( $term ): array => array(
				'term_id' => (int) $term->term_id,
				'name'    => sanitize_text_field( (string) $term->name ),
			),
			$terms
		);

		$connectors   = $this->connectors->facilitators();
		$facilitators = array();
		foreach ( $connectors as $id => $connector ) {
			$id = self::sanitize_connector_id( (string) $id );
			if ( '' === $id || ! is_array( $connector ) ) {
				continue;
			}
			$facilitators[] = array(
				'id'          => $id,
				'name'        => sanitize_text_field( (string) ( $connector['name'] ?? $id ) ),
				'description' => sanitize_text_field( (string) ( $connector['description'] ?? '' ) ),
			);
		}

		$managed_wallet_facilitators = array();
		$api_key_facilitators        = array();
		$connector_credentials       = array();
		$connector_admin_meta        = array();
		foreach ( $connectors as $fid => $connector ) {
			$fid = self::sanitize_connector_id( (string) $fid );
			if ( '' === $fid ) {
				continue;
			}
			if ( '' !== (string) apply_filters( FacilitatorHooks::MANAGED_POOL_PAY_TO, '', $fid ) ) {
				$managed_wallet_facilitators[] = $fid;
			}
			$auth_method = (string) ( ( $connector['authentication']['method'] ?? '' ) );
			if ( 'api_key' === $auth_method ) {
				$api_key_facilitators[]        = $fid;
				$connector_credentials[ $fid ] = $this->credentials->status( $fid );
				$meta                          = apply_filters( FacilitatorHooks::CONNECTOR_ADMIN_META, array(), $fid );
				if ( is_array( $meta ) && array() !== $meta ) {
					$connector_admin_meta[ $fid ] = self::sanitize_connector_admin_meta( $meta );
				}
			}
		}

		return array(
			'option'                    => SettingsRepository::OPTION_NAME,
			'modes'                     => array(
				'paywall'  => array(
					'none'     => SettingsRepository::PAYWALL_MODE_NONE,
					'allPosts' => SettingsRepository::PAYWALL_MODE_ALL_POSTS,
					'category' => SettingsRepository::PAYWALL_MODE_CATEGORY,
				),
				'audience' => array(
					'everyone' => SettingsRepository::AUDIENCE_EVERYONE,
					'bots'     => SettingsRepository::AUDIENCE_BOTS,
				),
			),
			'categories'                => $categories,
			'modeCategory'              => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'facilitators'              => $facilitators,
			'managedWalletFacilitators' => $managed_wallet_facilitators,
			'apiKeyFacilitators'        => $api_key_facilitators,
			'connectorCredentials'      => $connector_credentials,
			'connectorAdminMeta'        => $connector_admin_meta,
			'ajaxUrl'                   => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'testConnection'            => array(
				'action' => TestConnectionAjax::ACTION,
				'nonce'  => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( TestConnectionAjax::NONCE ) : '',
			),
			'saveSettings'              => array(
				'action' => SettingsAjax::ACTION,
				'nonce'  => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( SettingsAjax::NONCE ) : '',
			),
			'paywallProbe'              => array(
				'action' => PaywallProbeAjax::ACTION,
				'nonce'  => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( PaywallProbeAjax::NONCE ) : '',
			),
			'values'                    => array(
				'paywall_mode'             => $this->settings->paywall_mode(),
				'paywall_audience'         => $this->settings->paywall_audience(),
				'paywall_category_term_id' => $this->settings->paywall_category_term_id(),
				'selected_facilitator_id'  => $this->settings->selected_facilitator_id(),
				'facilitators'             => $this->settings->facilitator_slots(),
				'default_price'            => $this->settings->default_price(),
			),
		);
	}

	private static function sanitize_connector_id( string $id ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $id ) );
	}

	/**
	 * Keep connector-supplied admin UI metadata to plain text and safe URLs
	 * before it crosses into the React bootstrap payload.
	 *
	 * @param array<string,mixed> $meta
	 * @return array<string,string>
	 */
	private static function sanitize_connector_admin_meta( array $meta ): array {
		$text_keys = array(
			'introHeadline',
			'introBody',
			'docsLinkText',
			'keyIdPlaceholder',
			'keyIdPattern',
			'keyIdInvalidMessage',
			'keySecretPlaceholder',
			'keySecretPattern',
			'keySecretInvalidMessage',
		);
		$out       = array();
		foreach ( $text_keys as $key ) {
			if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ) {
				$out[ $key ] = 'introBody' === $key
					? self::sanitize_interpolated_text( (string) $meta[ $key ] )
					: sanitize_text_field( (string) $meta[ $key ] );
			}
		}
		if ( isset( $meta['docsUrl'] ) && is_scalar( $meta['docsUrl'] ) ) {
			$out['docsUrl'] = self::sanitize_http_url( (string) $meta['docsUrl'] );
		}
		return $out;
	}

	private static function sanitize_interpolated_text( string $text ): string {
		$token = '__X402PRESS_DOCS_PLACEHOLDER__';
		$text  = str_replace( '<docs/>', $token, $text );
		$text  = sanitize_text_field( $text );
		return str_replace( $token, '<docs/>', $text );
	}

	private static function sanitize_http_url( string $url ): string {
		$url    = trim( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}
}
