<?php
/**
 * Orchestrates the paywall flow on template_redirect.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Http;

use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\FacilitatorResolver;
use SimpleX402\Payment\PaymentProviderRegistry;
use SimpleX402\Services\GrantStore;
use SimpleX402\Services\PaywallClientProfile;
use SimpleX402\Services\PaymentRequirementsBuilder;
use SimpleX402\Services\PaymentSettlementNotifier;
use SimpleX402\Services\RuleResolver;
use SimpleX402\Services\X402HeaderCodec;
use SimpleX402\Settings\SettingsRepository;

/**
 * Decides whether to serve, verify-then-serve, or reject with 402.
 *
 * The controller does not `echo` or `exit`; it only mutates a response
 * structure on $GLOBALS['__sx402_response']. The Plugin bootstrap is
 * responsible for echoing the body and exiting when `exited` is true.
 * This split keeps the controller unit-testable.
 */
final class PaywallController {

	public const BYPASS_HOOK = 'simple_x402_bypass_paywall';

	/**
	 * Fires after the paywall builds a {@see PaywallClientProfile} for this request
	 * (non-bypassed path with a resolved facilitator). Filter must return a
	 * PaywallClientProfile instance; other return types are ignored.
	 */
	public const CLIENT_PROFILE_FILTER = 'simple_x402_paywall_client_profile';

	/**
	 * Filters the plain-text excerpt fragment embedded in HTML 402 responses.
	 *
	 * @param string $excerpt  Fragment after built-in trimming (may be empty).
	 * @param int    $post_id  Queried post ID from the paywall request.
	 * @param array  $request  Full paywall request array.
	 */
	public const EXCERPT_TEXT_FILTER = 'simple_x402_paywall_excerpt_text';

	/**
	 * Filters the full HTML document returned for HTML 402 responses.
	 *
	 * @param string $html          Complete HTML document.
	 * @param array  $request       Full paywall request array.
	 * @param array  $requirements  Encoded x402 requirements (same as JSON path).
	 * @param string $price         Human-readable price.
	 * @param array  $body          Error payload merged into JSON path; same keys available here.
	 */
	public const HTML_402_BODY_FILTER = 'simple_x402_paywall_html_402_body';

	/** Nonce action for {@see self::PROBE_HEADER} — settings probe drops admin bypass when valid. */
	public const PROBE_NONCE_ACTION = 'simple_x402_paywall_probe';

	/** Request header carrying {@see self::PROBE_NONCE_ACTION} from the settings screen self-check. */
	public const PROBE_HEADER = 'X-Simple-X402-Probe';

	/** Response/request header carrying the opaque grant token after a successful payment. */
	public const GRANT_HEADER = 'X-Payment-Grant';

	/** Cookie name used to redeem the grant on subsequent requests from a browser. */
	public const GRANT_COOKIE = 'sx402_grant';

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
	 * no facilitator — pick one in Settings → Simple x402 to activate it.
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
	 *              `Sec-Fetch-Dest` when built by {@see \SimpleX402\Plugin::boot()} (empty string if absent).
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
		// `simple_x402_bypass_paywall` filter (e.g. let post editors through,
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
			$this->respond_402( $request, $requirements, $rule['price'], array( 'error' => 'payment_required' ) );
			return;
		}

		$payload = X402HeaderCodec::decode( $signature_header );
		if ( null === $payload ) {
			$this->respond_402( $request, $requirements, $rule['price'], array( 'error' => 'invalid_signature_header' ) );
			return;
		}

		$verify = $facilitator->verify( $requirements, $payload );
		if ( ! $verify['isValid'] ) {
			$this->respond_402(
				$request,
				$requirements,
				$rule['price'],
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
				$rule['price'],
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
	 * @param array  $request      Paywall request (uses post_id for HTML excerpt).
	 * @param string $price        Decimal USDC amount (e.g. "0.01") for clients that expect a human-readable price alongside `requirements.maxAmountRequired`.
	 * @param array  $body         Extra keys (e.g. error); must not use keys `requirements` or `price`.
	 */
	private function respond_402( array $request, array $requirements, string $price, array $body ): void {
		nocache_headers();
		status_header( 402 );
		$GLOBALS['__sx402_response']['headers']['PAYMENT-REQUIRED'] = X402HeaderCodec::encode( $requirements );

		if ( $this->should_serve_html_402_body() ) {
			$GLOBALS['__sx402_response']['headers']['Content-Type'] = 'text/html; charset=UTF-8';
			$GLOBALS['__sx402_response']['body']                    = $this->build_html_402_body( $request, $requirements, $price, $body );
		} else {
			$GLOBALS['__sx402_response']['headers']['Content-Type'] = 'application/json';
			// Use array union (+), not array_merge: keys in $body must not overwrite requirements/price.
			$GLOBALS['__sx402_response']['body'] = wp_json_encode(
				array(
					'requirements' => $requirements,
					'price'        => $price,
				) + $body
			);
		}
		$GLOBALS['__sx402_response']['exited'] = true;
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
	 * @param array<string,mixed> $body
	 */
	private function build_html_402_body( array $request, array $requirements, string $price, array $body ): string {
		$post_id = (int) ( $request['post_id'] ?? 0 );
		$excerpt = (string) apply_filters(
			self::EXCERPT_TEXT_FILTER,
			$this->paywall_excerpt_fragment( $post_id ),
			$post_id,
			$request
		);

		$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$site = '' !== $site ? '<p class="sx402-site">' . esc_html( $site ) . '</p>' : '';

		$excerpt_block = '' !== $excerpt
			? '<p class="sx402-excerpt">' . esc_html( $excerpt ) . '</p>'
			: '';

		$error_code = isset( $body['error'] ) ? (string) $body['error'] : '';
		$error_line = '' !== $error_code
			? '<p class="sx402-error"><code>' . esc_html( $error_code ) . '</code></p>'
			: '';

		$providers_block = $this->payment_providers_block( $request, $requirements );
		$hint_line       = '' !== $providers_block
			? '' // The CTA replaces the developer-facing hint.
			: '<p class="sx402-hint">'
				. esc_html__( 'x402 payment instructions are in the PAYMENT-REQUIRED HTTP response header.', 'simple-x402' )
				. '</p>';

		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>'
			. esc_html__( 'Payment required', 'simple-x402' )
			. '</title>'
			. $this->html_402_styles()
			. '</head><body><main><h1>'
			. esc_html__( 'Payment required', 'simple-x402' )
			. '</h1>'
			. $site
			. $excerpt_block
			. '<p class="sx402-price">'
			. esc_html(
				/* translators: %s: USDC price (decimal string). */
				sprintf( __( 'Price: %s USDC', 'simple-x402' ), $price )
			)
			. '</p>'
			. $providers_block
			. $hint_line
			. $error_line
			. '</main></body></html>';

		return (string) apply_filters( self::HTML_402_BODY_FILTER, $html, $request, $requirements, $price, $body );
	}

	/**
	 * Render the payment-provider buttons block. Each eligible provider gets a
	 * `<div data-sx402-provider="…">` slot plus a `<script src="…">` tag; the
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
			$slots                      .= '<div data-sx402-provider="' . esc_attr( $id ) . '"></div>';
			$script_tags                .= '<script defer src="' . esc_url( $provider['script_url'] ) . '"></script>';
		}

		$context_json = wp_json_encode(
			$context,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);
		if ( false === $context_json ) {
			return '';
		}

		$status   = esc_html__( 'Choose a payment method to continue.', 'simple-x402' );
		$host_url = plugins_url( 'assets/payment/host.js', SIMPLE_X402_FILE );

		return '<div class="sx402-checkout">'
			. '<div class="sx402-providers">' . $slots . '</div>'
			. '<p id="sx402-status" class="sx402-status">' . $status . '</p>'
			. '<script type="application/json" id="sx402-payment-context">' . $context_json . '</script>'
			. '<script defer src="' . esc_url( $host_url ) . '"></script>'
			. $script_tags
			. '</div>';
	}

	private function html_402_styles(): string {
		return <<<'CSS'
<style>
	body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; max-width: 540px; margin: 4rem auto; padding: 0 1rem; color: #1d1d1f; }
	h1 { font-size: 22px; }
	.sx402-site { color: #6e6e73; margin: 4px 0 16px; font-size: 14px; }
	.sx402-excerpt { color: #444; margin: 12px 0 20px; }
	.sx402-price { font-size: 15px; margin: 16px 0; }
	.sx402-checkout { margin: 20px 0; }
	.sx402-providers { display: flex; flex-direction: column; gap: 8px; }
	.sx402-pay-button { font: inherit; font-size: 15px; padding: 10px 20px; border: 0; background: linear-gradient(135deg, #ff8a4c 0%, #ff5c3a 100%); color: white; border-radius: 8px; cursor: pointer; }
	.sx402-pay-button:disabled { opacity: 0.6; cursor: not-allowed; }
	.sx402-status { color: #6e6e73; font-size: 13px; margin: 10px 0 0; }
	.sx402-hint, .sx402-error { color: #6e6e73; font-size: 13px; }
	.sx402-error code { background: #fef2f2; padding: 2px 6px; border-radius: 4px; }
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
	 * `sx402_grant` cookie (browsers, sent automatically once issued).
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
	 * the success-path response struct so {@see \SimpleX402\Plugin} can
	 * emit them before WordPress renders the page.
	 *
	 * The cookie is `Secure; HttpOnly; SameSite=Strict` and `Max-Age` matches
	 * the rule TTL so the bypass disappears together with the server-side
	 * transient. `Path` is scoped to the paid URL so an unrelated path on
	 * the same site can't accidentally redeem it.
	 */
	private function emit_grant_response_headers( string $token, string $path, int $ttl ): void {
		$cookie_path = '' !== $path ? $path : '/';
		$cookie      = sprintf(
			'%s=%s; Max-Age=%d; Path=%s; Secure; HttpOnly; SameSite=Strict',
			self::GRANT_COOKIE,
			rawurlencode( $token ),
			max( $ttl, 0 ),
			$cookie_path
		);
		$GLOBALS['__sx402_response']['success_headers'][] = self::GRANT_HEADER . ': ' . $token;
		$GLOBALS['__sx402_response']['success_headers'][] = 'Set-Cookie: ' . $cookie;
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
