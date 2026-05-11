<?php
/**
 * HTTP client for an x402 facilitator.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Facilitator\Facilitator;
use X402Press\Facilitator\TestResult;
use Throwable;

/**
 * Posts PaymentRequirements + PaymentPayload bodies to a facilitator's
 * /verify and /settle endpoints using wp_remote_post.
 *
 * Authentication is delegated to the optional {@see \X402Press\Facilitator\RequestSigner}
 * carried by the profile — the client never knows which scheme is in play
 * (no auth, static bearer, signed JWT, …) and just merges whatever headers
 * the signer returns into each outbound request.
 */
final class X402FacilitatorClient implements Facilitator {

	private const TIMEOUT       = 25;
	private const PROBE_TIMEOUT = 10;

	public function __construct( private readonly FacilitatorProfile $profile ) {}

	public function describe(): FacilitatorProfile {
		return $this->profile;
	}

	/**
	 * Verify a payment payload against requirements.
	 *
	 * @param array $requirements PaymentRequirements.
	 * @param array $payload      PaymentPayload extracted from PAYMENT-SIGNATURE.
	 *
	 * @return array{isValid:bool,error:?string,raw:array}
	 */
	public function verify( array $requirements, array $payload ): array {
		$response = $this->post(
			'verify',
			array(
				'paymentRequirements' => $requirements,
				'paymentPayload'      => $payload,
			)
		);
		return array(
			'isValid' => (bool) ( $response['body']['isValid'] ?? false ),
			'error'   => $response['error'] ?? ( $response['body']['invalidReason'] ?? null ),
			'raw'     => $response['body'],
		);
	}

	/**
	 * Settle a verified payment on-chain via the facilitator.
	 *
	 * @param array $requirements PaymentRequirements.
	 * @param array $payload      PaymentPayload.
	 *
	 * @return array{success:bool,transaction:?string,network:?string,error:?string,raw:array}
	 */
	public function settle( array $requirements, array $payload ): array {
		$response = $this->post(
			'settle',
			array(
				'paymentRequirements' => $requirements,
				'paymentPayload'      => $payload,
			)
		);
		return array(
			'success'     => (bool) ( $response['body']['success'] ?? false ),
			'transaction' => $response['body']['transaction'] ?? null,
			'network'     => $response['body']['network'] ?? null,
			'error'       => $response['error'] ?? ( $response['body']['errorReason'] ?? null ),
			'raw'         => $response['body'],
		);
	}

	/**
	 * Probe the facilitator to see if the configured credentials work.
	 *
	 * For authenticated profiles (signer present) this is a signed GET against
	 * `/supported`, so a green check actually means "auth passes." For
	 * unauthenticated facilitators we fall back to a HEAD against the base
	 * URL — any non-5xx counts as reachable.
	 */
	public function test_connection(): TestResult {
		$base    = rtrim( $this->profile->facilitator_url, '/' );
		$started = microtime( true );

		if ( null === $this->profile->signer ) {
			$raw = wp_remote_head( $base . '/', array( 'timeout' => self::PROBE_TIMEOUT ) );
		} else {
			try {
				$auth_headers = $this->profile->signer->sign( 'GET', $base . '/supported' );
			} catch ( Throwable $e ) {
				return new TestResult(
					ok: false,
					error: $e->getMessage(),
					duration_ms: (int) round( ( microtime( true ) - $started ) * 1000 ),
				);
			}
			$raw = wp_remote_request(
				$base . '/supported',
				array(
					'method'  => 'GET',
					'timeout' => self::PROBE_TIMEOUT,
					'headers' => array_merge( array( 'Accept' => 'application/json' ), $auth_headers ),
				)
			);
		}

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $raw ) ) {
			return new TestResult(
				ok: false,
				error: $raw->get_error_message(),
				duration_ms: $elapsed,
			);
		}
		$code = wp_remote_retrieve_response_code( $raw );
		if ( 0 === $code || $code >= 500 ) {
			return new TestResult(
				ok: false,
				error: 0 === $code ? 'No response' : "HTTP {$code}",
				http_code: $code,
				duration_ms: $elapsed,
			);
		}
		// For authenticated probes, 401/403 means the signer's credentials
		// were rejected — that's an auth failure, not a "reachable but quirky"
		// success. Unauthenticated probes pass through to the success path.
		if ( null !== $this->profile->signer && in_array( $code, array( 401, 403 ), true ) ) {
			return new TestResult(
				ok: false,
				error: "HTTP {$code} — credentials rejected",
				http_code: $code,
				duration_ms: $elapsed,
			);
		}
		return new TestResult(
			ok: true,
			http_code: $code,
			duration_ms: $elapsed,
		);
	}

	/**
	 * POST JSON to a facilitator endpoint.
	 *
	 * @param string $endpoint Endpoint path (e.g. "verify", "settle").
	 * @param array  $body     Request body.
	 *
	 * @return array{body:array,error:?string}
	 */
	private function post( string $endpoint, array $body ): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		$base = rtrim( $this->profile->facilitator_url, '/' ) . '/';
		$url  = $base . ltrim( $endpoint, '/' );

		if ( null !== $this->profile->signer ) {
			try {
				$headers = array_merge( $headers, $this->profile->signer->sign( 'POST', $url ) );
			} catch ( Throwable $e ) {
				return array(
					'body'  => array(),
					'error' => $e->getMessage(),
				);
			}
		}

		$raw = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $raw ) ) {
			return array(
				'body'  => array(),
				'error' => $raw->message,
			);
		}

		$code   = wp_remote_retrieve_response_code( $raw );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'body'  => $parsed,
				'error' => $parsed['error'] ?? "HTTP {$code}",
			);
		}
		return array(
			'body'  => $parsed,
			'error' => null,
		);
	}
}
