<?php
/**
 * Common surface every x402 facilitator client implements.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Facilitator;

use SimpleX402\Services\FacilitatorProfile;

/**
 * The plugin talks to facilitators (x402.org, Coinbase CDP, etc.) through
 * this single interface. Connector authors return an instance of this from
 * the `simple_x402_facilitator_for_connector` filter.
 */
interface Facilitator {

	/**
	 * Describe what network/asset/EIP-712 domain this facilitator operates on.
	 * Drives PaymentRequirementsBuilder so every facilitator is the single
	 * source of truth about its own environment — site owners only provide
	 * wallet + price, never network-specific plumbing.
	 */
	public function describe(): FacilitatorProfile;


	/**
	 * Verify a payment payload against its requirements.
	 *
	 * @param array<string,mixed> $requirements PaymentRequirements.
	 * @param array<string,mixed> $payload      PaymentPayload.
	 * @return array{isValid:bool,error:?string,raw:array<string,mixed>}
	 */
	public function verify( array $requirements, array $payload ): array;

	/**
	 * Settle a verified payment on-chain via the facilitator.
	 *
	 * @param array<string,mixed> $requirements PaymentRequirements.
	 * @param array<string,mixed> $payload      PaymentPayload.
	 * @return array{success:bool,transaction:?string,network:?string,error:?string,raw:array<string,mixed>}
	 */
	public function settle( array $requirements, array $payload ): array;

	/**
	 * Quick "is this facilitator reachable" probe for the admin UI. Meant to
	 * be cheap — a HEAD or GET to the base URL, not a real verify.
	 */
	public function test_connection(): TestResult;
}
