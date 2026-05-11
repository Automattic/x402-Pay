<?php
/**
 * Orchestrates the paywall flow on template_redirect.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Http;

use X402Press\Facilitator\Facilitator;
use X402Press\Facilitator\FacilitatorResolver;
use X402Press\Payment\PaymentProviderRegistry;
use X402Press\Services\GrantStore;
use X402Press\Services\PaywallClientProfile;
use X402Press\Services\PaymentRequirementsBuilder;
use X402Press\Services\PaymentSettlementNotifier;
use X402Press\Services\RuleResolver;
use X402Press\Services\X402HeaderCodec;
use X402Press\Settings\SettingsRepository;

/**
 * Decides whether to serve, verify-then-serve, or reject with 402.
 *
 * The controller does not `echo` or `exit`; it only mutates a response
 * structure on $GLOBALS['__x402press_response']. The Plugin bootstrap is
 * responsible for echoing the body and exiting when `exited` is true.
 * This split keeps the controller unit-testable.
 */
final class PaywallController {

	public const BYPASS_HOOK = 'x402press_bypass_paywall';

	/**
	 * Fires after the paywall builds a {@see PaywallClientProfile} for this request
	 * (non-bypassed path with a resolved facilitator). Filter must return a
	 * PaywallClientProfile instance; other return types are ignored.
	 */
	public const CLIENT_PROFILE_FILTER = 'x402press_paywall_client_profile';

	/**
	 * Filters the plain-text excerpt fragment embedded in HTML 402 responses.
	 *
	 * @param string $excerpt  Fragment after built-in trimming (may be empty).
	 * @param int    $post_id  Queried post ID from the paywall request.
	 * @param array  $request  Full paywall request array.
	 */
	public const EXCERPT_TEXT_FILTER = 'x402press_paywall_excerpt_text';

	/**
	 * Filters the full HTML document returned for HTML 402 responses.
	 *
	 * @param string $html          Complete HTML document.
	 * @param array  $request       Full paywall request array.
	 * @param array  $requirements  Encoded x402 requirements (same as JSON path).
	 * @param string $price         Human-readable price.
	 * @param array  $body          Error payload merged into JSON path; same keys available here.
	 */
	public const HTML_402_BODY_FILTER = 'x402press_paywall_html_402_body';

	/** Nonce action for {@see self::PROBE_HEADER} — settings probe drops admin bypass when valid. */
	public const PROBE_NONCE_ACTION = 'x402press_paywall_probe';

	/** Request header carrying {@see self::PROBE_NONCE_ACTION} from the settings screen self-check. */
	public const PROBE_HEADER = 'X-Simple-X402-Probe';

	/** Response/request header carrying the opaque grant token after a successful payment. */
	public const GRANT_HEADER = 'X-Payment-Grant';

	/** Cookie name used to redeem the grant on subsequent requests from a browser. */
	public const GRANT_COOKIE = 'x402press_grant';

	/**
	 * Lazily-resolved facilitator client + the builder that wraps its profile.
	 * Deferred so requests that never reach the paywall path don't pay for
	 * filter firing or option reads.
	 */
	private ?Facilitator $facilitator_svc        = null;
	private ?PaymentRequirementsBuilder $builder = null;

	private ?PaymentSettlementNotifier $settlement_notifier;

	private PaymentProviderRegistry $providers;

	/** Set on the paywall enforcement path for Phase B; unused in Phase A beyond {@see self::CLIENT_PROFILE_FILTER}. */
	private ?PaywallClientProfile $client_profile = null;

	public function __construct(
		private readonly RuleResolver $rules,
		private readonly GrantStore $grants,
		private readonly SettingsRepository $settings,
		private readonly FacilitatorResolver $resolver,
		?PaymentSettlementNotifier $settlement_notifier = null,
		?PaymentProviderRegistry $providers = null,
	) {
		$this->settlement_notifier = $settlement_notifier;
		$this->providers           = $providers ?? new PaymentProviderRegistry();
	}

	private function settlement_notifier(): PaymentSettlementNotifier {
		return $this->settlement_notifier ??= new PaymentSettlementNotifier();
	}

	/**
	 * Resolve the active Facilitator from the selected connector, or null if
	 * none selected / resolution failed. The paywall is inert when there is
	 * no facilitator — pick one in Settings → x402press to activate it.
	 */
	private function facilitator(): ?Facilitator {
		if ( null !== $this->facilitator_svc ) {
			return $this->facilitator_svc;
		}
		$id = $this->settings->selected_facilitator_id();
		if ( '' === $id ) {
			return null;
		}
		$resolved = $this->resolver->resolve( $id );
		if ( null === $resolved ) {
			return null;
		}
		$this->facilitator_svc = $resolved;
		return $this->facilitator_svc;
	}

	private function builder( Facilitator $facilitator ): PaymentRequirementsBuilder {
		return $this->builder ??= new PaymentRequirementsBuilder( $facilitator->describe() );
	}

	/**
	 * @param array{
	 *   path:string,
	 *   method:string,
	 *   post_id:int,
	 *   singular?:bool,
	 *   headers:array<string,string>
	 * } $request Request details. `headers` always includes `Accept`, `Sec-Fetch-Mode`, and
	 *              `Sec-Fetch-Dest` when built by {@see \X402Press\Plugin::boot()} (empty string if absent).
	 */
	public function handle( array $request ): void {
		$this->client_profile = null;
		$paywall_probe        = $this->valid_paywall_probe_header( $request );
		$rule                 = $this->rules->resolve(
			array(
				'path'          => $request['path'],
				'method'        => $request['method'],
				'post_id'       => $request['post_id'],
				'singular'      => ! empty( $request['singular'] ),
				'paywall_probe' => $paywall_probe,
			)
		);
		if ( null === $rule ) {
			return;
		}

		// Administrators bypass by default so they can preview and manage
		// paywalled content. Extenders can widen or narrow this via the
		// `x402press_bypass_paywall` filter (e.g. let post editors through,
		// or force admins to pay for audit reasons). A valid probe header
		// forces the default to "do not bypass" so admins can self-test 402.
		$default_bypass = current_user_can( 'manage_options' );
		if ( $paywall_probe ) {
			$default_bypass = false;
		}
		if ( (bool) apply_filters( self::BYPASS_HOOK, $default_bypass, $request, $rule ) ) {
			return;
		}

		$facilitator = $this->facilitator();
		if ( null === $facilitator ) {
			// No facilitator selected or resolved — paywall is inert.
			return;
		}

		$grant_token = $this->extract_grant_token( $request );
		if ( '' !== $grant_token && $this->grants->redeem( $grant_token, $request['path'] ) ) {
			return;
		}

		// After grant short-circuit: classifier + filter only on paths that may 402 or verify/settle.
		$this->client_profile = $this->filtered_client_profile( $request );

		$requirements = $this->builder( $facilitator )->build(
			$this->settings->resolved_pay_to_address(),
			$rule['price'],
			home_url( $request['path'] ),
			$rule['description']
		);

		$signature_header = (string) ( $request['headers']['Payment-Signature'] ?? '' );
		if ( '' === $signature_header ) {
			$this->respond_402( $request, $requirements, $rule, array( 'error' => 'payment_required' ) );
			return;
		}

		$payload = X402HeaderCodec::decode( $signature_header );
		if ( null === $payload ) {
			$this->respond_402( $request, $requirements, $rule, array( 'error' => 'invalid_signature_header' ) );
			return;
		}

		$verify = $facilitator->verify( $requirements, $payload );
		if ( ! $verify['isValid'] ) {
			$this->respond_402(
				$request,
				$requirements,
				$rule,
				array(
					'error'  => 'verify_failed',
					'reason' => $verify['error'],
				)
			);
			return;
		}

		$settle = $facilitator->settle( $requirements, $payload );
		if ( ! $settle['success'] ) {
			$this->respond_402(
				$request,
				$requirements,
				$rule,
				array(
					'error'  => 'settle_failed',
					'reason' => $settle['error'],
				)
			);
			return;
		}

		$wallet = $this->extract_wallet( $payload );
		$token  = $this->grants->issue(
			$request['path'],
			$rule['ttl'],
			array(
				'transaction' => $settle['transaction'] ?? null,
				'wallet'      => $wallet,
			)
		);
		if ( '' !== $token ) {
			$this->emit_grant_response_headers( $token, $request['path'], $rule['ttl'] );
		}

		$this->settlement_notifier()->notify(
			array(
				'connector_id' => $this->settings->selected_facilitator_id(),
				'post_id'      => $request['post_id'],
				'path'         => $request['path'],
				'transaction'  => (string) ( $settle['transaction'] ?? '' ),
				'network'      => (string) ( $settle['network'] ?? '' ),
				'amount'       => $rule['price'],
				'resource_url' => home_url( $request['path'] ),
				'pay_to'       => (string) ( $requirements['payTo'] ?? '' ),
				'payer_wallet' => $wallet,
			)
		);
	}

	/**
	 * Emit a 402 response via the response buffer (JSON or HTML body per client profile).
	 *
	 * @param array $request      Paywall request (uses post_id for HTML excerpt).
	 * @param array $requirements Encoded x402 requirements.
	 * @param array $rule         Resolved rule with at least `price` (decimal USDC) and `ttl` (grant lifetime, seconds).
	 * @param array $body         Extra keys (e.g. error); must not use keys `requirements` or `price`.
	 */
	private function respond_402( array $request, array $requirements, array $rule, array $body ): void {
		nocache_headers();
		status_header( 402 );
		$GLOBALS['__x402press_response']['headers']['PAYMENT-REQUIRED'] = X402HeaderCodec::encode( $requirements );

		$price = (string) ( $rule['price'] ?? '' );
		if ( $this->should_serve_html_402_body() ) {
			$GLOBALS['__x402press_response']['headers']['Content-Type'] = 'text/html; charset=UTF-8';
			$GLOBALS['__x402press_response']['body']                    = $this->build_html_402_body( $request, $requirements, $rule, $body );
		} else {
			$GLOBALS['__x402press_response']['headers']['Content-Type'] = 'application/json';
			// Use array union (+), not array_merge: keys in $body must not overwrite requirements/price.
			$GLOBALS['__x402press_response']['body'] = wp_json_encode(
				array(
					'requirements' => $requirements,
					'price'        => $price,
				) + $body
			);
		}
		$GLOBALS['__x402press_response']['exited'] = true;
	}

	/**
	 * HTML vs JSON for blocked responses (see docs/paywall-ux-simplification.md).
	 *
	 * {@see PaywallClientProfile::$document_navigation_intent} (`Sec-Fetch-Mode: navigate` and
	 * `Sec-Fetch-Dest: document`) selects HTML; all other paywalled clients receive JSON
	 * (including JSON/`Accept`+json, `X-Requested-With: XMLHttpRequest`, and ambiguous signals).
	 */
	private function should_serve_html_402_body(): bool {
		$p = $this->client_profile;
		return null !== $p && $p->document_navigation_intent;
	}

	/**
	 * @param array<string,mixed> $rule Resolved rule (price + ttl).
	 * @param array<string,mixed> $body
	 */
	private function build_html_402_body( array $request, array $requirements, array $rule, array $body ): string {
		$price   = (string) ( $rule['price'] ?? '' );
		$ttl     = (int) ( $rule['ttl'] ?? 0 );
		$post_id = (int) ( $request['post_id'] ?? 0 );
		$excerpt = (string) apply_filters(
			self::EXCERPT_TEXT_FILTER,
			$this->paywall_excerpt_fragment( $post_id ),
			$post_id,
			$request
		);

		$site_name      = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$site_icon_url  = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 96 ) : '';
		$site_block     = $this->build_site_block( $site_name, $site_icon_url );

		$post_title     = $this->paywall_post_title( $post_id );
		$title_block    = '' !== $post_title
			? '<h2 class="x402press-title">' . esc_html( $post_title ) . '</h2>'
			: '';
		$excerpt_block  = '' !== $excerpt
			? '<p class="x402press-excerpt">' . esc_html( $excerpt ) . '</p>'
			: '';

		$error_code    = isset( $body['error'] ) ? (string) $body['error'] : '';
		$error_message = $this->error_message_for_visitor( $error_code );
		$error_line    = '' !== $error_message
			? '<p class="x402press-error" data-x402press-error="' . esc_attr( $error_code ) . '">'
				. esc_html( $error_message )
				. '</p>'
			: '';

		$providers_block = $this->payment_providers_block( $request, $requirements );
		$hint_line       = '' !== $providers_block
			? '' // The CTA replaces the developer-facing hint.
			: '<p class="x402press-hint">'
				. esc_html__( 'x402 payment instructions are in the PAYMENT-REQUIRED HTTP response header.', 'x402press' )
				. '</p>';

		$price_block = '<div class="x402press-price-card">'
			. '<span class="x402press-price-label">'
			. esc_html( $this->access_duration_label( $ttl ) )
			. '</span>'
			. '<span class="x402press-price-amount">'
			. esc_html(
				/* translators: %s: USDC price (decimal string). */
				sprintf( __( '%s USDC', 'x402press' ), $price )
			)
			. '</span>'
			. '</div>';

		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>'
			. esc_html__( 'Payment required', 'x402press' )
			. '</title>'
			. $this->html_402_styles()
			. '</head><body><main class="x402press-card">'
			. $site_block
			. '<div class="x402press-headline">'
			. '<p class="x402press-eyebrow">'
			. esc_html__( 'Payment required', 'x402press' )
			. '</p>'
			. $title_block
			. $excerpt_block
			. '</div>'
			. $price_block
			. $providers_block
			. $hint_line
			. $error_line
			. '</main></body></html>';

		return (string) apply_filters( self::HTML_402_BODY_FILTER, $html, $request, $requirements, $price, $body );
	}

	/**
	 * Site identity block at the top of the 402 page — the favicon (if the
	 * admin set a Site Icon in Customizer → Site Identity) plus the site
	 * name, both linking back to the home page so a paywalled visitor has
	 * an obvious way out.
	 */
	private function build_site_block( string $name, string $icon_url ): string {
		if ( '' === $name && '' === $icon_url ) {
			return '';
		}
		$home = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		$icon = '' !== $icon_url
			? '<img class="x402press-site-icon" src="' . esc_url( $icon_url ) . '" alt="" width="20" height="20">'
			: '';
		$name_html = '' !== $name
			? '<span class="x402press-site-name">' . esc_html( $name ) . '</span>'
			: '';
		$inner = $icon . $name_html;
		if ( '' !== $home ) {
			return '<a class="x402press-site" href="' . esc_url( $home ) . '">' . $inner . '</a>';
		}
		return '<div class="x402press-site">' . $inner . '</div>';
	}

	/**
	 * Human-readable copy for each 402 error_code, or '' to render no error
	 * line at all. The bare initial 402 (`payment_required`) deliberately
	 * returns an empty string — the eyebrow + price card already convey
	 * the state, and rendering "payment_required" looks like an error to
	 * a visitor who hasn't tried to pay yet.
	 *
	 * The raw `error_code` is still exposed via a `data-x402press-error`
	 * attribute on the rendered element so devtools / extensions can read
	 * it without us cluttering the visible UI.
	 */
	private function error_message_for_visitor( string $error_code ): string {
		switch ( $error_code ) {
			case '':
			case 'payment_required':
				// Initial 402 — not an error from the visitor's perspective.
				return '';
			case 'invalid_signature_header':
				return __(
					'The payment data sent by your wallet was invalid. Try again.',
					'x402press'
				);
			case 'verify_failed':
				return __(
					'Your payment couldn’t be verified. Check your wallet and try again.',
					'x402press'
				);
			case 'settle_failed':
				return __(
					'Your payment couldn’t be settled on-chain. Try again in a moment.',
					'x402press'
				);
			default:
				return __( 'Something went wrong with the payment. Try again.', 'x402press' );
		}
	}

	/**
	 * Human-readable description of how long a successful payment grants
	 * access to the same path. Honest about the fact that the grant is a
	 * time-bound transient — not "one-time access" — and rounds to whatever
	 * unit reads naturally for the rule's TTL.
	 *
	 * Falls back to the generic "Access" label if the rule didn't carry a
	 * positive TTL (which would mean the grant won't actually be issued —
	 * see {@see GrantStore::issue()}).
	 */
	private function access_duration_label( int $ttl_seconds ): string {
		if ( $ttl_seconds <= 0 ) {
			return __( 'Access', 'x402press' );
		}
		if ( $ttl_seconds < HOUR_IN_SECONDS ) {
			$minutes = max( 1, (int) round( $ttl_seconds / MINUTE_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: number of minutes the grant is valid for. */
				_n( 'Access for %d minute', 'Access for %d minutes', $minutes, 'x402press' ),
				$minutes
			);
		}
		if ( $ttl_seconds < DAY_IN_SECONDS ) {
			$hours = max( 1, (int) round( $ttl_seconds / HOUR_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: number of hours the grant is valid for. */
				_n( 'Access for %d hour', 'Access for %d hours', $hours, 'x402press' ),
				$hours
			);
		}
		$days = max( 1, (int) round( $ttl_seconds / DAY_IN_SECONDS ) );
		return sprintf(
			/* translators: %d: number of days the grant is valid for. */
			_n( 'Access for %d day', 'Access for %d days', $days, 'x402press' ),
			$days
		);
	}

	/**
	 * Fetch the post title for the paywall request, or '' when no post is
	 * matched (e.g. archive pages or routes without a queried object).
	 */
	private function paywall_post_title( int $post_id ): string {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! isset( $post->post_title ) ) {
			return '';
		}
		return trim( (string) $post->post_title );
	}

	/**
	 * Render the payment-provider buttons block. Each eligible provider gets a
	 * `<div data-x402press-provider="…">` slot plus a `<script src="…">` tag; the
	 * host loader walks the slots once registrations are in. Returns an empty
	 * string if no providers are eligible, so the controller falls back to the
	 * developer-facing PAYMENT-REQUIRED hint.
	 *
	 * @param array<string,mixed> $request
	 * @param array<string,mixed> $requirements
	 */
	private function payment_providers_block( array $request, array $requirements ): string {
		// `add_query_arg( array() )` returns the current request URI (path + query
		// string), so the retry hits the exact URL that 402'd — critical when the
		// site uses Plain permalinks and posts are addressed via `?p=<id>`.
		$resource_url = home_url( add_query_arg( array() ) );

		$providers = $this->providers->eligible(
			array(
				'requirements' => $requirements,
				'resource_url' => $resource_url,
				'request'      => $request,
			)
		);
		if ( empty( $providers ) ) {
			return '';
		}

		$context        = array(
			'requirements' => $requirements,
			'resourceUrl'  => $resource_url,
			'providers'    => array(),
		);
		$slots          = '';
		$script_tags    = '';
		$seen_providers = array();
		foreach ( $providers as $provider ) {
			$id = $provider['id'];
			if ( isset( $seen_providers[ $id ] ) ) {
				continue;
			}
			$seen_providers[ $id ] = true;

			$context['providers'][ $id ] = array(
				'config' => $provider['config'],
			);
			$slots                      .= '<div data-x402press-provider="' . esc_attr( $id ) . '"></div>';
			$script_tags                .= '<script defer src="' . esc_url( $provider['script_url'] ) . '"></script>';
		}

		$context_json = wp_json_encode(
			$context,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);
		if ( false === $context_json ) {
			return '';
		}

		$host_url = plugins_url( 'src/Payment/loader.js', X402PRESS_FILE );

		return '<div class="x402press-checkout">'
			. '<div class="x402press-providers">' . $slots . '</div>'
			. '<script type="application/json" id="x402press-payment-context">' . $context_json . '</script>'
			. '<script defer src="' . esc_url( $host_url ) . '"></script>'
			. $script_tags
			. '</div>';
	}

	private function html_402_styles(): string {
		// Muted, deliberately quiet palette. Greyscale only — site-icon
		// colour is the one visual focal point so the paywall page never
		// fights with the host site's branding.
		return <<<'CSS'
<style>
	:root {
		--x402press-bg: #f5f5f4;
		--x402press-surface: #ffffff;
		--x402press-border: #e7e5e4;
		--x402press-text: #1c1917;
		--x402press-text-muted: #57534e;
		--x402press-text-faint: #a8a29e;
		--x402press-primary: #1c1917;
		--x402press-primary-text: #fafaf9;
	}
	* { box-sizing: border-box; }
	html, body { margin: 0; padding: 0; }
	body {
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
		font-size: 15px;
		line-height: 1.55;
		color: var(--x402press-text);
		background: var(--x402press-bg);
		min-height: 100vh;
		display: flex;
		justify-content: center;
		padding: 48px 16px;
	}
	.x402press-card {
		width: 100%;
		max-width: 440px;
		background: var(--x402press-surface);
		border: 1px solid var(--x402press-border);
		border-radius: 12px;
		padding: 28px 28px 24px;
		box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
	}
	.x402press-site {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 13px;
		font-weight: 500;
		color: var(--x402press-text-muted);
		text-decoration: none;
		margin-bottom: 24px;
	}
	a.x402press-site:hover { color: var(--x402press-text); }
	.x402press-site-icon {
		width: 20px;
		height: 20px;
		border-radius: 4px;
		display: block;
	}
	.x402press-headline { margin: 0 0 20px; }
	.x402press-eyebrow {
		font-size: 11px;
		font-weight: 600;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--x402press-text-faint);
		margin: 0 0 8px;
	}
	.x402press-title {
		font-size: 22px;
		line-height: 1.3;
		font-weight: 600;
		margin: 0 0 12px;
		color: var(--x402press-text);
	}
	.x402press-excerpt {
		color: var(--x402press-text-muted);
		margin: 0;
		display: -webkit-box;
		-webkit-line-clamp: 3;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}
	.x402press-price-card {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		padding: 14px 16px;
		border: 1px solid var(--x402press-border);
		border-radius: 8px;
		margin-bottom: 16px;
		background: var(--x402press-bg);
	}
	.x402press-price-label {
		font-size: 13px;
		color: var(--x402press-text-muted);
	}
	.x402press-price-amount {
		font-size: 16px;
		font-weight: 600;
		color: var(--x402press-text);
		font-variant-numeric: tabular-nums;
	}
	.x402press-checkout { margin: 0; }
	.x402press-providers {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	/* Each provider slot can render multiple children (e.g. the EVM-wallet
	   slot stacks detected wallets + a "or get a wallet" divider + install
	   links). Reproduce the parent gap so spacing stays consistent
	   regardless of slot child count. */
	.x402press-providers > div {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	/* Social-login-style list row: each wallet/provider is one button with
	   its icon at the left and its name to the right. Equal weight across
	   providers — no "primary" CTA so detected EIP-6963 wallets and the
	   built-in providers all read the same. */
	.x402press-pay-button {
		display: flex;
		align-items: center;
		gap: 12px;
		width: 100%;
		font: inherit;
		font-size: 14px;
		font-weight: 500;
		padding: 12px 14px;
		border: 1px solid var(--x402press-border);
		border-radius: 8px;
		background: var(--x402press-surface);
		color: var(--x402press-text);
		text-align: left;
		cursor: pointer;
		transition: border-color 0.15s ease, background 0.15s ease;
	}
	.x402press-pay-button:hover {
		border-color: var(--x402press-text-faint);
		background: var(--x402press-bg);
	}
	.x402press-pay-button:active { background: var(--x402press-border); }
	.x402press-pay-button:disabled { opacity: 0.5; cursor: not-allowed; }
	.x402press-pay-icon {
		display: inline-flex;
		flex-shrink: 0;
		width: 24px;
		height: 24px;
	}
	.x402press-pay-icon svg, .x402press-pay-icon img {
		width: 100%;
		height: 100%;
		display: block;
		border-radius: 6px;
	}
	.x402press-pay-label { flex: 1; }
	/* Trailing meta slot — currently only used by the install-link variant
	   to render an "external link" arrow, but free for any provider that
	   wants a small trailing affordance (e.g. "scan QR" badge). */
	.x402press-pay-meta {
		flex-shrink: 0;
		color: var(--x402press-text-faint);
		font-size: 13px;
	}
	/* Install-link variant — outbound link, not a payment action. Visually
	   secondary: tighter padding, smaller font, muted label colour. Icon
	   is the wallet's real official SVG (bundled with the plugin), not a
	   placeholder. text-decoration reset keeps anchor styles from leaking
	   through. */
	.x402press-pay-button--install {
		padding: 10px 14px;
		font-size: 13px;
		text-decoration: none;
	}
	.x402press-pay-button--install .x402press-pay-label {
		color: var(--x402press-text-muted);
	}
	.x402press-pay-button--install .x402press-pay-icon {
		width: 20px;
		height: 20px;
	}
	/* Section divider rendered above the install links — only appears when
	   the EvmWallet provider has at least one suggested wallet that wasn't
	   announced via EIP-6963. The flanking lines are pseudo-elements so
	   the label sits centred. */
	.x402press-section-divider {
		display: flex;
		align-items: center;
		gap: 12px;
		color: var(--x402press-text-faint);
		font-size: 12px;
		text-transform: uppercase;
		letter-spacing: 0.06em;
		margin: 4px 0;
	}
	.x402press-section-divider::before,
	.x402press-section-divider::after {
		content: '';
		flex: 1;
		height: 1px;
		background: var(--x402press-border);
	}
	.x402press-status {
		color: var(--x402press-text-muted);
		font-size: 13px;
		margin: 12px 0 0;
		text-align: center;
	}
	.x402press-hint {
		color: var(--x402press-text-muted);
		font-size: 12px;
		margin: 16px 0 0;
	}
	.x402press-error {
		color: var(--x402press-text);
		font-size: 13px;
		margin: 16px 0 0;
		padding: 12px 14px;
		border: 1px solid var(--x402press-border);
		border-radius: 8px;
		background: var(--x402press-bg);
	}
</style>
CSS;
	}

	private function paywall_excerpt_fragment( int $post_id ): string {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return '';
		}
		$manual = isset( $post->post_excerpt ) ? trim( (string) $post->post_excerpt ) : '';
		if ( '' !== $manual ) {
			return $manual;
		}
		$content = isset( $post->post_content ) ? (string) $post->post_content : '';
		if ( '' === $content ) {
			return '';
		}
		$stripped = wp_strip_all_tags( $content );
		if ( function_exists( 'wp_trim_words' ) ) {
			return (string) wp_trim_words( $stripped, 55, '…' );
		}
		$words = preg_split( '/\s+/u', $stripped, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) || count( $words ) <= 55 ) {
			return $stripped;
		}
		return implode( ' ', array_slice( $words, 0, 55 ) ) . '…';
	}

	/**
	 * Extract the opaque grant token from either the request header (CLI /
	 * scripts that capture the response header explicitly) or the
	 * `x402press_grant` cookie (browsers, sent automatically once issued).
	 *
	 * @param array{headers:array<string,string>} $request
	 */
	private function extract_grant_token( array $request ): string {
		$header = (string) ( $request['headers'][ self::GRANT_HEADER ] ?? '' );
		if ( '' !== $header ) {
			return $header;
		}
		// $_COOKIE is the only authoritative source — Plugin::collect_headers
		// doesn't fold cookies into the request shape (and shouldn't: cookies
		// have their own semantics).
		$raw = $_COOKIE[ self::GRANT_COOKIE ] ?? '';
		return is_string( $raw ) ? (string) wp_unslash( $raw ) : '';
	}

	/**
	 * Stage the response header + Set-Cookie for a freshly-issued grant on
	 * the success-path response struct so {@see \X402Press\Plugin} can
	 * emit them before WordPress renders the page.
	 *
	 * The cookie is `Secure; HttpOnly; SameSite=Strict` and `Max-Age` matches
	 * the rule TTL so the bypass disappears together with the server-side
	 * transient. `Path` is scoped to the paid URL so an unrelated path on
	 * the same site can't accidentally redeem it.
	 */
	private function emit_grant_response_headers( string $token, string $path, int $ttl ): void {
		$cookie_path = $this->sanitize_cookie_path( '' !== $path ? $path : '/' );
		$cookie      = sprintf(
			'%s=%s; Max-Age=%d; Path=%s; Secure; HttpOnly; SameSite=Strict',
			self::GRANT_COOKIE,
			rawurlencode( $token ),
			max( $ttl, 0 ),
			$cookie_path
		);
		$GLOBALS['__x402press_response']['success_headers'][] = self::GRANT_HEADER . ': ' . $token;
		$GLOBALS['__x402press_response']['success_headers'][] = 'Set-Cookie: ' . $cookie;
	}

	private function sanitize_cookie_path( string $path ): string {
		$path = '' !== $path && str_starts_with( $path, '/' ) ? $path : '/';
		return (string) preg_replace_callback(
			'/[\x00-\x20\x7f;]/',
			static fn ( array $cookie_path_char ): string => rawurlencode( $cookie_path_char[0] ),
			$path
		);
	}

	/**
	 * Pull the paying wallet from a decoded PAYMENT-SIGNATURE payload.
	 */
	private function extract_wallet( array $payload ): string {
		return (string) (
			$payload['payload']['authorization']['from']
			?? $payload['payload']['from']
			?? ''
		);
	}

	/**
	 * @param array{headers:array<string,string>} $request
	 */
	private function valid_paywall_probe_header( array $request ): bool {
		$token = (string) ( $request['headers'][ self::PROBE_HEADER ] ?? '' );
		if ( '' === $token ) {
			return false;
		}
		return (bool) wp_verify_nonce( $token, self::PROBE_NONCE_ACTION );
	}

	/**
	 * @param array{headers:array<string,string>} $request
	 */
	private function filtered_client_profile( array $request ): PaywallClientProfile {
		$h        = $request['headers'];
		$base     = PaywallClientProfile::classify(
			(string) ( $h['User-Agent'] ?? '' ),
			(string) ( $h['Accept'] ?? '' ),
			(string) ( $h['Sec-Fetch-Mode'] ?? '' ),
			(string) ( $h['Sec-Fetch-Dest'] ?? '' ),
			array_key_exists( 'X-Requested-With', $h ) ? (string) $h['X-Requested-With'] : null,
		);
		$filtered = apply_filters( self::CLIENT_PROFILE_FILTER, $base, $request );
		return $filtered instanceof PaywallClientProfile ? $filtered : $base;
	}
}
