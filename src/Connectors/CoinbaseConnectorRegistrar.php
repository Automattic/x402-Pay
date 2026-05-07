<?php
/**
 * Registers the "Coinbase CDP" facilitator connector.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Connectors;

use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Services\ConnectorCredentialStore;
use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Services\X402FacilitatorClient;
use SimpleX402\Settings\SettingsRepository;

/**
 * Registers `coinbase_cdp` — Base mainnet USDC settled through Coinbase CDP's
 * x402 facilitator. The connector entry surfaces in the admin picker; the
 * actual verify/settle flow needs JWT signing that X402FacilitatorClient does
 * not yet implement, so the integration is "list-only" until that lands.
 */
final class CoinbaseConnectorRegistrar {

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
	 * `simple_x402_facilitator_for_connector` filter callback.
	 */
	public function provide_facilitator( ?Facilitator $existing, string $id ): ?Facilitator {
		if ( self::ID !== $id || null !== $existing ) {
			return $existing;
		}
		return new X402FacilitatorClient(
			FacilitatorProfile::for_coinbase_cdp(
				$this->settings->api_key_id_for( self::ID ),
				$this->credentials->secret( self::ID ),
			)
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
			'plugin'         => array( 'file' => 'simple-x402/simple-x402.php' ),
		);
	}
}
