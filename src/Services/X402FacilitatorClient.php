<?php
/**
 * HTTP client for an x402 facilitator.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\TestResult;
use Throwable;

/**
 * Posts PaymentRequirements + PaymentPayload bodies to a facilitator's
 * /verify and /settle endpoints using wp_remote_post.
 *
 * Endpoint URL and optional bearer authorization come from the injected
 * FacilitatorProfile. The public x402.org facilitator is used in test mode
 * (no auth); live mode typically targets a commercial facilitator (e.g.
 * Coinbase CDP) that requires an API key.
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
	 * For CDP-style profiles (key id + base64 secret) this is a signed GET
	 * against `/supported`, so a green check actually means "auth passes."
	 * For unauthenticated facilitators we fall back to a HEAD against the
	 * base URL — any non-5xx counts as reachable.
	 */
	public function test_connection(): TestResult {
		$base    = rtrim( $this->profile->facilitator_url, '/' );
		$started = microtime( true );

		if ( FacilitatorProfile::AUTH_CDP_JWT === $this->profile->auth_scheme
			&& ! $this->cdp_credentials_complete() ) {
			return new TestResult(
				ok: false,
				error: 'Add the API key ID and secret to verify connectivity.',
				duration_ms: 0,
			);
		}

		if ( $this->uses_cdp_jwt() ) {
			$args = array(
				'method'  => 'GET',
				'timeout' => self::PROBE_TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			);
			try {
				$args['headers'] = array_merge(
					$args['headers'],
					$this->auth_headers_for( 'GET', $base . '/supported' )
				);
			} catch ( Throwable $e ) {
				return new TestResult(
					ok: false,
					error: $e->getMessage(),
					duration_ms: (int) round( ( microtime( true ) - $started ) * 1000 ),
				);
			}
			$raw = wp_remote_request( $base . '/supported', $args );
		} else {
			$raw = wp_remote_head( $base . '/', array( 'timeout' => self::PROBE_TIMEOUT ) );
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
		// CDP probe: 401/403 means the JWT was rejected — that's an auth
		// failure, not a "reachable but quirky" success.
		if ( $this->uses_cdp_jwt() && in_array( $code, array( 401, 403 ), true ) ) {
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

		try {
			$headers = array_merge( $headers, $this->auth_headers_for( 'POST', $url ) );
		} catch ( Throwable $e ) {
			return array(
				'body'  => array(),
				'error' => $e->getMessage(),
			);
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

	/**
	 * Pick the right Authorization header for one request:
	 * - CDP profiles (key id + base64 Ed25519 secret) → fresh per-request JWT.
	 * - Static-bearer profiles (just `api_key` set) → that bearer.
	 * - Unauthenticated profiles → no Authorization header.
	 *
	 * @return array<string,string>
	 */
	private function auth_headers_for( string $method, string $url ): array {
		if ( FacilitatorProfile::AUTH_CDP_JWT === $this->profile->auth_scheme ) {
			if ( ! $this->cdp_credentials_complete() ) {
				throw new \RuntimeException(
					'Coinbase CDP credentials are not configured.'
				);
			}
			$parts  = wp_parse_url( $url );
			$host   = (string) ( $parts['host'] ?? '' );
			$path   = (string) ( $parts['path'] ?? '/' );
			$signer = new CoinbaseJwtSigner(
				$this->profile->api_key_id,
				$this->profile->api_key_secret,
			);
			return array( 'Authorization' => 'Bearer ' . $signer->sign( $method, $host, $path ) );
		}
		if ( '' !== $this->profile->api_key ) {
			return array( 'Authorization' => 'Bearer ' . $this->profile->api_key );
		}
		return array();
	}

	private function uses_cdp_jwt(): bool {
		return FacilitatorProfile::AUTH_CDP_JWT === $this->profile->auth_scheme
			&& $this->cdp_credentials_complete();
	}

	private function cdp_credentials_complete(): bool {
		return '' !== $this->profile->api_key_id && '' !== $this->profile->api_key_secret;
	}
}
