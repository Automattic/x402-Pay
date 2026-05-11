<?php
/**
 * Read/write secrets for x402 facilitator connectors using the WordPress 7
 * Connectors API conventions.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Connectors\ConnectorRegistry;

/**
 * Stores facilitator API key secrets in their own wp_options row, named
 * `connectors_{type}_{id}_api_key`, the same pattern WordPress 7's Connectors
 * API uses for built-in providers. Reads honour an env-var/constant override
 * first so production sites can keep secrets out of the database entirely.
 *
 * The matching public KEY_ID stays in the SettingsRepository slot — only the
 * sensitive half goes through this store.
 */
final class ConnectorCredentialStore {

	public const SOURCE_NONE     = 'none';
	public const SOURCE_OPTION   = 'option';
	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_ENV      = 'env';

	/**
	 * @return string '' when the secret is unset.
	 */
	public function secret( string $connector_id ): string {
		$env = getenv( $this->env_name( $connector_id ) );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		$constant = $this->constant_name( $connector_id );
		if ( defined( $constant ) ) {
			$value = constant( $constant );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		$stored = get_option( $this->option_name( $connector_id ), '' );
		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Where the secret came from. Useful for the admin UI to show
	 * "configured via wp-config.php" instead of an empty input.
	 */
	public function source( string $connector_id ): string {
		$env = getenv( $this->env_name( $connector_id ) );
		if ( is_string( $env ) && '' !== $env ) {
			return self::SOURCE_ENV;
		}
		$constant = $this->constant_name( $connector_id );
		if ( defined( $constant ) ) {
			$value = constant( $constant );
			if ( is_string( $value ) && '' !== $value ) {
				return self::SOURCE_CONSTANT;
			}
		}
		$stored = get_option( $this->option_name( $connector_id ), '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			return self::SOURCE_OPTION;
		}
		return self::SOURCE_NONE;
	}

	public function has_secret( string $connector_id ): bool {
		return self::SOURCE_NONE !== $this->source( $connector_id );
	}

	/**
	 * True only when the database-backed slot is the source of truth (i.e. no
	 * env or constant is shadowing it). The admin UI uses this to disable the
	 * input when the secret is configured via wp-config.php.
	 */
	public function is_writable( string $connector_id ): bool {
		$source = $this->source( $connector_id );
		return self::SOURCE_OPTION === $source || self::SOURCE_NONE === $source;
	}

	/**
	 * Persist a new secret. Trimmed; no-op if env/constant overrides are
	 * active. Pass '' to clear the stored value.
	 */
	public function set_secret( string $connector_id, string $value ): void {
		if ( ! $this->is_writable( $connector_id ) ) {
			return;
		}
		$trimmed   = trim( $value );
		$option    = $this->option_name( $connector_id );
		$timestamp = $this->saved_at_option_name( $connector_id );
		if ( '' === $trimmed ) {
			delete_option( $option );
			delete_option( $timestamp );
			return;
		}
		update_option( $option, $trimmed, false );
		update_option( $timestamp, time(), false );
	}

	/**
	 * Public-facing status payload for one connector — the shape the admin
	 * UI consumes from both bootstrap and AJAX save responses. Keeping the
	 * builder here means both call sites stay in lockstep.
	 *
	 * @return array<string,mixed>
	 */
	public function status( string $connector_id ): array {
		$saved_at = $this->saved_at( $connector_id );
		return array(
			'has_secret'     => $this->has_secret( $connector_id ),
			'source'         => $this->source( $connector_id ),
			'is_writable'    => $this->is_writable( $connector_id ),
			'constant_name'  => $this->constant_name( $connector_id ),
			'saved_at'       => $saved_at,
			'saved_at_label' => null === $saved_at
				? null
				: wp_date( (string) get_option( 'date_format', 'M j, Y' ), $saved_at ),
		);
	}

	/**
	 * Unix timestamp of the last DB-backed write, or null when the secret is
	 * unset or sourced from env/constant.
	 */
	public function saved_at( string $connector_id ): ?int {
		if ( self::SOURCE_OPTION !== $this->source( $connector_id ) ) {
			return null;
		}
		$ts = get_option( $this->saved_at_option_name( $connector_id ), 0 );
		$ts = is_numeric( $ts ) ? (int) $ts : 0;
		return $ts > 0 ? $ts : null;
	}

	/**
	 * Canonical option name. Matches the WordPress 7 Connectors API pattern
	 * documented at make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/.
	 */
	public function option_name( string $connector_id ): string {
		return 'connectors_' . ConnectorRegistry::FACILITATOR_TYPE . '_' . $connector_id . '_api_key';
	}

	private function saved_at_option_name( string $connector_id ): string {
		return $this->option_name( $connector_id ) . '_saved_at';
	}

	/**
	 * Constant name a site admin can `define()` in wp-config.php to override
	 * the database-backed secret. Same name doubles as the env var.
	 */
	public function constant_name( string $connector_id ): string {
		return strtoupper( $this->option_name( $connector_id ) );
	}

	private function env_name( string $connector_id ): string {
		return $this->constant_name( $connector_id );
	}
}
