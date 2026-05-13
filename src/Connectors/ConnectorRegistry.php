<?php
/**
 * Read-side wrapper over the WordPress 7.0 Connectors API.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Connectors;

/**
 * Finds x402 facilitator connectors registered via the WP 7.0 Connectors API.
 *
 * The Connectors API lets any plugin register a connection to an external
 * service; each registration carries a free-form `type` string. We claim the
 * type `x402_facilitator` and expose the filtered view through this class,
 * so callers never have to re-check the type themselves.
 */
final class ConnectorRegistry {

	public const FACILITATOR_TYPE = 'x402_facilitator';

	/**
	 * True on WordPress 7.0+. On older installs the Connectors API functions
	 * don't exist and every other method degrades to an empty result.
	 */
	public function is_available(): bool {
		return function_exists( 'wp_get_connectors' );
	}

	/**
	 * Every registered connector whose type is x402_facilitator, keyed by ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function facilitators(): array {
		if ( ! $this->is_available() ) {
			return array();
		}
		$all = wp_get_connectors();
		if ( ! is_array( $all ) ) {
			return array();
		}
		return array_filter(
			$all,
			fn ( $connector ): bool => is_array( $connector )
				&& ( $connector['type'] ?? '' ) === self::FACILITATOR_TYPE
		);
	}

	/**
	 * Fetch a single connector by ID, or null if it doesn't exist or isn't
	 * of type x402_facilitator.
	 *
	 * @return array<string,mixed>|null
	 */
	public function facilitator( string $id ): ?array {
		if ( ! $this->is_available() ) {
			return null;
		}
		$connector = wp_get_connector( $id );
		if ( ! is_array( $connector ) ) {
			return null;
		}
		if ( ( $connector['type'] ?? '' ) !== self::FACILITATOR_TYPE ) {
			return null;
		}
		return $connector;
	}
}
