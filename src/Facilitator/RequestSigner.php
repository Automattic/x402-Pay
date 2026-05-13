<?php
/**
 * Per-request authentication primitive for facilitator HTTP calls.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Facilitator;

/**
 * Returns the headers needed to authenticate one outbound request to a
 * facilitator endpoint. Implementations are wholly owned by their connector
 * — the core HTTP client never knows what auth scheme is in play, just that
 * it has a signer (or doesn't).
 *
 * `sign()` is invoked once per request, so signers that mint short-lived
 * tokens (e.g. JWTs with a 120s exp) can return a fresh value on every
 * call without caching.
 *
 * Throw a `RuntimeException` from `sign()` to signal "credentials are not
 * configured" or "secret is malformed"; the client surfaces the message on
 * test_connection and aborts the call on verify/settle paths.
 */
interface RequestSigner {

	/**
	 * @param string $method HTTP method (GET, POST, …) of the outbound call.
	 * @param string $url    Absolute URL of the outbound call.
	 *
	 * @return array<string,string> Headers to merge into the outbound request.
	 */
	public function sign( string $method, string $url ): array;
}
