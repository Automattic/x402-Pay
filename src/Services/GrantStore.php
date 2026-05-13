<?php
/**
 * Short-lived access grants backed by WordPress transients.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Services;

/**
 * Issues opaque grant tokens after a successful payment and redeems them on
 * follow-up requests, so a paying client can read the same path again
 * without re-paying for the rule-specified TTL.
 *
 * The token is the secret. Wallet addresses do not bypass the paywall —
 * they're public on-chain, so anyone watching the recipient could replay
 * them. The store keeps `sha256(token)` (not the raw token) so a database
 * leak doesn't expose redeemable bearer credentials.
 *
 * Storage shape (per token, behind the hashed transient key):
 * `[ 'path' => string, 'issued_at' => int, ...$meta ]`
 */
final class GrantStore {

	private const PREFIX = 'x402_pay_gt_';

	/**
	 * Mint a new grant. Returns the freshly-generated token; the caller
	 * is responsible for delivering it to the client (e.g. as a response
	 * header and/or Set-Cookie).
	 *
	 * @param string $path Request path the grant covers — verified at redeem
	 *                     time so a token leaked from one URL can't be
	 *                     replayed against another.
	 * @param int    $ttl  Lifetime in seconds; non-positive returns ''.
	 * @param array  $meta Free-form metadata (e.g. tx hash) merged with the
	 *                     stored value.
	 * @return string The token, or '' when the grant could not be issued.
	 */
	public function issue( string $path, int $ttl, array $meta = array() ): string {
		if ( $ttl <= 0 ) {
			return '';
		}
		$token = bin2hex( random_bytes( 32 ) );
		$key   = self::key( $token );
		set_transient(
			$key,
			$meta + array(
				'path'      => $path,
				'issued_at' => time(),
			),
			$ttl
		);
		return $token;
	}

	/**
	 * True only when $token resolves to a live grant whose stored path
	 * matches $path exactly. A leaked token presented against any other
	 * path is rejected so the bypass can't be reused across URLs.
	 */
	public function redeem( string $token, string $path ): bool {
		if ( '' === $token ) {
			return false;
		}
		$stored = get_transient( self::key( $token ) );
		if ( ! is_array( $stored ) ) {
			return false;
		}
		return ( $stored['path'] ?? '' ) === $path;
	}

	/**
	 * Hashes the raw token so the transients table never holds a redeemable
	 * bearer secret in plaintext. SHA-256 is fine here — the token has
	 * 256 bits of entropy from `random_bytes(32)`, so there's no offline
	 * brute-force surface to harden against.
	 */
	private static function key( string $token ): string {
		return self::PREFIX . hash( 'sha256', $token );
	}
}
