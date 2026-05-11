<?php
/**
 * Paywall-oriented view of client HTTP signals (Phase A classifier).
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

/**
 * Pure classification of User-Agent plus fetch / Accept headers for paywall
 * 402 body negotiation (Phase B).
 *
 * **Stable public surface for Phase B:** read only the readonly properties
 * below; do not depend on internal helpers. Classification order matches
 * `docs/paywall-ux-simplification.md` § “Classification order (v1)” for
 * signal extraction (Sec-Fetch pair, then Accept, then CrawlerDetect bot).
 *
 * - {@see self::$document_navigation_intent}: `Sec-Fetch-Mode: navigate` and
 *   `Sec-Fetch-Dest: document` (both required, case-insensitive).
 * - {@see self::$json_accept_intent}: `Accept` lists `application/json` or a
 *   `+json` media subtype (e.g. `application/problem+json`); does not treat
 *   `application/json-seq` as JSON.
 * - {@see self::$is_bot}: {@see BotDetector} on the User-Agent string.
 * - {@see self::$xml_http_request}: `X-Requested-With: XMLHttpRequest`
 *   (case-insensitive); optional signal for API-style requests.
 *
 * This type does **not** decide who is paywalled (audience / bot-only policy
 * remains elsewhere until later phases).
 */
final class PaywallClientProfile {

	public function __construct(
		public readonly bool $is_bot,
		public readonly bool $document_navigation_intent,
		public readonly bool $json_accept_intent,
		public readonly bool $xml_http_request,
	) {}

	/**
	 * @param string      $user_agent        Raw User-Agent (empty allowed).
	 * @param string      $accept            Raw Accept header.
	 * @param string      $sec_fetch_mode    Sec-Fetch-Mode value or empty.
	 * @param string      $sec_fetch_dest    Sec-Fetch-Dest value or empty.
	 * @param string|null $x_requested_with  X-Requested-With or null if absent.
	 */
	public static function classify(
		string $user_agent,
		string $accept,
		string $sec_fetch_mode,
		string $sec_fetch_dest,
		?string $x_requested_with = null,
	): self {
		$mode_trim           = strtolower( trim( $sec_fetch_mode ) );
		$dest_trim           = strtolower( trim( $sec_fetch_dest ) );
		$document_navigation = ( 'navigate' === $mode_trim && 'document' === $dest_trim );

		$xrw = null !== $x_requested_with ? strtolower( trim( $x_requested_with ) ) : '';
		$xhr = ( 'xmlhttprequest' === $xrw );

		return new self(
			( new BotDetector( $user_agent ) )->is_bot(),
			$document_navigation,
			self::accept_lists_json( $accept ),
			$xhr,
		);
	}

	private static function accept_lists_json( string $accept ): bool {
		if ( '' === $accept ) {
			return false;
		}
		foreach ( explode( ',', $accept ) as $part ) {
			$part  = trim( $part );
			$media = strtolower( trim( explode( ';', $part, 2 )[0] ) );
			if ( '' === $media ) {
				continue;
			}
			if ( 'application/json' === $media ) {
				return true;
			}
			if ( str_ends_with( $media, '+json' ) ) {
				return true;
			}
		}
		return false;
	}
}
