<?php
/**
 * EdDSA JWT minter for Coinbase Developer Platform request authentication.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Connectors\Coinbase;

use RuntimeException;
use X402Press\Facilitator\RequestSigner;

/**
 * Mints a fresh, single-use bearer token for each call to a Coinbase CDP API.
 *
 * CDP rejects static API keys: every request needs a JWT signed with the key's
 * Ed25519 private half, valid for ≤120 seconds, with a `uri` claim that pins
 * the token to one method+host+path. The class has no HTTP, no settings,
 * no WordPress dependencies — so the signer is trivially unit-testable.
 *
 * Header: `{ alg: "EdDSA", typ: "JWT", kid, nonce }`
 * Claims: `{ sub, iss: "cdp", aud: ["cdp_service"], nbf, exp, uri }`
 *
 * Spec: https://docs.cdp.coinbase.com/api-reference/v2/authentication
 */
final class JwtSigner implements RequestSigner {

	/** Coinbase rejects tokens with `exp - nbf > 120`. */
	public const TOKEN_TTL_SECONDS = 120;

	public function __construct(
		private readonly string $key_id,
		private readonly string $key_secret_base64,
	) {}

	/**
	 * @return array<string,string> Authorization header for one request.
	 */
	public function sign( string $method, string $url ): array {
		if ( '' === $this->key_id || '' === $this->key_secret_base64 ) {
			throw new RuntimeException( 'Coinbase CDP credentials are not configured.' );
		}
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			throw new RuntimeException( 'libsodium is required to sign Coinbase CDP requests.' );
		}
		$parts  = wp_parse_url( $url );
		$host   = (string) ( $parts['host'] ?? '' );
		$path   = (string) ( $parts['path'] ?? '/' );
		$secret = $this->decode_secret();
		$now    = time();
		$header = array(
			'alg'   => 'EdDSA',
			'typ'   => 'JWT',
			'kid'   => $this->key_id,
			'nonce' => bin2hex( random_bytes( 8 ) ),
		);
		$claims = array(
			'sub' => $this->key_id,
			'iss' => 'cdp',
			'aud' => array( 'cdp_service' ),
			'nbf' => $now,
			'exp' => $now + self::TOKEN_TTL_SECONDS,
			// CDP's spec is literal: "${METHOD} ${HOST}${PATH}" with one
			// space between method and host, and a leading slash on path.
			'uri' => strtoupper( $method ) . ' ' . $host . $path,
		);
		$signing_input = self::base64url( (string) wp_json_encode( $header ) )
			. '.'
			. self::base64url( (string) wp_json_encode( $claims ) );
		$signature     = sodium_crypto_sign_detached( $signing_input, $secret );
		$jwt           = $signing_input . '.' . self::base64url( $signature );
		return array( 'Authorization' => 'Bearer ' . $jwt );
	}

	/**
	 * Coinbase delivers Ed25519 keys base64-encoded; the decoded blob is the
	 * 64-byte libsodium secret key (32-byte seed concatenated with the
	 * 32-byte public key) which `sodium_crypto_sign_detached` expects directly.
	 */
	private function decode_secret(): string {
		$raw = base64_decode( $this->key_secret_base64, true );
		if ( false === $raw ) {
			throw new RuntimeException( 'Coinbase CDP key secret is not valid base64.' );
		}
		if ( SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $raw ) ) {
			throw new RuntimeException(
				sprintf(
					'Coinbase CDP key secret must decode to %d bytes; got %d.',
					SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
					strlen( $raw )
				)
			);
		}
		return $raw;
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
