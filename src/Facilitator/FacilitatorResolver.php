<?php
/**
 * Apply the `x402_pay_facilitator_for_connector` filter to get a client.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Facilitator;

use X402Pay\Connectors\ConnectorRegistry;

/**
 * One place that knows how to turn a connector ID into a working Facilitator
 * client. Callers (settings page, PaywallController, Test Connection ajax)
 * all go through here so the filter contract only appears once in the code.
 */
final class FacilitatorResolver {

	public const FILTER = 'x402_pay_facilitator_for_connector';

	public function __construct( private readonly ConnectorRegistry $connectors ) {}

	/**
	 * Resolve a connector ID to a Facilitator, or null if no callback claims it.
	 */
	public function resolve( string $connector_id ): ?Facilitator {
		$connector = $this->connectors->facilitator( $connector_id );
		if ( null === $connector ) {
			return null;
		}
		$client = apply_filters( self::FILTER, null, $connector_id, $connector );
		return $client instanceof Facilitator ? $client : null;
	}
}
