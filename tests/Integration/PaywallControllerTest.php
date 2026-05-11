<?php
declare(strict_types=1);

namespace X402Press\Tests\Integration;

use PHPUnit\Framework\TestCase;
use X402Press\Connectors\ConnectorRegistry;
use X402Press\Facilitator\FacilitatorResolver;
use X402Press\Http\PaywallController;
use X402Press\Services\FacilitatorHooks;
use X402Press\Services\FacilitatorProfile;
use X402Press\Services\GrantStore;
use X402Press\Services\PaywallClientProfile;
use X402Press\Services\RuleResolver;
use X402Press\Services\X402HeaderCodec;
use X402Press\Settings\SettingsRepository;

final class PaywallControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402press_current_user_id'] = 0;
		$GLOBALS['__x402press_filters']          = array();
		$GLOBALS['__x402press_actions']          = array();
		$GLOBALS['__x402press_transients']       = array();
		$GLOBALS['__x402press_options']    = array(
			'x402press_settings' => array(
				'selected_facilitator_id' => 'x402press_test',
				'facilitators'            => array(
					'x402press_test' => array( 'wallet_address' => '0xreceiver' ),
				),
				'default_price'           => '0.01',
				'paywall_audience'        => SettingsRepository::AUDIENCE_BOTS,
			),
		);
		$GLOBALS['__x402press_posts']    = array();
		$GLOBALS['__x402press_bloginfo'] = array( 'name' => 'Example Site' );
		// Default: one x402_facilitator connector, resolved via the filter.
		$GLOBALS['__x402press_connectors'] = array(
			'x402press_test' => array( 'type' => ConnectorRegistry::FACILITATOR_TYPE ),
		);
		add_filter(
			FacilitatorResolver::FILTER,
			static fn ( $existing, $id ) => 'x402press_test' === $id && null === $existing
				? new \X402Press\Services\X402FacilitatorClient( FacilitatorProfile::for_test() )
				: $existing,
			10,
			2
		);
		$GLOBALS['__x402press_response']   = array(
			'status'          => 200,
			'headers'         => array(),
			'success_headers' => array(),
			'body'            => null,
			'exited'          => false,
		);
		$_COOKIE                       = array();
		$GLOBALS['__x402press_http']            = null;
		$GLOBALS['__x402press_http_next']       = null;
		$GLOBALS['__x402press_http_queue']      = array();
		$GLOBALS['__x402press_current_user_caps'] = array();
	}

	private function controller( ?SettingsRepository $settings = null ): PaywallController {
		return new PaywallController(
			new RuleResolver(),
			new GrantStore(),
			$settings ?? new SettingsRepository(),
			new FacilitatorResolver( new ConnectorRegistry() ),
		);
	}

	/**
	 * Assert 402 JSON body includes requirements and the expected human-readable price (from the resolved rule).
	 */
	private function assert_402_json_body_has_price_and_requirements( string $expected_price ): void {
		$ct = (string) ( $GLOBALS['__x402press_response']['headers']['Content-Type'] ?? '' );
		$this->assertStringContainsString( 'application/json', $ct );
		$body = json_decode( (string) $GLOBALS['__x402press_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( $expected_price, $body['price'] );
		$this->assertArrayHasKey( 'requirements', $body );
	}

	public function test_passes_through_when_no_rule_matches(): void {
		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);
		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_passes_singular_flag_to_rule_filter(): void {
		$seen = null;
		add_filter(
			'x402press_rule_for_request',
			static function ( $rule, $ctx ) use ( &$seen ) {
				$seen = $ctx;
				return null;
			},
			10,
			2
		);
		$this->controller()->handle(
			array(
				'path'     => '/p',
				'method'   => 'GET',
				'post_id'  => 1,
				'singular' => true,
				'headers'  => array(),
			)
		);
		$this->assertIsArray( $seen );
		$this->assertTrue( $seen['singular'] );
		$this->assertSame( 1, $seen['post_id'] );
		$this->assertFalse( $seen['paywall_probe'] );
	}

	public function test_client_profile_filter_runs_with_classified_headers_on_paywall_path(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$captured = null;
		add_filter(
			PaywallController::CLIENT_PROFILE_FILTER,
			function ( PaywallClientProfile $profile, array $request ) use ( &$captured ) {
				$captured = $profile;
				$this->assertArrayHasKey( 'Accept', $request['headers'] );
				$this->assertArrayHasKey( 'Sec-Fetch-Mode', $request['headers'] );
				$this->assertArrayHasKey( 'Sec-Fetch-Dest', $request['headers'] );
				return $profile;
			},
			10,
			2
		);

		$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'     => $googlebot,
					'Accept'         => 'application/json',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);

		$this->assertInstanceOf( PaywallClientProfile::class, $captured );
		$this->assertTrue( $captured->is_bot );
		$this->assertTrue( $captured->document_navigation_intent );
		$this->assertTrue( $captured->json_accept_intent );
		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$ct = (string) ( $GLOBALS['__x402press_response']['headers']['Content-Type'] ?? '' );
		$this->assertStringContainsString( 'text/html', $ct, 'Document navigation intent overrides JSON Accept for 402 body shape.' );
	}

	public function test_responds_402_when_rule_matches_and_no_signature(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assertArrayHasKey( 'PAYMENT-REQUIRED', $GLOBALS['__x402press_response']['headers'] );
		$decoded = X402HeaderCodec::decode( $GLOBALS['__x402press_response']['headers']['PAYMENT-REQUIRED'] );
		$this->assertSame( '0xreceiver', $decoded['payTo'] );
		$this->assertSame( '10000', $decoded['maxAmountRequired'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.01' );
		$this->assertTrue( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_administrator_bypasses_paywall(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_current_user_caps'] = array( 'manage_options' );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_valid_paywall_probe_header_overrides_admin_bypass(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_current_user_caps'] = array( 'manage_options' );
		$GLOBALS['__x402press_current_user_id']   = 1;
		$nonce                                  = wp_create_nonce( PaywallController::PROBE_NONCE_ACTION );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( PaywallController::PROBE_HEADER => $nonce ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assertTrue( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_invalid_probe_nonce_admin_still_bypasses(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_current_user_caps'] = array( 'manage_options' );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( PaywallController::PROBE_HEADER => 'not-a-valid-nonce' ),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_bypass_filter_can_widen_to_non_admin(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		add_filter( 'x402press_bypass_paywall', static fn () => true, 10, 3 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_bypass_filter_can_override_admin_default(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_current_user_caps'] = array( 'manage_options' );
		add_filter( 'x402press_bypass_paywall', static fn () => false, 10, 3 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assertTrue( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_bypass_filter_receives_request_and_rule(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$seen = null;
		add_filter(
			'x402press_bypass_paywall',
			static function ( $bypass, $request, $rule ) use ( &$seen ) {
				$seen = array( 'request' => $request, 'rule' => $rule );
				return $bypass;
			},
			10,
			3
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 42,
				'headers' => array(),
			)
		);

		$this->assertIsArray( $seen );
		$this->assertSame( '/foo', $seen['request']['path'] );
		$this->assertSame( 42, $seen['request']['post_id'] );
		$this->assertSame( '0.01', $seen['rule']['price'] );
	}

	public function test_allows_request_with_live_grant_via_header_token(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$token = ( new GrantStore() )->issue( '/foo', 60, array() );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( PaywallController::GRANT_HEADER => $token ),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_allows_request_with_live_grant_via_cookie(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$token                                       = ( new GrantStore() )->issue( '/foo', 60, array() );
		$_COOKIE[ PaywallController::GRANT_COOKIE ] = $token;

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_wallet_address_header_alone_no_longer_bypasses(): void {
		// Pre-fix: sending X-Wallet-Address with the paying wallet was enough
		// to redeem the grant for that path. Wallet addresses are public, so
		// that bypass must not work anymore.
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		( new GrantStore() )->issue( '/foo', 60, array( 'wallet' => '0xbuyer' ) );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'X-Wallet-Address' => '0xbuyer' ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
	}

	public function test_token_for_one_path_does_not_redeem_against_another(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$token = ( new GrantStore() )->issue( '/foo', 60, array() );

		$this->controller()->handle(
			array(
				'path'    => '/bar',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( PaywallController::GRANT_HEADER => $token ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
	}

	public function test_client_profile_filter_not_invoked_when_grant_short_circuits(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$token = ( new GrantStore() )->issue( '/foo', 60, array() );

		$filter_runs = 0;
		add_filter(
			PaywallController::CLIENT_PROFILE_FILTER,
			static function ( PaywallClientProfile $profile ) use ( &$filter_runs ) {
				++$filter_runs;
				return $profile;
			},
			10,
			2
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( PaywallController::GRANT_HEADER => $token ),
			)
		);

		$this->assertSame( 0, $filter_runs, 'Classifier and filter should not run when an existing grant bypasses enforcement.' );
		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
	}

	public function test_requirements_use_managed_pool_pay_to_when_filter_returns_address(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		add_filter(
			FacilitatorHooks::MANAGED_POOL_PAY_TO,
			static fn ( string $p, string $id ): string => 'x402press_test' === $id
				? '0x1111111111111111111111111111111111111111'
				: $p,
			10,
			2
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.01' );
		$body = json_decode( (string) $GLOBALS['__x402press_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( '0x1111111111111111111111111111111111111111', $body['requirements']['payTo'] );
	}

	public function test_verifies_and_settles_then_emits_grant_token_and_cookie(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01', 'ttl' => 600 ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);

		$GLOBALS['__x402press_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":true}',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":true,"transaction":"0xdead"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'Payment-Signature' => $payload ),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );

		$success_headers = $GLOBALS['__x402press_response']['success_headers'];
		$grant_line      = self::find_header_line( $success_headers, PaywallController::GRANT_HEADER . ': ' );
		$cookie_line     = self::find_header_line( $success_headers, 'Set-Cookie: ' . PaywallController::GRANT_COOKIE . '=' );
		$this->assertNotNull( $grant_line, 'X-Payment-Grant header must be staged on the success path.' );
		$this->assertNotNull( $cookie_line, 'x402press_grant cookie must be staged on the success path.' );

		// Cookie must carry the security-critical attributes — Secure, HttpOnly,
		// SameSite=Strict — and a Max-Age that matches the rule TTL.
		$this->assertStringContainsString( 'Secure', $cookie_line );
		$this->assertStringContainsString( 'HttpOnly', $cookie_line );
		$this->assertStringContainsString( 'SameSite=Strict', $cookie_line );
		$this->assertStringContainsString( 'Max-Age=600', $cookie_line );
		$this->assertStringContainsString( 'Path=/foo', $cookie_line );

		// The token from the response header must redeem against the same path.
		$token = substr( $grant_line, strlen( PaywallController::GRANT_HEADER . ': ' ) );
		$this->assertTrue( ( new GrantStore() )->redeem( $token, '/foo' ) );
	}

	public function test_grant_cookie_path_encodes_attribute_separators(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01', 'ttl' => 600 ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);

		$GLOBALS['__x402press_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":true}',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":true,"transaction":"0xdead"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo; Domain=example.test',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'Payment-Signature' => $payload ),
			)
		);

		$cookie_line = self::find_header_line(
			$GLOBALS['__x402press_response']['success_headers'],
			'Set-Cookie: ' . PaywallController::GRANT_COOKIE . '='
		);

		$this->assertNotNull( $cookie_line );
		$this->assertStringContainsString( 'Path=/foo%3B%20Domain=example.test', $cookie_line );
		$this->assertStringNotContainsString( '; Domain=example.test', $cookie_line );
	}

	/**
	 * @param list<string> $lines
	 */
	private static function find_header_line( array $lines, string $prefix ): ?string {
		foreach ( $lines as $line ) {
			if ( str_starts_with( (string) $line, $prefix ) ) {
				return (string) $line;
			}
		}
		return null;
	}

	public function test_settle_success_fires_payment_settled_action(): void {
		$captured = array();
		add_action(
			FacilitatorHooks::PAYMENT_SETTLED,
			static function ( array $ctx ) use ( &$captured ): void {
				$captured[] = $ctx;
			}
		);
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.02', 'ttl' => 60 ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);

		$GLOBALS['__x402press_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":true}',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":true,"transaction":"0xabc123","network":"base-sepolia"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/paid-post',
				'method'  => 'GET',
				'post_id' => 42,
				'headers' => array( 'Payment-Signature' => $payload ),
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( '0xabc123', $captured[0]['transaction'] );
		$this->assertSame( 42, $captured[0]['post_id'] );
		$this->assertSame( '0.02', $captured[0]['amount'] );
		$this->assertSame( 'x402press_test', $captured[0]['connector_id'] );
		$this->assertSame( '0xreceiver', $captured[0]['pay_to'] );
	}

	public function test_verify_failure_responds_402(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array(),
			)
		);

		$GLOBALS['__x402press_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":false,"invalidReason":"bad_sig"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'Payment-Signature' => $payload ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.01' );
		$body = json_decode( (string) $GLOBALS['__x402press_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'verify_failed', $body['error'] );
		$this->assertTrue( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_invalid_signature_header_responds_402_with_price(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'Payment-Signature' => 'not-valid-base64!!!' ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.01' );
		$body = json_decode( (string) $GLOBALS['__x402press_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'invalid_signature_header', $body['error'] );
		$this->assertTrue( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_settle_failure_responds_402_with_price(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.25' ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);

		$GLOBALS['__x402press_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":true}',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":false,"errorReason":"on_chain_revert"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'Payment-Signature' => $payload ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.25' );
		$body = json_decode( (string) $GLOBALS['__x402press_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'settle_failed', $body['error'] );
		$this->assertTrue( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_bot_json_accept_without_document_navigation_serves_json_402(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'     => $googlebot,
					'Accept'         => 'application/json',
					'Sec-Fetch-Mode' => '',
					'Sec-Fetch-Dest' => '',
				),
			)
		);
		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.01' );
	}

	public function test_bot_document_navigation_serves_html_402_with_excerpt(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_posts'][7] = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_excerpt' => 'Teaser for bots and browsers.',
		);
		$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$this->controller()->handle(
			array(
				'path'    => '/p/7',
				'method'  => 'GET',
				'post_id' => 7,
				'headers' => array(
					'User-Agent'     => $googlebot,
					'Accept'         => 'text/html',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);
		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$ct = (string) ( $GLOBALS['__x402press_response']['headers']['Content-Type'] ?? '' );
		$this->assertStringContainsString( 'text/html', $ct );
		$html = (string) $GLOBALS['__x402press_response']['body'];
		$this->assertStringContainsString( 'Teaser for bots and browsers.', $html );
		$this->assertStringContainsString( '0.01', $html );
		$this->assertArrayHasKey( 'PAYMENT-REQUIRED', $GLOBALS['__x402press_response']['headers'] );
	}

	public function test_everyone_audience_human_document_navigation_serves_html_402(): void {
		$GLOBALS['__x402press_options']['x402press_settings']['paywall_audience'] = SettingsRepository::AUDIENCE_EVERYONE;
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_posts'][42] = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_excerpt' => 'Everyone mode excerpt.',
		);
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		$this->controller()->handle(
			array(
				'path'    => '/story',
				'method'  => 'GET',
				'post_id' => 42,
				'headers' => array(
					'User-Agent'     => $human_ua,
					'Accept'         => 'text/html',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);
		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assertStringContainsString( 'text/html', (string) ( $GLOBALS['__x402press_response']['headers']['Content-Type'] ?? '' ) );
		$this->assertStringContainsString( 'Everyone mode excerpt.', (string) $GLOBALS['__x402press_response']['body'] );
	}

	public function test_html_402_renders_site_identity_and_post_title(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_bloginfo']['name'] = 'Example Site';
		$GLOBALS['__x402press_site_icon_url']    = 'https://example.test/icon-96.png';
		$GLOBALS['__x402press_posts'][55]        = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'The Headline of the Story',
			'post_excerpt' => 'A teaser sentence to whet the appetite.',
		);
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

		$this->controller()->handle(
			array(
				'path'    => '/p/55',
				'method'  => 'GET',
				'post_id' => 55,
				'headers' => array(
					'User-Agent'     => $human_ua,
					'Accept'         => 'text/html',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);

		$html = (string) $GLOBALS['__x402press_response']['body'];
		// Site identity row at the top: clickable wrapper with the site
		// name and the configured Site Icon as the favicon-style avatar.
		$this->assertStringContainsString( 'class="x402press-site"', $html );
		$this->assertStringContainsString( 'Example Site', $html );
		$this->assertStringContainsString( 'src="https://example.test/icon-96.png"', $html );
		// Post title rendered as the headline (replaces the generic
		// "Payment required" h1 from the old layout).
		$this->assertStringContainsString( '<h2 class="x402press-title">The Headline of the Story</h2>', $html );
		// Excerpt block remains.
		$this->assertStringContainsString( 'A teaser sentence to whet the appetite.', $html );
		// Price now rendered as a labelled card, not a bare line.
		$this->assertStringContainsString( 'class="x402press-price-card"', $html );
		$this->assertStringContainsString( '0.01 USDC', $html );
	}

	public function test_html_402_price_card_label_uses_rule_ttl(): void {
		// Two-hour TTL → "Access for 2 hours" (the previous "One-time access"
		// label was misleading: payment grants TTL-bound transient access,
		// not single-shot access).
		add_filter(
			'x402press_rule_for_request',
			static fn () => array( 'price' => '0.01', 'ttl' => 7200 ),
			10,
			2
		);
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

		$this->controller()->handle(
			array(
				'path'    => '/post',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'     => $human_ua,
					'Accept'         => 'text/html',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);

		$html = (string) $GLOBALS['__x402press_response']['body'];
		$this->assertStringContainsString( 'Access for 2 hours', $html );
		$this->assertStringNotContainsString( 'One-time access', $html );
	}

	public function test_initial_402_does_not_render_payment_required_as_an_error(): void {
		// Bare first visit (no PAYMENT-SIGNATURE) is the expected state, not
		// a failure — the eyebrow + price card already say so. Rendering
		// "payment_required" as an error block was misleading dev noise.
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

		$this->controller()->handle(
			array(
				'path'    => '/post',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'     => $human_ua,
					'Accept'         => 'text/html',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);

		$html = (string) $GLOBALS['__x402press_response']['body'];
		$this->assertStringNotContainsString( 'class="x402press-error"', $html );
		$this->assertStringNotContainsString( 'payment_required', $html );
	}

	public function test_invalid_signature_header_renders_friendly_error_with_dev_data_attr(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

		$this->controller()->handle(
			array(
				'path'    => '/post',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'        => $human_ua,
					'Accept'            => 'text/html',
					'Sec-Fetch-Mode'    => 'navigate',
					'Sec-Fetch-Dest'    => 'document',
					'Payment-Signature' => 'this-is-not-base64-x402-data',
				),
			)
		);

		$html = (string) $GLOBALS['__x402press_response']['body'];
		// The user gets prose, not a stack trace.
		$this->assertStringContainsString( 'class="x402press-error"', $html );
		$this->assertStringContainsString( 'payment data sent by your wallet was invalid', $html );
		// The raw code is still on the element so devtools / extensions /
		// log scrapers can read it without us showing it to humans.
		$this->assertStringContainsString( 'data-x402press-error="invalid_signature_header"', $html );
	}

	public function test_html_402_omits_site_block_when_no_name_or_icon(): void {
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__x402press_bloginfo']['name'] = '';
		$GLOBALS['__x402press_site_icon_url']    = '';
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

		$this->controller()->handle(
			array(
				'path'    => '/anywhere',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'     => $human_ua,
					'Accept'         => 'text/html',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				),
			)
		);

		$html = (string) $GLOBALS['__x402press_response']['body'];
		// Empty site name + missing icon → no identity row at all, rather
		// than an empty placeholder.
		$this->assertStringNotContainsString( 'class="x402press-site"', $html );
	}

	public function test_everyone_audience_human_json_accept_serves_json_402(): void {
		$GLOBALS['__x402press_options']['x402press_settings']['paywall_audience'] = SettingsRepository::AUDIENCE_EVERYONE;
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$human_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		$this->controller()->handle(
			array(
				'path'    => '/api-ish',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(
					'User-Agent'     => $human_ua,
					'Accept'         => 'application/json',
					'Sec-Fetch-Mode' => '',
					'Sec-Fetch-Dest' => '',
				),
			)
		);
		$this->assertSame( 402, $GLOBALS['__x402press_response']['status'] );
		$this->assert_402_json_body_has_price_and_requirements( '0.01' );
	}

	/**
	 * Regression guard for the lazy-profile refactor: a request that matches
	 * no paywall rule must never touch the facilitator layer. Without this
	 * guard, every admin dashboard hit, AJAX poll, REST call, and cron tick
	 * would pay for profile resolution + service construction it never needs.
	 *
	 * We assert it via reflection on the private fields — they're null iff
	 * the lazy accessors were never invoked.
	 */
	public function test_no_rule_match_never_constructs_facilitator_services(): void {
		$controller = $this->controller();
		$controller->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertNull( $this->private_field( $controller, 'builder' ) );
		$this->assertNull( $this->private_field( $controller, 'facilitator_svc' ) );
	}

	/**
	 * The full verify + settle path must populate every lazy field: profile,
	 * builder, facilitator_svc. If any one stays null, the controller reached
	 * deeper code paths without going through the memoized accessors — a
	 * regression we want to catch.
	 */
	public function test_full_verify_and_settle_populates_all_lazy_fields(): void {
		$payload = X402HeaderCodec::encode(
			array( 'payload' => array( 'authorization' => array( 'from' => '0xwallet' ) ) )
		);
		add_filter(
			'x402press_rule_for_request',
			static fn() => array( 'price' => '0.01', 'ttl' => 60, 'description' => 'Test' )
		);
		$GLOBALS['__x402press_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"isValid":true}' ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"transaction":"0xtx"}' ),
		);

		$controller = $this->controller();
		$controller->handle(
			array(
				'path'    => '/premium',
				'method'  => 'GET',
				'post_id' => 1,
				'headers' => array( 'Payment-Signature' => $payload ),
			)
		);

		$this->assertNotNull( $this->private_field( $controller, 'builder' ) );
		$this->assertNotNull( $this->private_field( $controller, 'facilitator_svc' ) );
	}

	private function private_field( object $obj, string $name ): mixed {
		$prop = new \ReflectionProperty( $obj::class, $name );
		$prop->setAccessible( true );
		return $prop->getValue( $obj );
	}

	public function test_paywall_is_inert_when_no_facilitator_is_selected(): void {
		// Clear the default test-setup selection. No facilitator = paywall
		// passes requests through untouched even when a rule matches.
		$GLOBALS['__x402press_options']['x402press_settings']['selected_facilitator_id'] = '';
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}

	public function test_paywall_is_inert_when_selected_connector_is_unknown(): void {
		// Stale selection pointing at a connector that isn't registered any more.
		$GLOBALS['__x402press_options']['x402press_settings']['selected_facilitator_id'] = 'nonexistent';
		add_filter( 'x402press_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 200, $GLOBALS['__x402press_response']['status'] );
		$this->assertFalse( $GLOBALS['__x402press_response']['exited'] );
	}
}
