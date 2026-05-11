<?php
/**
 * Serialises and deserialises base64(JSON) x402 HTTP header payloads.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

/**
 * Encodes and decodes base64(JSON) header payloads used by the x402 protocol
 * for the `X-PAYMENT` request header (signed authorization envelope) and the
 * `X-PAYMENT-RESPONSE` response header (settlement receipt).
 */
final class X402HeaderCodec {

	/**
	 * Encode a payload as base64-encoded JSON.
	 *
	 * @param array $payload Payload to encode.
	 */
	public static function encode( array $payload ): string {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '';
		}
		return base64_encode( $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- x402 spec requires base64.
	}

	/**
	 * Decode a base64-encoded JSON header value.
	 *
	 * @param string $header Raw header value.
	 *
	 * @return array|null Decoded associative array, or null if the header is malformed.
	 */
	public static function decode( string $header ): ?array {
		$header = trim( $header );
		if ( '' === $header ) {
			return null;
		}
		$decoded = base64_decode( $header, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- x402 spec requires base64.
		if ( false === $decoded ) {
			return null;
		}
		$data = json_decode( $decoded, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return $data;
	}
}
