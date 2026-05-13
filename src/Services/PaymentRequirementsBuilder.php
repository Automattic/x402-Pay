<?php
/**
 * Builds the x402 PaymentRequirements payload for a single request.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Services;

/**
 * Assembles the `PaymentRequirements` array embedded in the spec-standard
 * 402 JSON envelope (`{ x402Version, error, accepts: [<PaymentRequirements>] }`).
 *
 * Network, asset, and EIP-712 domain come from the injected FacilitatorProfile,
 * so the builder itself is agnostic to test vs live.
 */
final class PaymentRequirementsBuilder {

	private const SCHEME      = 'exact';
	private const MAX_TIMEOUT = 120;

	public function __construct( private readonly FacilitatorProfile $profile ) {}

	/**
	 * Build a PaymentRequirements array.
	 *
	 * @param string $pay_to       Receiving wallet address (EVM).
	 * @param string $price        Decimal price in USDC, e.g. "0.01".
	 * @param string $resource_url Absolute URL being paywalled.
	 * @param string $description  Human-readable description.
	 */
	public function build(
		string $pay_to,
		string $price,
		string $resource_url,
		string $description
	): array {
		return array(
			'scheme'            => self::SCHEME,
			'network'           => $this->profile->network,
			'asset'             => $this->profile->asset,
			'payTo'             => $pay_to,
			'maxAmountRequired' => $this->to_base_units( $price ),
			'resource'          => $resource_url,
			'description'       => $description,
			'mimeType'          => 'application/json',
			'maxTimeoutSeconds' => self::MAX_TIMEOUT,
			'extra'             => array(
				'name'    => $this->profile->eip712_name,
				'version' => $this->profile->eip712_version,
			),
		);
	}

	/**
	 * Convert a decimal string amount into base units (atomic token units).
	 */
	private function to_base_units( string $decimal ): string {
		if ( ! PriceSanitizer::is_fixed_decimal( $decimal ) ) {
			return '0';
		}
		[ $whole, $frac ] = array_pad( explode( '.', $decimal, 2 ), 2, '' );
		$frac             = substr( $frac, 0, $this->profile->asset_decimals );
		$frac             = str_pad( $frac, $this->profile->asset_decimals, '0' );
		$combined         = ltrim( $whole . $frac, '0' );
		return '' === $combined ? '0' : $combined;
	}
}
