<?php
/**
 * Registers the "Coinbase CDP" facilitator connector.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Connectors\Coinbase;

use X402Press\Connectors\ConnectorRegistry;
use X402Press\Facilitator\Facilitator;
use X402Press\Services\ConnectorCredentialStore;
use X402Press\Services\X402FacilitatorClient;
use X402Press\Settings\SettingsRepository;

/**
 * Registers `coinbase_cdp` — Base mainnet USDC settled through Coinbase CDP's
 * x402 facilitator. The connector entry surfaces in the admin picker, and the
 * provider hook builds an X402FacilitatorClient pointed at the CDP profile
 * with the JWT signer pre-wired.
 */
final class Registrar {

	public const ID = 'coinbase_cdp';

	public function __construct(
		private readonly SettingsRepository $settings = new SettingsRepository(),
		private readonly ConnectorCredentialStore $credentials = new ConnectorCredentialStore(),
	) {}

	/**
	 * Hooked to `wp_connectors_init`.
	 */
	public function __invoke( \WP_Connector_Registry $registry ): void {
		$registry->register( self::ID, self::payload() );
	}

	/**
	 * `x402press_facilitator_for_connector` filter callback.
	 */
	public function provide_facilitator( ?Facilitator $existing, string $id ): ?Facilitator {
		if ( self::ID !== $id || null !== $existing ) {
			return $existing;
		}
		return new X402FacilitatorClient(
			Profile::for_cdp(
				$this->settings->api_key_id_for( self::ID ),
				$this->credentials->secret( self::ID ),
			)
		);
	}

	/**
	 * `x402press_connector_admin_meta` filter callback. Provides the
	 * intro copy, docs link, placeholders, validation regex, and error
	 * messages the admin UI shows when this connector is selected — so the
	 * generic React app stays free of CDP-specific strings.
	 *
	 * @param array<string,mixed> $existing
	 *
	 * @return array<string,mixed>
	 */
	public function provide_admin_meta( array $existing, string $id ): array {
		if ( self::ID !== $id ) {
			return $existing;
		}
		return array(
			'introHeadline'           => __(
				'Connect this site to Coinbase to accept USDC payments on Base mainnet.',
				'x402press'
			),
			// `<docs/>` is a self-closing placeholder the React app interpolates
			// into a link to `docsUrl`.
			'introBody'               => __(
				'Read the <docs/>, then paste the two values it gives you below.',
				'x402press'
			),
			'docsLinkText'            => __( 'guide on creating your API keys', 'x402press' ),
			'docsUrl'                 => 'https://docs.cdp.coinbase.com/api-reference/v2/authentication#secret-api-key',
			'keyIdPlaceholder'        => '00000000-0000-0000-0000-000000000000',
			// UUID 8-4-4-4-12. Hex char class lists both cases so the JS RegExp
			// constructor doesn't need flags.
			'keyIdPattern'            => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
			'keyIdInvalidMessage'     => __(
				'Doesn’t look like a UUID. Copy the value labelled “API Key ID” in the CDP Portal.',
				'x402press'
			),
			'keySecretPlaceholder'    => __( 'Paste the long secret string from the CDP Portal.', 'x402press' ),
			// Loose sanity check: base64 alphabet (incl. URL-safe variant) plus
			// padding, ≥40 chars. Real validation happens at first verify call.
			'keySecretPattern'        => '^[A-Za-z0-9+/_=-]{40,}$',
			'keySecretInvalidMessage' => __(
				'That doesn’t look like a CDP key secret. Copy the “API Key Secret” value, not the JSON key/value pair.',
				'x402press'
			),
		);
	}

	/**
	 * Registration payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		return array(
			'name'           => 'Coinbase CDP',
			'description'    => 'Coinbase Developer Platform x402 facilitator on Base mainnet (USDC). Requires a CDP API key.',
			'type'           => ConnectorRegistry::FACILITATOR_TYPE,
			'authentication' => array( 'method' => 'api_key' ),
			'plugin'         => array( 'file' => 'x402-pay/x402press.php' ),
		);
	}
}
